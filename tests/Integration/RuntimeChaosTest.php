<?php declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Integration;

use Apntalk\EslCore\Events\ChannelLifecycleEvent;
use Apntalk\EslCore\Exceptions\MalformedFrameException;
use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Config\HeartbeatConfig;
use Apntalk\EslReact\Config\RetryPolicy;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Exceptions\ConnectionException;
use Apntalk\EslReact\Exceptions\ConnectionLostException;
use Apntalk\EslReact\Session\SessionState;
use Apntalk\EslReact\Tests\FakeServer\ScriptedFakeEslServer;
use Apntalk\EslReact\Tests\Support\AsyncTestCase;
use Apntalk\EslReact\Tests\Support\CollectingReplaySink;
use React\Promise\Deferred;

final class RuntimeChaosTest extends AsyncTestCase
{
    public function testInflightApiInterruptedByUnexpectedDisconnectFailsClosedWithoutHanging(): void
    {
        $server = $this->authenticatedServer();
        $server->queueCommandHandler(static function ($connection, string $command): void {
            self::assertSame('api status', $command);
        });

        $client = $this->authenticatedClient(
            $server,
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                retryPolicy: RetryPolicy::disabled(),
                heartbeat: HeartbeatConfig::disabled(),
            ),
        );

        $api = $client->api('status');
        $api->then(null, static function (): void {
        });

        $server->closeActiveConnection();

        try {
            $this->await($api, 0.2);
            self::fail('Expected in-flight api() to reject after unexpected disconnect');
        } catch (ConnectionLostException) {
        }

        $this->waitUntil(
            fn (): bool => $client->health()->snapshot()->connectionState === ConnectionState::Disconnected,
            0.2,
        );

        $snapshot = $client->health()->snapshot();
        self::assertSame(ConnectionState::Disconnected, $snapshot->connectionState);
        self::assertSame(SessionState::Disconnected, $snapshot->sessionState);
        self::assertFalse($snapshot->isLive);

        $server->close();
    }

    public function testMalformedInboundTrafficAfterInitialSuccessClosesTheSessionHonesty(): void
    {
        $server = $this->authenticatedServer();
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $server->writeApiResponse($connection, "+OK warmup\n");
        });

        $client = $this->authenticatedClient(
            $server,
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                retryPolicy: RetryPolicy::disabled(),
                heartbeat: HeartbeatConfig::disabled(),
            ),
        );

        $reply = $this->await($client->api('status'));
        self::assertSame("+OK warmup\n", $reply->body());

        $server->writeRawFrame($server->activeConnection(), "Content-Type auth/request\n\n");

        $this->waitUntil(
            fn (): bool => $client->health()->snapshot()->connectionState === ConnectionState::Disconnected,
            0.2,
        );

        $snapshot = $client->health()->snapshot();
        self::assertSame(ConnectionState::Disconnected, $snapshot->connectionState);
        self::assertSame(SessionState::Disconnected, $snapshot->sessionState);
        self::assertFalse($snapshot->isLive);
        self::assertSame(MalformedFrameException::class, $snapshot->lastErrorClass);

        try {
            $this->await($client->api('status'), 0.1);
            self::fail('Expected api() to fail once malformed traffic has broken the session');
        } catch (ConnectionException $e) {
            self::assertSame('Runtime is not authenticated', $e->getMessage());
        }

        $server->close();
    }

    public function testReplaySinkFailureStaysObservationalAcrossReconnectAndLaterEventTraffic(): void
    {
        $failingSink = new CollectingReplaySink(true);
        $collectingSink = new CollectingReplaySink();
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient(
            $server,
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                retryPolicy: RetryPolicy::withMaxAttempts(2, 0.01),
                heartbeat: HeartbeatConfig::disabled(),
                replayCaptureEnabled: true,
                replayCaptureSinks: [$failingSink, $collectingSink],
            ),
        );

        $eventDeferred = new Deferred();
        $client->events()->onEvent('CHANNEL_CREATE', function ($event) use ($eventDeferred): void {
            $eventDeferred->resolve($event);
        });

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });

        $server->closeActiveConnection();

        $this->waitUntil(
            fn (): bool => $client->health()->snapshot()->connectionState === ConnectionState::Authenticated
                && $server->connectionCount() === 2,
            0.4,
        );

        $collectingSink->reset();
        $server->emitPlainEvent([
            'Event-Name' => 'CHANNEL_CREATE',
            'Event-Sequence' => '500',
            'Unique-ID' => 'uuid-chaos',
            'Channel-Name' => 'sofia/internal/5000',
        ]);

        $event = $this->await($eventDeferred->promise(), 0.2);

        self::assertInstanceOf(ChannelLifecycleEvent::class, $event);
        self::assertSame('uuid-chaos', $event->uniqueId());
        self::assertCount(1, $collectingSink->captured());
        self::assertSame('event.raw', $collectingSink->captured()[0]->derivedMetadata()['replay-artifact-name']);
        self::assertSame('2', $collectingSink->captured()[0]->derivedMetadata()['runtime-connection-generation']);

        $snapshot = $client->health()->snapshot();
        self::assertSame(ConnectionState::Authenticated, $snapshot->connectionState);
        self::assertSame(SessionState::Active, $snapshot->sessionState);
        self::assertTrue($snapshot->isLive);

        $server->close();
    }

    private function authenticatedServer(): ScriptedFakeEslServer
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });

        return $server;
    }

    private function authenticatedClient(ScriptedFakeEslServer $server, ?RuntimeConfig $config = null)
    {
        $client = AsyncEslRuntime::make(
            $config ?? RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
            ),
            $this->loop,
        );

        $this->await($client->connect());

        return $client;
    }
}
