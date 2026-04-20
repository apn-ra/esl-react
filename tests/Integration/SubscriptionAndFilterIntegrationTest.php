<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Integration;

use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Exceptions\ConnectionException;
use Apntalk\EslReact\Session\SessionState;
use Apntalk\EslReact\Tests\FakeServer\ScriptedFakeEslServer;
use Apntalk\EslReact\Tests\Support\AsyncTestCase;
use Throwable;

final class SubscriptionAndFilterIntegrationTest extends AsyncTestCase
{
    public function testSubscribeAfterAuthSendsFullDesiredSubscriptionSet(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient($server);

        $this->await($client->subscriptions()->subscribe('CHANNEL_CREATE'));
        $this->await($client->subscriptions()->subscribe('CHANNEL_ANSWER'));

        self::assertSame(
            ['auth ClueCon', 'event plain CHANNEL_CREATE', 'event plain CHANNEL_CREATE CHANNEL_ANSWER'],
            $server->receivedCommands(),
        );
        self::assertSame(
            ['CHANNEL_CREATE', 'CHANNEL_ANSWER'],
            $client->subscriptions()->activeEventNames(),
        );

        $server->close();
    }

    public function testUnsubscribeAfterAuthSendsReducedDesiredSubscriptionSet(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient($server);

        $this->await($client->subscriptions()->subscribe('CHANNEL_CREATE', 'CHANNEL_ANSWER'));
        $this->await($client->subscriptions()->unsubscribe('CHANNEL_CREATE'));
        $this->await($client->subscriptions()->unsubscribe('CHANNEL_ANSWER'));

        self::assertSame(
            [
                'auth ClueCon',
                'event plain CHANNEL_CREATE CHANNEL_ANSWER',
                'event plain CHANNEL_ANSWER',
                'noevents',
            ],
            $server->receivedCommands(),
        );
        self::assertSame([], $client->subscriptions()->activeEventNames());

        $server->close();
    }

    public function testDuplicateSubscribeIsANoopAndDoesNotCorruptDesiredState(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient($server);

        $this->await($client->subscriptions()->subscribe('CHANNEL_CREATE'));
        $this->await($client->subscriptions()->subscribe('CHANNEL_CREATE'));

        self::assertSame(
            ['auth ClueCon', 'event plain CHANNEL_CREATE'],
            $server->receivedCommands(),
        );
        self::assertSame(['CHANNEL_CREATE'], $client->subscriptions()->activeEventNames());

        $server->close();
    }

    public function testUnsubscribeOfInactiveSubscriptionIsANoop(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient($server);

        $this->await($client->subscriptions()->unsubscribe('CHANNEL_CREATE'));

        self::assertSame(['auth ClueCon'], $server->receivedCommands());
        self::assertSame([], $client->subscriptions()->activeEventNames());

        $server->close();
    }

    public function testAddAndRemoveFilterSendExpectedCommandsAndTrackState(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient($server);

        $this->await($client->subscriptions()->addFilter('Event-Name', 'CHANNEL_CREATE'));
        $this->await($client->subscriptions()->removeFilter('Event-Name', 'CHANNEL_CREATE'));

        self::assertSame(
            ['auth ClueCon', 'filter Event-Name CHANNEL_CREATE', 'filter delete Event-Name CHANNEL_CREATE'],
            $server->receivedCommands(),
        );
        self::assertFalse($client->subscriptions()->hasFilters());

        $server->close();
    }

    public function testDuplicateFilterAddAndMissingFilterRemovalAreNoops(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient($server);

        $this->await($client->subscriptions()->addFilter('Unique-ID', 'uuid-1'));
        $this->await($client->subscriptions()->addFilter('Unique-ID', 'uuid-1'));
        $this->await($client->subscriptions()->removeFilter('Unique-ID', 'missing'));

        self::assertSame(
            ['auth ClueCon', 'filter Unique-ID uuid-1'],
            $server->receivedCommands(),
        );
        self::assertTrue($client->subscriptions()->hasFilters());

        $server->close();
    }

    public function testLiveSubscribeRejectsServerErrorReplyWithoutCorruptingRuntimeState(): void
    {
        $server = $this->authenticatedServer();
        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(host: '127.0.0.1', port: $server->port(), password: 'ClueCon'),
            $this->loop,
        );

        $this->await($client->connect());

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain CHANNEL_CREATE', $command);
            $server->writeCommandReply($connection, '-ERR listener denied');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain CHANNEL_ANSWER', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });

        try {
            $this->await($client->subscriptions()->subscribe('CHANNEL_CREATE'));
            self::fail('Expected subscribe() to reject on server -ERR');
        } catch (ConnectionException $e) {
            self::assertSame(
                'Live session mutation command failed for event plain CHANNEL_CREATE: listener denied',
                $e->getMessage(),
            );
        }

        $snapshot = $client->health()->snapshot();
        self::assertSame(ConnectionState::Authenticated, $snapshot->connectionState);
        self::assertSame(SessionState::Active, $snapshot->sessionState);
        self::assertSame([], $client->subscriptions()->activeEventNames());
        self::assertFalse($client->subscriptions()->hasFilters());

        $this->await($client->subscriptions()->subscribe('CHANNEL_ANSWER'));

        self::assertSame(
            ['auth ClueCon', 'event plain CHANNEL_CREATE', 'event plain CHANNEL_ANSWER'],
            $server->receivedCommands(),
        );
        self::assertSame(['CHANNEL_ANSWER'], $client->subscriptions()->activeEventNames());

        $server->close();
    }

    public function testLiveAddFilterRejectsServerErrorReplyWithoutCorruptingRuntimeState(): void
    {
        $server = $this->authenticatedServer();
        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(host: '127.0.0.1', port: $server->port(), password: 'ClueCon'),
            $this->loop,
        );

        $this->await($client->connect());

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('filter Event-Name CHANNEL_CREATE', $command);
            $server->writeCommandReply($connection, '-ERR filter denied');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('filter Unique-ID uuid-1', $command);
            $server->writeCommandReply($connection, '+OK filter added');
        });

        try {
            $this->await($client->subscriptions()->addFilter('Event-Name', 'CHANNEL_CREATE'));
            self::fail('Expected addFilter() to reject on server -ERR');
        } catch (ConnectionException $e) {
            self::assertSame(
                'Live session mutation command failed for filter Event-Name CHANNEL_CREATE: filter denied',
                $e->getMessage(),
            );
        }

        $snapshot = $client->health()->snapshot();
        self::assertSame(ConnectionState::Authenticated, $snapshot->connectionState);
        self::assertSame(SessionState::Active, $snapshot->sessionState);
        self::assertSame([], $client->subscriptions()->activeEventNames());
        self::assertFalse($client->subscriptions()->hasFilters());

        $this->await($client->subscriptions()->addFilter('Unique-ID', 'uuid-1'));

        self::assertSame(
            ['auth ClueCon', 'filter Event-Name CHANNEL_CREATE', 'filter Unique-ID uuid-1'],
            $server->receivedCommands(),
        );
        self::assertTrue($client->subscriptions()->hasFilters());

        $server->close();
    }

    public function testLiveUnsubscribeRejectsServerErrorReplyWithoutCorruptingRuntimeState(): void
    {
        $server = $this->authenticatedServer();
        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(host: '127.0.0.1', port: $server->port(), password: 'ClueCon'),
            $this->loop,
        );

        $this->await($client->connect());

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain CHANNEL_CREATE CHANNEL_ANSWER', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });
        $this->await($client->subscriptions()->subscribe('CHANNEL_CREATE', 'CHANNEL_ANSWER'));

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain CHANNEL_ANSWER', $command);
            $server->writeCommandReply($connection, '-ERR unsubscribe denied');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain CHANNEL_ANSWER', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });

        try {
            $this->await($client->subscriptions()->unsubscribe('CHANNEL_CREATE'));
            self::fail('Expected unsubscribe() to reject on server -ERR');
        } catch (ConnectionException $e) {
            self::assertSame(
                'Live session mutation command failed for event plain CHANNEL_ANSWER: unsubscribe denied',
                $e->getMessage(),
            );
        }

        $snapshot = $client->health()->snapshot();
        self::assertSame(ConnectionState::Authenticated, $snapshot->connectionState);
        self::assertSame(SessionState::Active, $snapshot->sessionState);
        self::assertSame(['CHANNEL_CREATE', 'CHANNEL_ANSWER'], $client->subscriptions()->activeEventNames());
        self::assertFalse($client->subscriptions()->hasFilters());

        $this->await($client->subscriptions()->unsubscribe('CHANNEL_CREATE'));

        self::assertSame(
            [
                'auth ClueCon',
                'event plain CHANNEL_CREATE CHANNEL_ANSWER',
                'event plain CHANNEL_ANSWER',
                'event plain CHANNEL_ANSWER',
            ],
            $server->receivedCommands(),
        );
        self::assertSame(['CHANNEL_ANSWER'], $client->subscriptions()->activeEventNames());

        $server->close();
    }

    public function testLiveRemoveFilterRejectsServerErrorReplyWithoutCorruptingRuntimeState(): void
    {
        $server = $this->authenticatedServer();
        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(host: '127.0.0.1', port: $server->port(), password: 'ClueCon'),
            $this->loop,
        );

        $this->await($client->connect());

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('filter Event-Name CHANNEL_CREATE', $command);
            $server->writeCommandReply($connection, '+OK filter added');
        });
        $this->await($client->subscriptions()->addFilter('Event-Name', 'CHANNEL_CREATE'));

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('filter delete Event-Name CHANNEL_CREATE', $command);
            $server->writeCommandReply($connection, '-ERR filter remove denied');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('filter delete Event-Name CHANNEL_CREATE', $command);
            $server->writeCommandReply($connection, '+OK filter deleted');
        });

        try {
            $this->await($client->subscriptions()->removeFilter('Event-Name', 'CHANNEL_CREATE'));
            self::fail('Expected removeFilter() to reject on server -ERR');
        } catch (ConnectionException $e) {
            self::assertSame(
                'Live session mutation command failed for filter delete Event-Name CHANNEL_CREATE: filter remove denied',
                $e->getMessage(),
            );
        }

        $snapshot = $client->health()->snapshot();
        self::assertSame(ConnectionState::Authenticated, $snapshot->connectionState);
        self::assertSame(SessionState::Active, $snapshot->sessionState);
        self::assertSame([], $client->subscriptions()->activeEventNames());
        self::assertTrue($client->subscriptions()->hasFilters());

        $this->await($client->subscriptions()->removeFilter('Event-Name', 'CHANNEL_CREATE'));

        self::assertSame(
            [
                'auth ClueCon',
                'filter Event-Name CHANNEL_CREATE',
                'filter delete Event-Name CHANNEL_CREATE',
                'filter delete Event-Name CHANNEL_CREATE',
            ],
            $server->receivedCommands(),
        );
        self::assertFalse($client->subscriptions()->hasFilters());

        $server->close();
    }

    public function testSubscriptionMutationBeforeAuthIsRejected(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(host: '127.0.0.1', port: $server->port(), password: 'ClueCon'),
            $this->loop,
        );

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Runtime is not authenticated');

        try {
            $this->await($client->subscriptions()->subscribe('CHANNEL_CREATE'));
        } finally {
            self::assertSame([], $server->receivedCommands());
            $server->close();
        }
    }

    public function testSubscriptionMutationBeforeAuthRejectsReturnedPromiseInsteadOfThrowingSynchronously(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(host: '127.0.0.1', port: $server->port(), password: 'ClueCon'),
            $this->loop,
        );

        $rejected = null;

        try {
            $promise = $client->subscriptions()->subscribe('CHANNEL_CREATE');
        } catch (Throwable $e) {
            $server->close();
            self::fail(sprintf('Expected rejected promise, got synchronous %s: %s', $e::class, $e->getMessage()));
        }

        $promise->then(
            null,
            function (Throwable $e) use (&$rejected): void {
                $rejected = $e;
            },
        );

        try {
            $this->await($promise);
            self::fail('Expected subscribe() to reject before authentication');
        } catch (ConnectionException $e) {
            self::assertSame('Runtime is not authenticated', $e->getMessage());
        } finally {
            self::assertInstanceOf(ConnectionException::class, $rejected);
            self::assertSame([], $server->receivedCommands());
            $server->close();
        }
    }

    public function testFilterMutationBeforeAuthIsRejected(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(host: '127.0.0.1', port: $server->port(), password: 'ClueCon'),
            $this->loop,
        );

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Runtime is not authenticated');

        try {
            $this->await($client->subscriptions()->addFilter('Event-Name', 'CHANNEL_CREATE'));
        } finally {
            self::assertSame([], $server->receivedCommands());
            $server->close();
        }
    }

    public function testSubscriptionAndFilterMutationsFailClosedAfterDisconnect(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient($server);

        $this->await($client->disconnect());

        try {
            $this->await($client->subscriptions()->subscribe('CHANNEL_CREATE'));
            self::fail('Expected subscribe() to fail after disconnect');
        } catch (ConnectionException $e) {
            self::assertSame('Runtime is not authenticated', $e->getMessage());
        }

        try {
            $this->await($client->subscriptions()->addFilter('Event-Name', 'CHANNEL_CREATE'));
            self::fail('Expected addFilter() to fail after disconnect');
        } catch (ConnectionException $e) {
            self::assertSame('Runtime is not authenticated', $e->getMessage());
        }

        $snapshot = $client->health()->snapshot();
        self::assertSame(ConnectionState::Closed, $snapshot->connectionState);
        self::assertSame(SessionState::Disconnected, $snapshot->sessionState);
        self::assertSame([], $client->subscriptions()->activeEventNames());
        self::assertFalse($client->subscriptions()->hasFilters());

        $server->close();
    }

    public function testSubscribeAllIsIdempotentAndSpecificUnsubscribeIsRejected(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient($server);

        $this->await($client->subscriptions()->subscribeAll());
        $this->await($client->subscriptions()->subscribeAll());

        self::assertSame(['auth ClueCon', 'event plain all'], $server->receivedCommands());

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Cannot unsubscribe specific events while subscribed to all events in the current implementation');

        try {
            $this->await($client->subscriptions()->unsubscribe('CHANNEL_CREATE'));
        } finally {
            $server->close();
        }
    }

    public function testSpecificUnsubscribeFromSubscribeAllRejectsReturnedPromiseInsteadOfThrowingSynchronously(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient($server);

        $this->await($client->subscriptions()->subscribeAll());

        $rejected = null;

        try {
            $promise = $client->subscriptions()->unsubscribe('CHANNEL_CREATE');
        } catch (Throwable $e) {
            $server->close();
            self::fail(sprintf('Expected rejected promise, got synchronous %s: %s', $e::class, $e->getMessage()));
        }

        $promise->then(
            null,
            function (Throwable $e) use (&$rejected): void {
                $rejected = $e;
            },
        );

        try {
            $this->await($promise);
            self::fail('Expected unsubscribe() from subscribeAll() to reject');
        } catch (ConnectionException $e) {
            self::assertSame(
                'Cannot unsubscribe specific events while subscribed to all events in the current implementation',
                $e->getMessage(),
            );
        } finally {
            self::assertInstanceOf(ConnectionException::class, $rejected);
            self::assertSame(['auth ClueCon', 'event plain all'], $server->receivedCommands());
            $server->close();
        }
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

    private function authenticatedClient(ScriptedFakeEslServer $server)
    {
        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(host: '127.0.0.1', port: $server->port(), password: 'ClueCon'),
            $this->loop,
        );

        $this->await($client->connect());

        foreach (range(1, 8) as $_) {
            $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
                if (str_starts_with($command, 'event plain')) {
                    $server->writeCommandReply($connection, '+OK event listener enabled plain');
                    return;
                }

                if ($command === 'noevents') {
                    $server->writeCommandReply($connection, '+OK no events');
                    return;
                }

                if (str_starts_with($command, 'filter delete ')) {
                    $server->writeCommandReply($connection, '+OK filter deleted');
                    return;
                }

                if (str_starts_with($command, 'filter ')) {
                    $server->writeCommandReply($connection, '+OK filter added');
                    return;
                }

                if ($command === 'exit') {
                    $server->writeCommandReply($connection, '+OK bye');
                    return;
                }

                self::fail(sprintf('Unexpected fake-server command: %s', $command));
            });
        }

        return $client;
    }
}
