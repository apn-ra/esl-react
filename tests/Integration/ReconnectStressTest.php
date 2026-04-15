<?php declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Integration;

use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Config\HeartbeatConfig;
use Apntalk\EslReact\Config\RetryPolicy;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Config\SubscriptionConfig;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Contracts\AsyncEslClientInterface;
use Apntalk\EslReact\Session\SessionState;
use Apntalk\EslReact\Tests\FakeServer\ScriptedFakeEslServer;
use Apntalk\EslReact\Tests\Support\AsyncTestCase;

final class ReconnectStressTest extends AsyncTestCase
{
    public function testRepeatedDisconnectReconnectCyclesRestoreSeededDesiredState(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        for ($i = 0; $i < 4; $i++) {
            $this->queueAuthSubscriptionAndFilterRestore($server, 'uuid-reconnect-seed');
        }

        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                retryPolicy: RetryPolicy::withMaxAttempts(5, 0.005),
                heartbeat: HeartbeatConfig::disabled(),
                subscriptions: SubscriptionConfig::forEvents('CHANNEL_CREATE')
                    ->withFilter('Unique-ID', 'uuid-reconnect-seed'),
            ),
            $this->loop,
        );

        $this->await($client->connect());

        for ($expectedConnections = 2; $expectedConnections <= 4; $expectedConnections++) {
            $server->closeActiveConnection();
            $this->waitUntil(
                fn (): bool => $server->connectionCount() === $expectedConnections
                    && $client->health()->snapshot()->connectionState === ConnectionState::Authenticated,
                0.6,
            );
            self::assertSame(SessionState::Active, $client->health()->snapshot()->sessionState);
        }

        self::assertSame(
            [
                ['auth ClueCon', 'event plain CHANNEL_CREATE', 'filter Unique-ID uuid-reconnect-seed'],
                ['auth ClueCon', 'event plain CHANNEL_CREATE', 'filter Unique-ID uuid-reconnect-seed'],
                ['auth ClueCon', 'event plain CHANNEL_CREATE', 'filter Unique-ID uuid-reconnect-seed'],
                ['auth ClueCon', 'event plain CHANNEL_CREATE', 'filter Unique-ID uuid-reconnect-seed'],
            ],
            $server->receivedCommandsByConnection(),
        );

        $server->close();
    }

    public function testTransportCollapseDuringReconnectRestoreDoesNotReportFalseHealthyState(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $client = $this->authenticatedClient($server);

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain CHANNEL_CREATE', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('filter Unique-ID uuid-restore', $command);
            $server->writeCommandReply($connection, '+OK filter added');
        });

        $this->await($client->subscriptions()->subscribe('CHANNEL_CREATE'));
        $this->await($client->subscriptions()->addFilter('Unique-ID', 'uuid-restore'));

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command): void {
            self::assertSame('event plain CHANNEL_CREATE', $command);
            $connection->close();
        });
        $this->queueAuthSubscriptionAndFilterRestore($server, 'uuid-restore');

        $server->closeActiveConnection();

        $this->waitUntil(
            fn (): bool => $server->connectionCount() >= 2
                && $client->health()->snapshot()->connectionState === ConnectionState::Reconnecting,
            0.4,
        );

        $recovering = $client->health()->snapshot();
        self::assertFalse($recovering->isLive);
        self::assertSame(SessionState::Disconnected, $recovering->sessionState);

        $this->waitUntil(
            fn (): bool => $server->connectionCount() === 3
                && $client->health()->snapshot()->connectionState === ConnectionState::Authenticated,
            0.8,
        );

        self::assertSame(
            [
                ['auth ClueCon', 'event plain CHANNEL_CREATE', 'filter Unique-ID uuid-restore'],
                ['auth ClueCon', 'event plain CHANNEL_CREATE'],
                ['auth ClueCon', 'event plain CHANNEL_CREATE', 'filter Unique-ID uuid-restore'],
            ],
            $server->receivedCommandsByConnection(),
        );
        self::assertTrue($client->health()->snapshot()->isLive);

        $server->close();
    }

    private function authenticatedClient(ScriptedFakeEslServer $server): AsyncEslClientInterface
    {
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });

        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                retryPolicy: RetryPolicy::withMaxAttempts(4, 0.005),
                heartbeat: HeartbeatConfig::disabled(),
            ),
            $this->loop,
        );

        $this->await($client->connect());

        return $client;
    }

    private function queueAuthSubscriptionAndFilterRestore(
        ScriptedFakeEslServer $server,
        string $filterValue,
    ): void {
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain CHANNEL_CREATE', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server, $filterValue): void {
            self::assertSame('filter Unique-ID ' . $filterValue, $command);
            $server->writeCommandReply($connection, '+OK filter added');
        });
    }
}
