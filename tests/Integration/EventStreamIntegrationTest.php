<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Integration;

use Apntalk\EslCore\Correlation\EventEnvelope;
use Apntalk\EslCore\Events\ChannelLifecycleEvent;
use Apntalk\EslCore\Events\RawEvent;
use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Tests\FakeServer\ScriptedFakeEslServer;
use Apntalk\EslReact\Tests\Support\AsyncTestCase;
use React\Promise\Deferred;
use RuntimeException;

final class EventStreamIntegrationTest extends AsyncTestCase
{
    public function testKnownEventIsDeliveredAsTypedModel(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient($server);

        $deferred = new Deferred();
        $client->events()->onEvent('CHANNEL_CREATE', function ($event) use ($deferred): void {
            $deferred->resolve($event);
        });

        $server->emitPlainEvent([
            'Event-Name' => 'CHANNEL_CREATE',
            'Event-Sequence' => '42',
            'Unique-ID' => 'uuid-1',
            'Channel-State' => 'CS_NEW',
            'Channel-Name' => 'sofia/internal/1000',
        ]);

        $event = $this->await($deferred->promise());

        self::assertInstanceOf(ChannelLifecycleEvent::class, $event);
        self::assertSame('CHANNEL_CREATE', $event->eventName());
        self::assertSame('uuid-1', $event->uniqueId());
        self::assertSame('CS_NEW', $event->channelState());
        self::assertSame('sofia/internal/1000', $event->channelName());

        $server->close();
    }

    public function testMultipleEventsAreDeliveredInSocketOrder(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient($server);

        $seen = [];
        $deferred = new Deferred();
        $client->events()->onAnyEvent(function ($event) use (&$seen, $deferred): void {
            $seen[] = $event->eventName();
            if (count($seen) === 2) {
                $deferred->resolve($seen);
            }
        });

        $server->emitPlainEvent([
            'Event-Name' => 'CHANNEL_CREATE',
            'Event-Sequence' => '1',
            'Unique-ID' => 'uuid-1',
        ]);
        $server->emitPlainEvent([
            'Event-Name' => 'CHANNEL_ANSWER',
            'Event-Sequence' => '2',
            'Unique-ID' => 'uuid-1',
        ]);

        self::assertSame(['CHANNEL_CREATE', 'CHANNEL_ANSWER'], $this->await($deferred->promise()));

        $server->close();
    }

    public function testEventBurstIsDeliveredInSocketOrderWithoutDroppingFrames(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient($server);

        $seen = [];
        $deferred = new Deferred();
        $client->events()->onAnyEvent(function ($event) use (&$seen, $deferred): void {
            $seen[] = $event->eventName() . ':' . $event->uniqueId();
            if (count($seen) === 5) {
                $deferred->resolve($seen);
            }
        });

        for ($i = 1; $i <= 5; $i++) {
            $server->emitPlainEvent([
                'Event-Name' => 'CHANNEL_CREATE',
                'Event-Sequence' => (string) $i,
                'Unique-ID' => 'uuid-' . $i,
            ]);
        }

        self::assertSame(
            [
                'CHANNEL_CREATE:uuid-1',
                'CHANNEL_CREATE:uuid-2',
                'CHANNEL_CREATE:uuid-3',
                'CHANNEL_CREATE:uuid-4',
                'CHANNEL_CREATE:uuid-5',
            ],
            $this->await($deferred->promise()),
        );

        $server->close();
    }

    public function testRawEnvelopeAccessCoexistsWithTypedDispatch(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient($server);

        $rawDeferred = new Deferred();
        $typedDeferred = new Deferred();

        $client->events()->onRawEnvelope(function (EventEnvelope $envelope) use ($rawDeferred): void {
            $rawDeferred->resolve($envelope);
        });
        $client->events()->onEvent('CHANNEL_CREATE', function ($event) use ($typedDeferred): void {
            $typedDeferred->resolve($event);
        });

        $server->emitPlainEvent([
            'Event-Name' => 'CHANNEL_CREATE',
            'Event-Sequence' => '11',
            'Unique-ID' => 'uuid-raw',
            'Channel-Name' => 'sofia/internal/2000',
        ]);

        $envelope = $this->await($rawDeferred->promise());
        $typed = $this->await($typedDeferred->promise());

        self::assertInstanceOf(EventEnvelope::class, $envelope);
        self::assertInstanceOf(ChannelLifecycleEvent::class, $typed);
        self::assertSame('CHANNEL_CREATE', $envelope->event()->eventName());
        self::assertSame('11', $envelope->metadata()->protocolSequence());
        self::assertSame('CHANNEL_CREATE', $typed->eventName());

        $server->close();
    }

    public function testUnknownEventIsSurfacedThroughUnknownPath(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient($server);

        $unknownDeferred = new Deferred();
        $typedEvents = [];
        $client->events()->onAnyEvent(function ($event) use (&$typedEvents): void {
            $typedEvents[] = $event->eventName();
        });
        $client->events()->onUnknown(function (RawEvent $event) use ($unknownDeferred): void {
            $unknownDeferred->resolve($event);
        });

        $server->emitPlainEvent([
            'Event-Name' => 'SOME_NEW_EVENT',
            'Event-Sequence' => '99',
            'Unique-ID' => 'uuid-unknown',
        ]);

        $event = $this->await($unknownDeferred->promise());

        self::assertInstanceOf(RawEvent::class, $event);
        self::assertSame('SOME_NEW_EVENT', $event->eventName());
        self::assertSame([], $typedEvents);

        $server->close();
    }

    public function testListenerExceptionsDoNotStopSubsequentListenersOrCrashRuntime(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient($server);

        $deferred = new Deferred();
        $seen = [];
        $client->events()->onEvent('CHANNEL_CREATE', function (): void {
            throw new RuntimeException('listener boom');
        });
        $client->events()->onEvent('CHANNEL_CREATE', function ($event) use (&$seen, $deferred): void {
            $seen[] = $event->eventName();
            $deferred->resolve(null);
        });

        $server->emitPlainEvent([
            'Event-Name' => 'CHANNEL_CREATE',
            'Event-Sequence' => '31',
            'Unique-ID' => 'uuid-safe',
        ]);

        $this->await($deferred->promise());
        $reply = $this->await($client->api('status'));

        self::assertSame(['CHANNEL_CREATE'], $seen);
        self::assertSame("+OK still-alive\n", $reply->body());

        $server->close();
    }

    public function testRepliesDoNotEnterTheEventPath(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient($server);

        $rawCount = 0;
        $typedCount = 0;
        $unknownCount = 0;

        $client->events()->onRawEnvelope(function () use (&$rawCount): void {
            $rawCount++;
        });
        $client->events()->onAnyEvent(function () use (&$typedCount): void {
            $typedCount++;
        });
        $client->events()->onUnknown(function () use (&$unknownCount): void {
            $unknownCount++;
        });

        $reply = $this->await($client->api('status'));

        self::assertSame("+OK still-alive\n", $reply->body());
        self::assertSame(0, $rawCount);
        self::assertSame(0, $typedCount);
        self::assertSame(0, $unknownCount);

        $server->close();
    }

    public function testPartialEventFrameIsReassembledAndDelivered(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient($server);

        $deferred = new Deferred();
        $client->events()->onEvent('CHANNEL_CREATE', function ($event) use ($deferred): void {
            $deferred->resolve($event);
        });

        $body = implode("\n", [
            'Event-Name: CHANNEL_CREATE',
            'Event-Sequence: 77',
            'Unique-ID: uuid-fragmented',
            'Channel-Name: sofia%2Finternal%2F3000',
        ]) . "\n\n";
        $frame = sprintf(
            "Content-Type: text/event-plain\nContent-Length: %d\n\n%s",
            strlen($body),
            $body,
        );

        $fragments = [
            substr($frame, 0, 20),
            substr($frame, 20, 17),
            substr($frame, 37),
        ];
        $server->writeRawFrameFragments($server->activeConnection(), $fragments, 0.002);

        /** @var ChannelLifecycleEvent $event */
        $event = $this->await($deferred->promise(), 0.2);

        self::assertSame('CHANNEL_CREATE', $event->eventName());
        self::assertSame('uuid-fragmented', $event->uniqueId());
        self::assertSame('sofia/internal/3000', $event->channelName());

        $server->close();
    }

    private function authenticatedServer(): ScriptedFakeEslServer
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $server->writeApiResponse($connection, "+OK still-alive\n");
        });

        return $server;
    }

    private function authenticatedClient(ScriptedFakeEslServer $server)
    {
        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(host: '127.0.0.1', port: $server->port(), password: 'ClueCon'),
            $this->loop,
        );

        $this->await($client->connect());

        return $client;
    }
}
