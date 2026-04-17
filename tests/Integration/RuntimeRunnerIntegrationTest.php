<?php declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Integration;

use Apntalk\EslCore\Inbound\InboundPipeline;
use Apntalk\EslCore\Replies\ApiReply;
use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Config\HeartbeatConfig;
use Apntalk\EslReact\Config\RetryPolicy;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Exceptions\AuthenticationException;
use Apntalk\EslReact\Runner\PreparedRuntimeBootstrapInput;
use Apntalk\EslReact\Runner\PreparedRuntimeInput;
use Apntalk\EslReact\Runner\RuntimeLifecycleSnapshot;
use Apntalk\EslReact\Runner\RuntimeSessionContext;
use Apntalk\EslReact\Runner\RuntimeRunnerState;
use Apntalk\EslReact\Session\SessionState;
use Apntalk\EslReact\Tests\FakeServer\ScriptedFakeEslServer;
use Apntalk\EslReact\Tests\Support\AsyncTestCase;
use React\EventLoop\LoopInterface;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;

final class RuntimeRunnerIntegrationTest extends AsyncTestCase
{
    /**
     * @return array{
     *   runner: string,
     *   connection: ?string,
     *   session: ?string,
     *   live: bool,
     *   reconnecting: bool,
     *   draining: bool,
     *   stopped: bool,
     *   failed: bool,
     *   reconnectAttempts: int
     * }
     */
    private function lifecycleMarker(RuntimeLifecycleSnapshot $snapshot): array
    {
        return [
            'runner' => $snapshot->runnerState->value,
            'connection' => $snapshot->connectionState()?->value,
            'session' => $snapshot->sessionState()?->value,
            'live' => $snapshot->isLive(),
            'reconnecting' => $snapshot->isReconnecting(),
            'draining' => $snapshot->isDraining(),
            'stopped' => $snapshot->isStopped(),
            'failed' => $snapshot->isFailed(),
            'reconnectAttempts' => $snapshot->reconnectAttempts(),
        ];
    }

    public function testRunnerConsumesPreparedInputStartsRuntimeAndTransitionsToRunning(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $this->loop->addTimer(0.01, function () use ($connection, $server): void {
                $server->writeCommandReply($connection, '+OK accepted');
            });
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $server->writeApiResponse($connection, "+OK runner-live\n");
        });

        $input = new PreparedRuntimeInput(
            endpoint: sprintf('tcp://127.0.0.1:%d', $server->port()),
            runtimeConfig: RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
            ),
        );

        $handle = AsyncEslRuntime::runner()->run($input, $this->loop);

        self::assertSame('tcp://127.0.0.1:' . $server->port(), $handle->endpoint());
        self::assertSame(RuntimeRunnerState::Starting, $handle->state());
        self::assertTrue($handle->lifecycleSnapshot()->isStarting());

        $this->await($handle->startupPromise());

        self::assertSame(RuntimeRunnerState::Running, $handle->state());
        self::assertNull($handle->startupError());

        $lifecycle = $handle->lifecycleSnapshot();
        self::assertInstanceOf(RuntimeLifecycleSnapshot::class, $lifecycle);
        self::assertSame(RuntimeRunnerState::Running, $lifecycle->runnerState);
        self::assertSame(ConnectionState::Authenticated, $lifecycle->connectionState());
        self::assertSame(SessionState::Active, $lifecycle->sessionState());
        self::assertTrue($lifecycle->isConnected());
        self::assertTrue($lifecycle->isAuthenticated());
        self::assertTrue($lifecycle->isLive());
        self::assertFalse($lifecycle->isStarting());
        self::assertFalse($lifecycle->isReconnecting());
        self::assertFalse($lifecycle->isDraining());
        self::assertFalse($lifecycle->isStopped());
        self::assertFalse($lifecycle->isFailed());
        self::assertNull($lifecycle->startupErrorClass);
        self::assertNull($lifecycle->startupErrorMessage);

        $reply = $this->await($handle->client()->api('status'));

        self::assertInstanceOf(ApiReply::class, $reply);
        self::assertSame("+OK runner-live\n", $reply->body());

        $snapshot = $handle->client()->health()->snapshot();
        self::assertSame(ConnectionState::Authenticated, $snapshot->connectionState);
        self::assertSame(SessionState::Active, $snapshot->sessionState);
        self::assertTrue($snapshot->isLive);

        $server->close();
    }

    public function testRunnerLifecycleChangeListenerEmitsCurrentReconnectDrainAndStopSnapshots(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $this->loop->addTimer(0.05, function () use ($connection, $server): void {
                $server->writeCommandReply($connection, '+OK accepted');
            });
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeInput(
                endpoint: sprintf('tcp://127.0.0.1:%d', $server->port()),
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                    retryPolicy: RetryPolicy::withMaxAttempts(2, 0.2),
                    heartbeat: HeartbeatConfig::disabled(),
                ),
            ),
            $this->loop,
        );

        $markers = [];
        $handle->onLifecycleChange(function (RuntimeLifecycleSnapshot $snapshot) use (&$markers): void {
            $markers[] = $this->lifecycleMarker($snapshot);
        });

        self::assertSame('starting', $markers[0]['runner']);
        self::assertContains($markers[0]['connection'], ['disconnected', 'connecting']);
        self::assertSame('not_started', $markers[0]['session']);

        $this->await($handle->startupPromise());

        $this->waitUntil(
            function () use (&$markers): bool {
                return array_filter(
                    $markers,
                    static fn (array $marker): bool => $marker['runner'] === 'running'
                        && $marker['connection'] === 'authenticated'
                        && $marker['session'] === 'active'
                        && $marker['live'] === true
                ) !== [];
            },
            0.2,
        );

        $server->closeActiveConnection();

        $this->waitUntil(
            function () use (&$markers): bool {
                return array_filter(
                    $markers,
                    static fn (array $marker): bool => $marker['connection'] === 'reconnecting'
                        && $marker['session'] === 'disconnected'
                        && $marker['reconnecting'] === true
                        && $marker['draining'] === false
                ) !== [];
            },
            0.7,
        );

        $this->waitUntil(
            function () use (&$markers): bool {
                return count(array_filter(
                    $markers,
                    static fn (array $marker): bool => $marker['runner'] === 'running'
                        && $marker['connection'] === 'authenticated'
                        && $marker['session'] === 'active'
                        && $marker['live'] === true
                )) >= 2;
            },
            1.0,
        );

        $this->await($handle->client()->disconnect());

        $this->waitUntil(
            function () use (&$markers): bool {
                return array_filter(
                    $markers,
                    static fn (array $marker): bool => $marker['connection'] === 'draining'
                        && $marker['draining'] === true
                        && $marker['reconnecting'] === false
                        && $marker['stopped'] === false
                ) !== [];
            },
            0.2,
        );

        $this->waitUntil(
            function () use (&$markers): bool {
                return array_filter(
                    $markers,
                    static fn (array $marker): bool => $marker['connection'] === 'closed'
                        && $marker['session'] === 'disconnected'
                        && $marker['draining'] === false
                        && $marker['stopped'] === true
                        && $marker['reconnecting'] === false
                ) !== [];
            },
            0.2,
        );

        $server->close();
    }

    public function testRunnerConsumesPreparedBootstrapInputAndUsesPreparedConnector(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $server->writeApiResponse($connection, "+OK prepared-bootstrap\n");
        });

        $connector = new class($this->loop, $server->address()) implements ConnectorInterface {
            /** @var list<string> */
            public array $requestedUris = [];

            public function __construct(
                private readonly LoopInterface $loop,
                private readonly string $targetUri,
            ) {}

            public function connect($uri)
            {
                $this->requestedUris[] = (string) $uri;

                return (new Connector([], $this->loop))->connect($this->targetUri);
            }
        };

        $pipeline = new InboundPipeline();
        $pipeline->push("Content-Type: auth/request\n");
        self::assertGreaterThan(0, $pipeline->bufferedByteCount());

        $context = new RuntimeSessionContext(
            sessionId: 'runner-session-1',
            metadata: ['pbx' => 'node-a', 'attempt' => 1],
        );
        $input = new PreparedRuntimeBootstrapInput(
            endpoint: 'tcp://prepared-bootstrap',
            runtimeConfig: RuntimeConfig::create(
                host: 'config-only.invalid',
                port: 65000,
                password: 'ClueCon',
            ),
            connector: $connector,
            inboundPipeline: $pipeline,
            sessionContext: $context,
            dialUri: 'tls://pbx.example.test:7443',
        );

        $handle = AsyncEslRuntime::runner()->run($input, $this->loop);

        self::assertSame(['tls://pbx.example.test:7443'], $connector->requestedUris);
        self::assertSame(0, $pipeline->bufferedByteCount());
        self::assertSame($context, $handle->sessionContext());

        $this->await($handle->startupPromise());

        self::assertSame(RuntimeRunnerState::Running, $handle->state());

        $reply = $this->await($handle->client()->api('status'));
        self::assertInstanceOf(ApiReply::class, $reply);
        self::assertSame("+OK prepared-bootstrap\n", $reply->body());

        $server->close();
    }

    public function testRunnerReusesPreparedDialUriForReconnectAttempts(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });

        $connector = new class($this->loop, $server->address()) implements ConnectorInterface {
            /** @var list<string> */
            public array $requestedUris = [];

            public function __construct(
                private readonly LoopInterface $loop,
                private readonly string $targetUri,
            ) {}

            public function connect($uri)
            {
                $this->requestedUris[] = (string) $uri;

                return (new Connector([], $this->loop))->connect($this->targetUri);
            }
        };

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeBootstrapInput(
                endpoint: 'worker://node-a/session-1',
                runtimeConfig: RuntimeConfig::create(
                    host: 'config-only.invalid',
                    port: 65000,
                    password: 'ClueCon',
                    retryPolicy: RetryPolicy::withMaxAttempts(2, 0.05),
                    heartbeat: HeartbeatConfig::disabled(),
                ),
                connector: $connector,
                inboundPipeline: new InboundPipeline(),
                sessionContext: new RuntimeSessionContext('runner-session-2'),
                dialUri: 'tls://pbx.example.test:7443',
            ),
            $this->loop,
        );

        $this->await($handle->startupPromise());

        $server->closeActiveConnection();

        $this->waitUntil(
            fn (): bool => count($connector->requestedUris) >= 2
                && $handle->lifecycleSnapshot()->connectionState() === ConnectionState::Authenticated,
            0.5,
        );

        self::assertSame(
            ['tls://pbx.example.test:7443', 'tls://pbx.example.test:7443'],
            array_slice($connector->requestedUris, 0, 2),
        );

        $this->await($handle->client()->disconnect());
        $server->close();
    }

    public function testRunnerMarksFailedWhenStartupFails(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth wrong-password', $command);
            $server->writeCommandReply($connection, '-ERR invalid password');
        });

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeInput(
                endpoint: sprintf('tcp://127.0.0.1:%d', $server->port()),
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'wrong-password',
                ),
            ),
            $this->loop,
        );

        $markers = [];
        $handle->onLifecycleChange(function (RuntimeLifecycleSnapshot $snapshot) use (&$markers): void {
            $markers[] = $this->lifecycleMarker($snapshot);
        });

        try {
            $this->await($handle->startupPromise());
            self::fail('Expected startup to fail');
        } catch (AuthenticationException $e) {
            self::assertSame('invalid password', $e->getMessage());
        }

        self::assertSame(RuntimeRunnerState::Failed, $handle->state());
        self::assertInstanceOf(AuthenticationException::class, $handle->startupError());

        $lifecycle = $handle->lifecycleSnapshot();
        self::assertSame(RuntimeRunnerState::Failed, $lifecycle->runnerState);
        self::assertTrue($lifecycle->isFailed());
        self::assertSame(AuthenticationException::class, $lifecycle->startupErrorClass);
        self::assertSame('invalid password', $lifecycle->startupErrorMessage);
        self::assertSame(ConnectionState::Disconnected, $lifecycle->connectionState());
        self::assertSame(SessionState::Failed, $lifecycle->sessionState());
        self::assertSame('starting', $markers[0]['runner']);
        self::assertSame('failed', $markers[array_key_last($markers)]['runner']);
        self::assertTrue($markers[array_key_last($markers)]['failed']);
        self::assertSame('failed', $markers[array_key_last($markers)]['session']);

        $server->close();
    }

    public function testRunnerLifecycleSnapshotObservesReconnectAndTerminalDisconnect(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeInput(
                endpoint: sprintf('tcp://127.0.0.1:%d', $server->port()),
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                    retryPolicy: RetryPolicy::withMaxAttempts(2, 0.05),
                    heartbeat: HeartbeatConfig::disabled(),
                ),
            ),
            $this->loop,
        );

        $this->await($handle->startupPromise());

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });

        $server->closeActiveConnection();

        $this->waitUntil(
            fn (): bool => $handle->lifecycleSnapshot()->isReconnecting(),
            0.2,
        );

        $reconnecting = $handle->lifecycleSnapshot();
        self::assertSame(RuntimeRunnerState::Running, $reconnecting->runnerState);
        self::assertSame(ConnectionState::Reconnecting, $reconnecting->connectionState());
        self::assertSame(SessionState::Disconnected, $reconnecting->sessionState());
        self::assertFalse($reconnecting->isConnected());
        self::assertFalse($reconnecting->isAuthenticated());
        self::assertFalse($reconnecting->isLive());
        self::assertTrue($reconnecting->isReconnecting());
        self::assertFalse($reconnecting->isDraining());

        $this->waitUntil(
            fn (): bool => $handle->lifecycleSnapshot()->connectionState() === ConnectionState::Authenticated,
            0.5,
        );

        $this->await($handle->client()->disconnect());

        $closed = $handle->lifecycleSnapshot();
        self::assertSame(RuntimeRunnerState::Running, $closed->runnerState);
        self::assertSame(ConnectionState::Closed, $closed->connectionState());
        self::assertSame(SessionState::Disconnected, $closed->sessionState());
        self::assertFalse($closed->isConnected());
        self::assertFalse($closed->isLive());
        self::assertFalse($closed->isDraining());
        self::assertTrue($closed->isStopped());

        $server->close();
    }
}
