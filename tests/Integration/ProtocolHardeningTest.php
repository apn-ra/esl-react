<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Integration;

use Apntalk\EslCore\Events\ChannelLifecycleEvent;
use Apntalk\EslCore\Exceptions\MalformedFrameException;
use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Config\HeartbeatConfig;
use Apntalk\EslReact\Config\RetryPolicy;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Contracts\AsyncEslClientInterface;
use Apntalk\EslReact\Session\SessionState;
use Apntalk\EslReact\Tests\FakeServer\ScriptedFakeEslServer;
use Apntalk\EslReact\Tests\Support\AsyncTestCase;
use React\Promise\Deferred;

final class ProtocolHardeningTest extends AsyncTestCase
{
    public function testMixedReplyAndEventFramesDeliveredInOneBurstAreRoutedCorrectly(): void
    {
        $server = $this->authenticatedServer();
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $server->writeRawFrame(
                $connection,
                $this->frame('api/response', "+OK burst-reply\n")
                    . $this->frame('text/event-plain', implode("\n", [
                        'Event-Name: CHANNEL_CREATE',
                        'Event-Sequence: 900',
                        'Unique-ID: uuid-protocol-burst',
                        'Channel-Name: sofia%2Finternal%2F9000',
                    ]) . "\n\n"),
            );
        });
        $client = $this->authenticatedClient($server);

        $eventDeferred = new Deferred();
        $client->events()->onEvent('CHANNEL_CREATE', function ($event) use ($eventDeferred): void {
            $eventDeferred->resolve($event);
        });

        $reply = $this->await($client->api('status'));
        $event = $this->await($eventDeferred->promise(), 0.2);

        self::assertSame("+OK burst-reply\n", $reply->body());
        self::assertInstanceOf(ChannelLifecycleEvent::class, $event);
        self::assertSame('uuid-protocol-burst', $event->uniqueId());
        self::assertSame(ConnectionState::Authenticated, $client->health()->snapshot()->connectionState);

        $server->close();
    }

    public function testMalformedContentLengthAfterSuccessfulSessionFailsClosed(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient($server);

        $server->writeRawFrame(
            $server->activeConnection(),
            "Content-Type: api/response\nContent-Length: not-a-number\n\n",
        );

        $this->waitUntil(
            fn(): bool => $client->health()->snapshot()->connectionState === ConnectionState::Disconnected,
            0.2,
        );

        $snapshot = $client->health()->snapshot();
        self::assertSame(ConnectionState::Disconnected, $snapshot->connectionState);
        self::assertSame(SessionState::Disconnected, $snapshot->sessionState);
        self::assertFalse($snapshot->isLive);
        self::assertSame(MalformedFrameException::class, $snapshot->lastErrorClass);

        $server->close();
    }

    public function testBadFrameDoesNotLeaveHalfAliveState(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient($server);

        $server->writeRawFrame($server->activeConnection(), "Content-Type auth/request\n\n");

        $this->waitUntil(
            fn(): bool => $client->health()->snapshot()->connectionState === ConnectionState::Disconnected,
            0.2,
        );

        $snapshot = $client->health()->snapshot();
        self::assertSame(ConnectionState::Disconnected, $snapshot->connectionState);
        self::assertSame(SessionState::Disconnected, $snapshot->sessionState);
        self::assertFalse($snapshot->isLive);
        self::assertSame(MalformedFrameException::class, $snapshot->lastErrorClass);

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

    private function authenticatedClient(ScriptedFakeEslServer $server): AsyncEslClientInterface
    {
        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                retryPolicy: RetryPolicy::disabled(),
                heartbeat: HeartbeatConfig::disabled(),
            ),
            $this->loop,
        );

        $this->await($client->connect());

        return $client;
    }

    private function frame(string $contentType, string $body): string
    {
        return sprintf(
            "Content-Type: %s\nContent-Length: %d\n\n%s",
            $contentType,
            strlen($body),
            $body,
        );
    }
}
