<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Integration;

use Apntalk\EslCore\Events\ChannelLifecycleEvent;
use Apntalk\EslCore\Replies\ApiReply;
use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Config\SubscriptionConfig;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Tests\FakeServer\ScriptedFakeEslServer;
use Apntalk\EslReact\Tests\Support\AsyncTestCase;
use React\Promise\Deferred;

final class ReleaseSmokeTest extends AsyncTestCase
{
    public function testPublicRuntimeHappyPathSmoke(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain CHANNEL_CREATE', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('filter Unique-ID smoke-uuid', $command);
            $server->writeCommandReply($connection, '+OK filter added');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $server->writeApiResponse($connection, "+OK smoke-ready\n");
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('exit', $command);
            $server->writeCommandReply($connection, '+OK bye');
        });

        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                subscriptions: SubscriptionConfig::forEvents('CHANNEL_CREATE')
                    ->withFilter('Unique-ID', 'smoke-uuid'),
            ),
            $this->loop,
        );

        $this->await($client->connect());

        self::assertSame(['CHANNEL_CREATE'], $client->subscriptions()->activeEventNames());
        self::assertTrue($client->subscriptions()->hasFilters());

        $reply = $this->await($client->api('status'));
        self::assertInstanceOf(ApiReply::class, $reply);
        self::assertSame("+OK smoke-ready\n", $reply->body());

        $eventDeferred = new Deferred();
        $client->events()->onEvent('CHANNEL_CREATE', function ($event) use ($eventDeferred): void {
            $eventDeferred->resolve($event);
        });

        $server->emitPlainEvent([
            'Event-Name' => 'CHANNEL_CREATE',
            'Event-Sequence' => '500',
            'Unique-ID' => 'smoke-uuid',
            'Channel-State' => 'CS_NEW',
            'Channel-Name' => 'sofia/internal/1000',
        ]);

        $event = $this->await($eventDeferred->promise());
        self::assertInstanceOf(ChannelLifecycleEvent::class, $event);
        self::assertSame('CHANNEL_CREATE', $event->eventName());
        self::assertSame('smoke-uuid', $event->uniqueId());

        $this->await($client->disconnect());
        $this->runLoopFor(0.02);

        self::assertSame(
            [
                'auth ClueCon',
                'event plain CHANNEL_CREATE',
                'filter Unique-ID smoke-uuid',
                'api status',
                'exit',
            ],
            $server->receivedCommands(),
        );
        self::assertSame(ConnectionState::Closed, $client->health()->snapshot()->connectionState);

        $server->close();
    }
}
