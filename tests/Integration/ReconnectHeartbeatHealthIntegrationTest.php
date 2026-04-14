<?php declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Integration;

use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Config\CommandTimeoutConfig;
use Apntalk\EslReact\Config\HeartbeatConfig;
use Apntalk\EslReact\Config\RetryPolicy;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Exceptions\ConnectionException;
use Apntalk\EslReact\Session\SessionState;
use Apntalk\EslReact\Tests\FakeServer\ScriptedFakeEslServer;
use Apntalk\EslReact\Tests\Support\AsyncTestCase;

final class ReconnectHeartbeatHealthIntegrationTest extends AsyncTestCase
{
    public function testUnexpectedDisconnectReconnectsAndRestoresDesiredSessionState(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                retryPolicy: RetryPolicy::withMaxAttempts(2, 0.01),
                heartbeat: HeartbeatConfig::disabled(),
            ),
            $this->loop,
        );

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });

        $this->await($client->connect());

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain CHANNEL_CREATE', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('filter Unique-ID uuid-1', $command);
            $server->writeCommandReply($connection, '+OK filter added');
        });

        $this->await($client->subscriptions()->subscribe('CHANNEL_CREATE'));
        $this->await($client->subscriptions()->addFilter('Unique-ID', 'uuid-1'));

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain CHANNEL_CREATE', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('filter Unique-ID uuid-1', $command);
            $server->writeCommandReply($connection, '+OK filter added');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $server->writeApiResponse($connection, "+OK recovered\n");
        });

        $server->closeActiveConnection();

        $this->waitUntil(
            fn (): bool => $client->health()->snapshot()->connectionState === ConnectionState::Reconnecting,
            0.2,
        );

        $recovering = $client->health()->snapshot();
        self::assertSame(ConnectionState::Reconnecting, $recovering->connectionState);
        self::assertSame(SessionState::Disconnected, $recovering->sessionState);
        self::assertFalse($recovering->isLive);
        try {
            $this->await($client->api('status'));
            self::fail('Expected api() to fail while reconnecting');
        } catch (ConnectionException $e) {
            self::assertSame('Runtime is not authenticated', $e->getMessage());
        }

        try {
            $this->await($client->subscriptions()->subscribe('CHANNEL_ANSWER'));
            self::fail('Expected subscribe() to fail while reconnecting');
        } catch (ConnectionException $e) {
            self::assertSame('Runtime is not authenticated', $e->getMessage());
        }

        $this->waitUntil(
            fn (): bool => $server->connectionCount() === 2
                && $client->health()->snapshot()->connectionState === ConnectionState::Authenticated,
            0.5,
        );

        $reply = $this->await($client->api('status'));
        self::assertSame("+OK recovered\n", $reply->body());
        self::assertSame(
            [
                ['auth ClueCon', 'event plain CHANNEL_CREATE', 'filter Unique-ID uuid-1'],
                ['auth ClueCon', 'event plain CHANNEL_CREATE', 'filter Unique-ID uuid-1', 'api status'],
            ],
            $server->receivedCommandsByConnection(),
        );

        $live = $client->health()->snapshot();
        self::assertSame(ConnectionState::Authenticated, $live->connectionState);
        self::assertSame(SessionState::Active, $live->sessionState);
        self::assertTrue($live->isLive);
        self::assertSame(0, $live->reconnectAttempts);

        $server->close();
    }

    public function testExplicitDisconnectDoesNotTriggerReconnect(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                retryPolicy: RetryPolicy::withMaxAttempts(2, 0.01),
                heartbeat: HeartbeatConfig::disabled(),
            ),
            $this->loop,
        );

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('exit', $command);
            $server->writeCommandReply($connection, '+OK bye');
        });

        $this->await($client->connect());
        $this->await($client->disconnect());
        $this->runLoopFor(0.05);

        $snapshot = $client->health()->snapshot();
        self::assertSame(1, $server->connectionCount());
        self::assertSame(ConnectionState::Closed, $snapshot->connectionState);
        self::assertSame(SessionState::Disconnected, $snapshot->sessionState);
        self::assertFalse($snapshot->isLive);

        $server->close();
    }

    public function testReconnectRetriesAreBoundedAfterUnexpectedDisconnect(): void
    {
        $seenConnections = 0;
        $server = new ScriptedFakeEslServer(
            $this->loop,
            true,
            function ($connection) use (&$seenConnections): void {
                $seenConnections++;

                if ($seenConnections <= 1) {
                    return;
                }

                $this->loop->addTimer(0.002, function () use ($connection): void {
                    $connection->close();
                });
            },
        );

        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                retryPolicy: RetryPolicy::withMaxAttempts(2, 0.01),
                heartbeat: HeartbeatConfig::disabled(),
            ),
            $this->loop,
        );

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });

        $this->await($client->connect());

        $server->closeActiveConnection();

        $this->waitUntil(
            fn (): bool => $server->connectionCount() === 3
                && $client->health()->snapshot()->connectionState === ConnectionState::Disconnected,
            0.5,
        );

        $snapshot = $client->health()->snapshot();
        self::assertSame(ConnectionState::Disconnected, $snapshot->connectionState);
        self::assertSame(SessionState::Disconnected, $snapshot->sessionState);
        self::assertFalse($snapshot->isLive);
        self::assertSame(2, $snapshot->reconnectAttempts);

        $server->close();
    }

    public function testHeartbeatFailureDegradesHealthAndClosesTheConnection(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                retryPolicy: RetryPolicy::disabled(),
                heartbeat: HeartbeatConfig::withInterval(0.05, 0.01),
                commandTimeout: CommandTimeoutConfig::withApiTimeout(0.2),
            ),
            $this->loop,
        );

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command): void {
            self::assertSame('api status', $command);
        });

        $this->await($client->connect());
        $this->runLoopFor(0.06);

        $degraded = $client->health()->snapshot();
        self::assertSame(ConnectionState::Authenticated, $degraded->connectionState);
        self::assertSame(SessionState::Active, $degraded->sessionState);
        self::assertFalse($degraded->isLive);

        $this->waitUntil(
            fn (): bool => $client->health()->snapshot()->connectionState === ConnectionState::Disconnected,
            0.2,
        );

        $disconnected = $client->health()->snapshot();
        self::assertSame(ConnectionState::Disconnected, $disconnected->connectionState);
        self::assertSame(SessionState::Disconnected, $disconnected->sessionState);
        self::assertFalse($disconnected->isLive);
        self::assertSame(['auth ClueCon', 'api status'], $server->receivedCommands());

        $server->close();
    }
}
