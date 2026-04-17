<?php declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Integration;

use Apntalk\EslCore\Inbound\InboundPipeline;
use Apntalk\EslCore\Correlation\EventEnvelope;
use Apntalk\EslCore\Events\BackgroundJobEvent;
use Apntalk\EslCore\Replies\ApiReply;
use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Bgapi\BgapiJobHandle;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Config\HeartbeatConfig;
use Apntalk\EslReact\Config\RetryPolicy;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Exceptions\AuthenticationException;
use Apntalk\EslReact\Exceptions\ConnectionException;
use Apntalk\EslReact\Runner\PreparedRuntimeBootstrapInput;
use Apntalk\EslReact\Runner\PreparedRuntimeInput;
use Apntalk\EslReact\Runner\RuntimeLifecycleSnapshot;
use Apntalk\EslReact\Runner\RuntimeSessionContext;
use Apntalk\EslReact\Runner\RuntimeRunnerState;
use Apntalk\EslReact\Session\SessionState;
use Apntalk\EslReact\Tests\FakeServer\ScriptedFakeEslServer;
use Apntalk\EslReact\Tests\Support\AsyncTestCase;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
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

    public function testRunnerLifecycleObservationRemainsTruthfulDuringEventAndBgapiActivity(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain CHANNEL_CREATE BACKGROUND_JOB', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('bgapi status', $command);
            $server->writeBgapiAcceptedReply($connection, 'runner-bgapi-job-1');
        });

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeInput(
                endpoint: sprintf('tcp://127.0.0.1:%d', $server->port()),
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                    heartbeat: HeartbeatConfig::disabled(),
                ),
            ),
            $this->loop,
        );

        $markers = [];
        $handle->onLifecycleChange(function (RuntimeLifecycleSnapshot $snapshot) use (&$markers): void {
            $markers[] = $this->lifecycleMarker($snapshot);
        });

        $this->await($handle->startupPromise());

        $eventDeferred = new Deferred();
        $handle->client()->events()->onRawEnvelope(
            function (EventEnvelope $envelope) use ($eventDeferred): void {
                if ($envelope->event()->eventName() === 'CHANNEL_CREATE') {
                    $eventDeferred->resolve($envelope);
                }
            },
        );

        $this->await($handle->client()->subscriptions()->subscribe('CHANNEL_CREATE', 'BACKGROUND_JOB'));

        $server->emitPlainEvent([
            'Event-Name' => 'CHANNEL_CREATE',
            'Event-Sequence' => '501',
            'Unique-ID' => 'runner-event-1',
            'Channel-Name' => 'sofia/internal/runner-event',
        ]);

        $envelope = $this->await($eventDeferred->promise(), 0.2);
        self::assertInstanceOf(EventEnvelope::class, $envelope);
        self::assertSame('CHANNEL_CREATE', $envelope->event()->eventName());
        self::assertSame('501', $envelope->metadata()->protocolSequence());

        $job = $handle->client()->bgapi('status');
        self::assertInstanceOf(BgapiJobHandle::class, $job);
        self::assertSame('status', $job->eslCommand());
        self::assertSame('', $job->eslArgs());

        $this->waitUntil(
            fn (): bool => $job->jobUuid() === 'runner-bgapi-job-1'
                && $handle->lifecycleSnapshot()->health?->pendingBgapiJobCount === 1,
            0.2,
        );

        $duringBgapi = $handle->lifecycleSnapshot();
        self::assertSame(RuntimeRunnerState::Running, $duringBgapi->runnerState);
        self::assertSame(ConnectionState::Authenticated, $duringBgapi->connectionState());
        self::assertSame(SessionState::Active, $duringBgapi->sessionState());
        self::assertTrue($duringBgapi->isLive());
        self::assertFalse($duringBgapi->isReconnecting());
        self::assertFalse($duringBgapi->isDraining());
        self::assertFalse($duringBgapi->isStopped());
        self::assertSame(['CHANNEL_CREATE', 'BACKGROUND_JOB'], $duringBgapi->health?->activeSubscriptions);

        $server->emitBackgroundJobEvent('runner-bgapi-job-1', "+OK runner bgapi complete\n", 'status');

        $completion = $this->await($job->promise(), 0.2);
        self::assertInstanceOf(BackgroundJobEvent::class, $completion);
        self::assertSame('runner-bgapi-job-1', $completion->jobUuid());
        self::assertSame("+OK runner bgapi complete\n", $completion->result());

        $afterActivity = $handle->lifecycleSnapshot();
        self::assertSame(ConnectionState::Authenticated, $afterActivity->connectionState());
        self::assertSame(SessionState::Active, $afterActivity->sessionState());
        self::assertTrue($afterActivity->isLive());
        self::assertFalse($afterActivity->isReconnecting());
        self::assertFalse($afterActivity->isDraining());
        self::assertFalse($afterActivity->isStopped());
        self::assertSame(0, $afterActivity->health?->pendingBgapiJobCount);

        self::assertSame([], array_filter(
            $markers,
            static fn (array $marker): bool => $marker['reconnecting'] === true
                || $marker['connection'] === 'reconnecting'
                || $marker['draining'] === true
                || $marker['connection'] === 'draining'
                || $marker['stopped'] === true
        ));

        $this->await($handle->client()->disconnect());
        $server->close();
    }

    public function testRunnerRetainsPendingBgapiAndRestoresEventFlowAcrossUnexpectedReconnect(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain CHANNEL_CREATE BACKGROUND_JOB', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('bgapi status', $command);
            $server->writeBgapiAcceptedReply($connection, 'runner-bgapi-reconnect-job');
        });

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeInput(
                endpoint: sprintf('tcp://127.0.0.1:%d', $server->port()),
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                    retryPolicy: RetryPolicy::withMaxAttempts(2, 0.01),
                    heartbeat: HeartbeatConfig::disabled(),
                ),
            ),
            $this->loop,
        );

        $markers = [];
        $handle->onLifecycleChange(function (RuntimeLifecycleSnapshot $snapshot) use (&$markers): void {
            $markers[] = $this->lifecycleMarker($snapshot);
        });

        $this->await($handle->startupPromise());
        $this->await($handle->client()->subscriptions()->subscribe('CHANNEL_CREATE', 'BACKGROUND_JOB'));

        $eventDeferred = new Deferred();
        $handle->client()->events()->onRawEnvelope(
            function (EventEnvelope $envelope) use ($eventDeferred): void {
                if ($envelope->event()->eventName() === 'CHANNEL_CREATE') {
                    $eventDeferred->resolve($envelope);
                }
            },
        );

        $job = $handle->client()->bgapi('status');
        $this->waitUntil(
            fn (): bool => $job->jobUuid() === 'runner-bgapi-reconnect-job'
                && $handle->lifecycleSnapshot()->health?->pendingBgapiJobCount === 1,
            0.2,
        );

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain CHANNEL_CREATE BACKGROUND_JOB', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });

        $server->closeActiveConnection();

        $this->waitUntil(
            function () use ($handle, &$markers): bool {
                $snapshot = $handle->lifecycleSnapshot();

                return $snapshot->connectionState() === ConnectionState::Reconnecting
                    && $snapshot->sessionState() === SessionState::Disconnected
                    && $snapshot->health?->pendingBgapiJobCount === 1
                    && array_filter(
                        $markers,
                        static fn (array $marker): bool => $marker['connection'] === 'reconnecting'
                            && $marker['session'] === 'disconnected'
                            && $marker['reconnecting'] === true
                            && $marker['draining'] === false
                    ) !== [];
            },
            0.2,
        );

        $reconnecting = $handle->lifecycleSnapshot();
        self::assertFalse($reconnecting->isLive());
        self::assertTrue($reconnecting->isReconnecting());
        self::assertFalse($reconnecting->isDraining());
        self::assertFalse($reconnecting->isStopped());
        self::assertSame(1, $reconnecting->health?->pendingBgapiJobCount);

        try {
            $handle->client()->bgapi('status');
            self::fail('Expected new bgapi work to fail closed while reconnecting');
        } catch (ConnectionException $e) {
            self::assertSame('Runtime is not authenticated', $e->getMessage());
        }

        try {
            $handle->client()->subscriptions()->subscribe('CHANNEL_ANSWER');
            self::fail('Expected subscription mutation to fail closed while reconnecting');
        } catch (ConnectionException $e) {
            self::assertSame('Runtime is not authenticated', $e->getMessage());
        }

        $this->waitUntil(
            fn (): bool => $handle->lifecycleSnapshot()->connectionState() === ConnectionState::Authenticated
                && $handle->lifecycleSnapshot()->sessionState() === SessionState::Active
                && $handle->lifecycleSnapshot()->isLive()
                && $handle->lifecycleSnapshot()->health?->pendingBgapiJobCount === 1
                && $handle->lifecycleSnapshot()->health?->activeSubscriptions === ['CHANNEL_CREATE', 'BACKGROUND_JOB'],
            0.5,
        );

        $server->emitPlainEvent([
            'Event-Name' => 'CHANNEL_CREATE',
            'Event-Sequence' => '601',
            'Unique-ID' => 'runner-reconnect-event-1',
        ]);
        $server->emitBackgroundJobEvent('runner-bgapi-reconnect-job', "+OK runner bgapi recovered\n", 'status');

        $event = $this->await($eventDeferred->promise(), 0.2);
        self::assertInstanceOf(EventEnvelope::class, $event);
        self::assertSame('CHANNEL_CREATE', $event->event()->eventName());
        self::assertSame('601', $event->metadata()->protocolSequence());

        $completion = $this->await($job->promise(), 0.2);
        self::assertInstanceOf(BackgroundJobEvent::class, $completion);
        self::assertSame('runner-bgapi-reconnect-job', $completion->jobUuid());
        self::assertSame("+OK runner bgapi recovered\n", $completion->result());

        $recovered = $handle->lifecycleSnapshot();
        self::assertSame(ConnectionState::Authenticated, $recovered->connectionState());
        self::assertSame(SessionState::Active, $recovered->sessionState());
        self::assertTrue($recovered->isLive());
        self::assertFalse($recovered->isReconnecting());
        self::assertFalse($recovered->isDraining());
        self::assertFalse($recovered->isStopped());
        self::assertSame(0, $recovered->health?->pendingBgapiJobCount);

        self::assertSame([], array_filter(
            $markers,
            static fn (array $marker): bool => $marker['draining'] === true
                || $marker['connection'] === 'draining'
        ));

        $this->await($handle->client()->disconnect());
        $server->close();
    }

    public function testRunnerKeepsPendingBgapiTruthWhileHeartbeatDegradesAndRecovers(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain BACKGROUND_JOB', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('bgapi status', $command);
            $server->writeBgapiAcceptedReply($connection, 'runner-bgapi-degraded-job');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $this->loop->addTimer(0.03, function () use ($connection, $server): void {
                $server->writeApiResponse($connection, "+OK liveness-recovered-with-bgapi\n");
            });
        });

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeInput(
                endpoint: sprintf('tcp://127.0.0.1:%d', $server->port()),
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                    retryPolicy: RetryPolicy::disabled(),
                    heartbeat: HeartbeatConfig::withInterval(0.05, 0.01),
                ),
            ),
            $this->loop,
        );

        $markers = [];
        $handle->onLifecycleChange(function (RuntimeLifecycleSnapshot $snapshot) use (&$markers): void {
            $markers[] = $this->lifecycleMarker($snapshot);
        });

        $this->await($handle->startupPromise());
        $this->await($handle->client()->subscriptions()->subscribe('BACKGROUND_JOB'));

        $job = $handle->client()->bgapi('status');
        $this->waitUntil(
            fn (): bool => $job->jobUuid() === 'runner-bgapi-degraded-job'
                && $handle->lifecycleSnapshot()->health?->pendingBgapiJobCount === 1,
            0.2,
        );

        $this->waitUntil(
            function () use ($handle, &$markers): bool {
                $snapshot = $handle->lifecycleSnapshot();

                return $snapshot->connectionState() === ConnectionState::Authenticated
                    && $snapshot->sessionState() === SessionState::Active
                    && $snapshot->isLive() === false
                    && $snapshot->health?->pendingBgapiJobCount === 1
                    && array_filter(
                        $markers,
                        static fn (array $marker): bool => $marker['connection'] === 'authenticated'
                            && $marker['session'] === 'active'
                            && $marker['live'] === false
                            && $marker['reconnecting'] === false
                            && $marker['draining'] === false
                    ) !== [];
            },
            0.2,
        );

        $degraded = $handle->lifecycleSnapshot();
        self::assertSame(RuntimeRunnerState::Running, $degraded->runnerState);
        self::assertSame(ConnectionState::Authenticated, $degraded->connectionState());
        self::assertSame(SessionState::Active, $degraded->sessionState());
        self::assertFalse($degraded->isLive());
        self::assertFalse($degraded->isReconnecting());
        self::assertFalse($degraded->isDraining());
        self::assertFalse($degraded->isStopped());
        self::assertSame(1, $degraded->health?->pendingBgapiJobCount);

        $this->waitUntil(
            fn (): bool => $handle->lifecycleSnapshot()->connectionState() === ConnectionState::Authenticated
                && $handle->lifecycleSnapshot()->sessionState() === SessionState::Active
                && $handle->lifecycleSnapshot()->isLive()
                && $handle->lifecycleSnapshot()->health?->pendingBgapiJobCount === 1,
            0.3,
        );

        $server->emitBackgroundJobEvent('runner-bgapi-degraded-job', "+OK bgapi completed after liveness recovery\n", 'status');

        $completion = $this->await($job->promise(), 0.2);
        self::assertInstanceOf(BackgroundJobEvent::class, $completion);
        self::assertSame('runner-bgapi-degraded-job', $completion->jobUuid());
        self::assertSame("+OK bgapi completed after liveness recovery\n", $completion->result());

        $recovered = $handle->lifecycleSnapshot();
        self::assertSame(ConnectionState::Authenticated, $recovered->connectionState());
        self::assertSame(SessionState::Active, $recovered->sessionState());
        self::assertTrue($recovered->isLive());
        self::assertFalse($recovered->isReconnecting());
        self::assertFalse($recovered->isDraining());
        self::assertFalse($recovered->isStopped());
        self::assertSame(0, $recovered->health?->pendingBgapiJobCount);

        self::assertSame([], array_filter(
            $markers,
            static fn (array $marker): bool => $marker['reconnecting'] === true
                || $marker['connection'] === 'reconnecting'
                || $marker['draining'] === true
                || $marker['connection'] === 'draining'
        ));

        $this->await($handle->client()->disconnect());
        $server->close();
    }

    public function testRunnerLifecycleObservationReportsHeartbeatDegradationAndRecoveryWithoutFalseReconnect(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $this->loop->addTimer(0.03, function () use ($connection, $server): void {
                $server->writeApiResponse($connection, "+OK liveness-recovered\n");
            });
        });

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeInput(
                endpoint: sprintf('tcp://127.0.0.1:%d', $server->port()),
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                    retryPolicy: RetryPolicy::disabled(),
                    heartbeat: HeartbeatConfig::withInterval(0.05, 0.01),
                ),
            ),
            $this->loop,
        );

        $markers = [];
        $handle->onLifecycleChange(function (RuntimeLifecycleSnapshot $snapshot) use (&$markers): void {
            $markers[] = $this->lifecycleMarker($snapshot);
        });

        $this->await($handle->startupPromise());

        $this->waitUntil(
            function () use ($handle, &$markers): bool {
                return $handle->lifecycleSnapshot()->connectionState() === ConnectionState::Authenticated
                    && $handle->lifecycleSnapshot()->sessionState() === SessionState::Active
                    && $handle->lifecycleSnapshot()->isLive() === false
                    && array_filter(
                        $markers,
                        static fn (array $marker): bool => $marker['connection'] === 'authenticated'
                            && $marker['session'] === 'active'
                            && $marker['live'] === false
                            && $marker['reconnecting'] === false
                            && $marker['draining'] === false
                            && $marker['stopped'] === false
                    ) !== [];
            },
            0.2,
        );

        $degraded = $handle->lifecycleSnapshot();
        self::assertSame(RuntimeRunnerState::Running, $degraded->runnerState);
        self::assertSame(ConnectionState::Authenticated, $degraded->connectionState());
        self::assertSame(SessionState::Active, $degraded->sessionState());
        self::assertFalse($degraded->isLive());
        self::assertFalse($degraded->isReconnecting());
        self::assertFalse($degraded->isDraining());
        self::assertFalse($degraded->isStopped());
        self::assertNotNull($degraded->lastHeartbeatAtMicros());

        $this->waitUntil(
            function () use ($handle, &$markers): bool {
                return $handle->lifecycleSnapshot()->connectionState() === ConnectionState::Authenticated
                    && $handle->lifecycleSnapshot()->sessionState() === SessionState::Active
                    && $handle->lifecycleSnapshot()->isLive()
                    && count(array_filter(
                        $markers,
                        static fn (array $marker): bool => $marker['connection'] === 'authenticated'
                            && $marker['session'] === 'active'
                            && $marker['live'] === true
                            && $marker['reconnecting'] === false
                            && $marker['draining'] === false
                            && $marker['stopped'] === false
                    )) >= 2;
            },
            0.3,
        );

        $recovered = $handle->lifecycleSnapshot();
        self::assertSame(RuntimeRunnerState::Running, $recovered->runnerState);
        self::assertSame(ConnectionState::Authenticated, $recovered->connectionState());
        self::assertSame(SessionState::Active, $recovered->sessionState());
        self::assertTrue($recovered->isLive());
        self::assertFalse($recovered->isReconnecting());
        self::assertFalse($recovered->isDraining());
        self::assertFalse($recovered->isStopped());

        self::assertSame([], array_filter(
            $markers,
            static fn (array $marker): bool => $marker['reconnecting'] === true
                || $marker['connection'] === 'reconnecting'
                || $marker['draining'] === true
                || $marker['connection'] === 'draining'
        ));

        $this->await($handle->client()->disconnect());
        $server->close();
    }

    public function testRunnerLifecycleObservationReportsHeartbeatDeadPathReconnectAndRecoveryWithoutFalseDrain(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command): void {
            self::assertSame('api status', $command);
            // Leave the first probe unanswered so the second miss forces a recoverable close.
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $server->writeApiResponse($connection, "+OK after-heartbeat-dead-recover\n");
        });

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeInput(
                endpoint: sprintf('tcp://127.0.0.1:%d', $server->port()),
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                    retryPolicy: RetryPolicy::withMaxAttempts(2, 0.01),
                    heartbeat: HeartbeatConfig::withInterval(0.05, 0.01),
                ),
            ),
            $this->loop,
        );

        $markers = [];
        $handle->onLifecycleChange(function (RuntimeLifecycleSnapshot $snapshot) use (&$markers): void {
            $markers[] = $this->lifecycleMarker($snapshot);
        });

        $this->await($handle->startupPromise());

        $this->waitUntil(
            function () use ($handle, &$markers): bool {
                return $handle->lifecycleSnapshot()->connectionState() === ConnectionState::Authenticated
                    && $handle->lifecycleSnapshot()->sessionState() === SessionState::Active
                    && $handle->lifecycleSnapshot()->isLive() === false
                    && array_filter(
                        $markers,
                        static fn (array $marker): bool => $marker['connection'] === 'authenticated'
                            && $marker['session'] === 'active'
                            && $marker['live'] === false
                            && $marker['reconnecting'] === false
                            && $marker['draining'] === false
                            && $marker['stopped'] === false
                    ) !== [];
            },
            0.2,
        );

        $this->waitUntil(
            function () use ($handle, &$markers): bool {
                return array_filter(
                    $markers,
                    static fn (array $marker): bool => $marker['connection'] === 'reconnecting'
                        && $marker['session'] === 'disconnected'
                        && $marker['live'] === false
                        && $marker['reconnecting'] === true
                        && $marker['draining'] === false
                        && $marker['stopped'] === false
                ) !== [];
            },
            0.2,
        );

        $recovering = $handle->lifecycleSnapshot();
        self::assertSame(RuntimeRunnerState::Running, $recovering->runnerState);
        self::assertSame(ConnectionState::Reconnecting, $recovering->connectionState());
        self::assertSame(SessionState::Disconnected, $recovering->sessionState());
        self::assertFalse($recovering->isLive());
        self::assertTrue($recovering->isReconnecting());
        self::assertFalse($recovering->isDraining());
        self::assertFalse($recovering->isStopped());

        $this->waitUntil(
            function () use ($handle, &$markers): bool {
                return $handle->lifecycleSnapshot()->connectionState() === ConnectionState::Authenticated
                    && $handle->lifecycleSnapshot()->sessionState() === SessionState::Active
                    && $handle->lifecycleSnapshot()->isLive()
                    && count(array_filter(
                        $markers,
                        static fn (array $marker): bool => $marker['connection'] === 'authenticated'
                            && $marker['session'] === 'active'
                            && $marker['live'] === true
                            && $marker['reconnecting'] === false
                            && $marker['draining'] === false
                            && $marker['stopped'] === false
                    )) >= 2;
            },
            0.4,
        );

        $recovered = $handle->lifecycleSnapshot();
        self::assertSame(RuntimeRunnerState::Running, $recovered->runnerState);
        self::assertSame(ConnectionState::Authenticated, $recovered->connectionState());
        self::assertSame(SessionState::Active, $recovered->sessionState());
        self::assertTrue($recovered->isLive());
        self::assertFalse($recovered->isReconnecting());
        self::assertFalse($recovered->isDraining());
        self::assertFalse($recovered->isStopped());

        self::assertSame([], array_filter(
            $markers,
            static fn (array $marker): bool => $marker['draining'] === true
                || $marker['connection'] === 'draining'
        ));

        $this->await($handle->client()->disconnect());
        $server->close();
    }
}
