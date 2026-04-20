<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Integration;

use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Config\HeartbeatConfig;
use Apntalk\EslReact\Config\RetryPolicy;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Config\SubscriptionConfig;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Session\SessionState;
use Apntalk\EslReact\Tests\FakeServer\ScriptedFakeEslServer;
use Apntalk\EslReact\Tests\Support\AsyncTestCase;
use React\Promise\Deferred;

final class RuntimeSoakTest extends AsyncTestCase
{
    public function testShortSessionSurvivesRepeatedActivityOneReconnectAndCleanShutdown(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $this->queueAuthAndSubscriptionRestore($server);

        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                retryPolicy: RetryPolicy::withMaxAttempts(3, 0.005),
                heartbeat: HeartbeatConfig::disabled(),
                subscriptions: SubscriptionConfig::forEvents('CHANNEL_CREATE'),
            ),
            $this->loop,
        );

        $this->await($client->connect());

        $seen = [];
        $events = new Deferred();
        $client->events()->onEvent('CHANNEL_CREATE', function ($event) use (&$seen, $events): void {
            $seen[] = $event->uniqueId();
            if (count($seen) === 6) {
                $events->resolve($seen);
            }
        });

        for ($i = 1; $i <= 3; $i++) {
            $this->roundTrip($server, $client, $i);
        }

        $this->queueAuthAndSubscriptionRestore($server);
        $server->closeActiveConnection();
        $this->waitUntil(
            fn(): bool => $server->connectionCount() === 2
                && $client->health()->snapshot()->connectionState === ConnectionState::Authenticated,
            0.6,
        );

        for ($i = 4; $i <= 6; $i++) {
            $this->roundTrip($server, $client, $i);
        }

        self::assertSame(
            ['uuid-soak-1', 'uuid-soak-2', 'uuid-soak-3', 'uuid-soak-4', 'uuid-soak-5', 'uuid-soak-6'],
            $this->await($events->promise(), 0.5),
        );

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('exit', $command);
            $server->writeCommandReply($connection, '+OK bye');
        });

        $this->await($client->disconnect(), 0.3);

        $snapshot = $client->health()->snapshot();
        self::assertSame(ConnectionState::Closed, $snapshot->connectionState);
        self::assertSame(SessionState::Disconnected, $snapshot->sessionState);
        self::assertFalse($snapshot->isLive);

        $server->close();
    }

    private function roundTrip(ScriptedFakeEslServer $server, $client, int $index): void
    {
        $server->queueCommandHandler(function ($connection, string $command) use ($server, $index): void {
            self::assertSame('api status', $command);
            $server->writeApiResponse($connection, "+OK soak-{$index}\n");
        });

        $server->emitPlainEvent([
            'Event-Name' => 'CHANNEL_CREATE',
            'Event-Sequence' => (string) $index,
            'Unique-ID' => 'uuid-soak-' . $index,
        ]);

        self::assertSame("+OK soak-{$index}\n", $this->await($client->api('status'), 0.2)->body());
        self::assertSame(ConnectionState::Authenticated, $client->health()->snapshot()->connectionState);
    }

    private function queueAuthAndSubscriptionRestore(ScriptedFakeEslServer $server): void
    {
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain CHANNEL_CREATE', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });
    }
}
