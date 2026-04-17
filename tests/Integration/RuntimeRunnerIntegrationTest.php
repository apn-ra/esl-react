<?php declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Integration;

use Apntalk\EslCore\Inbound\InboundPipeline;
use Apntalk\EslCore\Replies\ApiReply;
use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Exceptions\AuthenticationException;
use Apntalk\EslReact\Runner\PreparedRuntimeBootstrapInput;
use Apntalk\EslReact\Runner\PreparedRuntimeInput;
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

        $this->await($handle->startupPromise());

        self::assertSame(RuntimeRunnerState::Running, $handle->state());
        self::assertNull($handle->startupError());

        $reply = $this->await($handle->client()->api('status'));

        self::assertInstanceOf(ApiReply::class, $reply);
        self::assertSame("+OK runner-live\n", $reply->body());

        $snapshot = $handle->client()->health()->snapshot();
        self::assertSame(ConnectionState::Authenticated, $snapshot->connectionState);
        self::assertSame(SessionState::Active, $snapshot->sessionState);
        self::assertTrue($snapshot->isLive);

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
        );

        $handle = AsyncEslRuntime::runner()->run($input, $this->loop);

        self::assertSame(['tcp://config-only.invalid:65000'], $connector->requestedUris);
        self::assertSame(0, $pipeline->bufferedByteCount());
        self::assertSame($context, $handle->sessionContext());

        $this->await($handle->startupPromise());

        self::assertSame(RuntimeRunnerState::Running, $handle->state());

        $reply = $this->await($handle->client()->api('status'));
        self::assertInstanceOf(ApiReply::class, $reply);
        self::assertSame("+OK prepared-bootstrap\n", $reply->body());

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

        try {
            $this->await($handle->startupPromise());
            self::fail('Expected startup to fail');
        } catch (AuthenticationException $e) {
            self::assertSame('invalid password', $e->getMessage());
        }

        self::assertSame(RuntimeRunnerState::Failed, $handle->state());
        self::assertInstanceOf(AuthenticationException::class, $handle->startupError());

        $server->close();
    }
}
