<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Integration;

use Apntalk\EslCore\Replies\ApiReply;
use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Config\BackpressureConfig;
use Apntalk\EslReact\Config\HeartbeatConfig;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Exceptions\BackpressureException;
use Apntalk\EslReact\Exceptions\DrainException;
use Apntalk\EslReact\Tests\FakeServer\ScriptedFakeEslServer;
use Apntalk\EslReact\Tests\Support\AsyncTestCase;
use Throwable;

final class BackpressureAndDrainIntegrationTest extends AsyncTestCase
{
    public function testOverloadRejectsNewApiWorkDeterministically(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient(
            $server,
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                heartbeat: HeartbeatConfig::disabled(),
                backpressure: BackpressureConfig::withLimit(1, 0.05),
            ),
        );

        $server->queueCommandHandler(static function ($connection, string $command): void {
            self::assertSame('api status', $command);
        });

        $first = $client->api('status');
        $first->then(null, static function (): void {});

        $this->waitUntil(
            fn(): bool => $client->health()->snapshot()->totalInflightCount === 1
                && $client->health()->snapshot()->isOverloaded,
            0.2,
        );

        try {
            $this->await($client->api('reloadxml'));
            self::fail('Expected overload rejection for second api()');
        } catch (BackpressureException $e) {
            self::assertSame('Runtime overloaded (1 inflight, limit 1)', $e->getMessage());
        }

        $snapshot = $client->health()->snapshot();
        self::assertSame(1, $snapshot->inflightCommandCount);
        self::assertSame(0, $snapshot->pendingBgapiJobCount);
        self::assertSame(1, $snapshot->totalInflightCount);
        self::assertTrue($snapshot->isOverloaded);

        $this->await($client->disconnect(), 0.2);

        try {
            $this->await($first, 0.2);
            self::fail('Expected inflight api() to terminate during drain');
        } catch (DrainException $e) {
            self::assertStringContainsString('Drain deadline expired', $e->getMessage());
        }

        $server->close();
    }

    public function testOverloadRejectsSubscriptionMutationViaReturnedPromise(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient(
            $server,
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                heartbeat: HeartbeatConfig::disabled(),
                backpressure: BackpressureConfig::withLimit(1, 0.05),
            ),
        );

        $server->queueCommandHandler(static function ($connection, string $command): void {
            self::assertSame('api status', $command);
        });

        $first = $client->api('status');
        $first->then(null, static function (): void {});

        $this->waitUntil(
            fn(): bool => $client->health()->snapshot()->totalInflightCount === 1
                && $client->health()->snapshot()->isOverloaded,
            0.2,
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
            $this->await($promise, 0.1);
            self::fail('Expected subscribe() overload rejection');
        } catch (BackpressureException $e) {
            self::assertSame('Runtime overloaded (1 inflight, limit 1)', $e->getMessage());
        }

        self::assertInstanceOf(BackpressureException::class, $rejected);

        $this->await($client->disconnect(), 0.2);

        try {
            $this->await($first, 0.2);
            self::fail('Expected inflight api() to terminate during drain');
        } catch (DrainException) {
        }

        $server->close();
    }

    public function testOverloadRejectsNewBgapiWorkDeterministically(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient(
            $server,
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                heartbeat: HeartbeatConfig::disabled(),
                backpressure: BackpressureConfig::withLimit(1, 0.05),
            ),
        );

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('bgapi status', $command);
            $server->writeBgapiAcceptedReply($connection, 'job-overload');
        });

        $handle = $client->bgapi('status');

        $this->waitUntil(
            fn(): bool => $client->health()->snapshot()->pendingBgapiJobCount === 1
                && $client->health()->snapshot()->totalInflightCount === 1
                && $client->health()->snapshot()->isOverloaded,
            0.2,
        );

        try {
            $client->bgapi('reloadxml');
            self::fail('Expected overload rejection for second bgapi()');
        } catch (BackpressureException $e) {
            self::assertSame('Runtime overloaded (1 inflight, limit 1)', $e->getMessage());
        }

        $snapshot = $client->health()->snapshot();
        self::assertSame(0, $snapshot->inflightCommandCount);
        self::assertSame(1, $snapshot->pendingBgapiJobCount);
        self::assertSame(1, $snapshot->totalInflightCount);
        self::assertTrue($snapshot->isOverloaded);

        $this->await($client->disconnect(), 0.2);

        try {
            $this->await($handle->promise(), 0.2);
            self::fail('Expected pending bgapi to terminate during drain');
        } catch (DrainException $e) {
            self::assertStringContainsString('Drain deadline expired', $e->getMessage());
        }

        $server->close();
    }

    public function testDrainRejectsNewWorkAndLetsInflightApiSettleBeforeDeadline(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient(
            $server,
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                heartbeat: HeartbeatConfig::disabled(),
                backpressure: BackpressureConfig::withLimit(5, 0.2),
            ),
        );

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $this->loop->addTimer(0.02, function () use ($connection, $server): void {
                $server->writeApiResponse($connection, "+OK drained cleanly\n");
            });
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('exit', $command);
            $server->writeCommandReply($connection, '+OK bye');
        });

        $api = $client->api('status');
        $disconnect = $client->disconnect();

        $this->waitUntil(
            fn(): bool => $client->health()->snapshot()->connectionState === ConnectionState::Draining
                && $client->health()->snapshot()->isDraining,
            0.1,
        );

        try {
            $this->await($client->api('reloadxml'));
            self::fail('Expected api() rejection during drain');
        } catch (DrainException) {
        }

        try {
            $client->bgapi('status');
            self::fail('Expected bgapi() rejection during drain');
        } catch (DrainException) {
        }

        try {
            $this->await($client->subscriptions()->subscribe('CHANNEL_CREATE'));
            self::fail('Expected subscribe() rejection during drain');
        } catch (DrainException) {
        }

        $reply = $this->await($api, 0.2);
        self::assertInstanceOf(ApiReply::class, $reply);
        self::assertSame("+OK drained cleanly\n", $reply->body());

        $this->await($disconnect, 0.2);
        $this->runLoopFor(0.05);

        $snapshot = $client->health()->snapshot();
        self::assertSame(ConnectionState::Closed, $snapshot->connectionState);
        self::assertFalse($snapshot->isDraining);
        self::assertSame(0, $snapshot->totalInflightCount);
        self::assertFalse($snapshot->isOverloaded);
        self::assertSame(1, $server->connectionCount());

        $server->close();
    }

    public function testDrainRejectsSubscriptionMutationViaReturnedPromise(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient(
            $server,
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                heartbeat: HeartbeatConfig::disabled(),
                backpressure: BackpressureConfig::withLimit(5, 0.2),
            ),
        );

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('exit', $command);
            $server->writeCommandReply($connection, '+OK bye');
        });

        $disconnect = $client->disconnect();

        $this->waitUntil(
            fn(): bool => $client->health()->snapshot()->connectionState === ConnectionState::Draining
                && $client->health()->snapshot()->isDraining,
            0.1,
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
            $this->await($promise, 0.1);
            self::fail('Expected subscribe() rejection during drain');
        } catch (DrainException) {
        }

        self::assertInstanceOf(DrainException::class, $rejected);

        $this->await($disconnect, 0.2);
        $server->close();
    }

    public function testDrainTerminatesPendingBgapiAtDeadlineAndDoesNotReconnect(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient(
            $server,
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                heartbeat: HeartbeatConfig::disabled(),
                backpressure: BackpressureConfig::withLimit(5, 0.05),
            ),
        );

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('bgapi status', $command);
            $server->writeBgapiAcceptedReply($connection, 'job-drain');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('exit', $command);
            $server->writeCommandReply($connection, '+OK bye');
        });

        $handle = $client->bgapi('status');

        $this->waitUntil(
            fn(): bool => $client->health()->snapshot()->pendingBgapiJobCount === 1,
            0.2,
        );

        $disconnect = $client->disconnect();

        $this->waitUntil(
            fn(): bool => $client->health()->snapshot()->connectionState === ConnectionState::Draining
                && $client->health()->snapshot()->isDraining,
            0.1,
        );

        try {
            $client->bgapi('reloadxml');
            self::fail('Expected bgapi() rejection during drain');
        } catch (DrainException) {
        }

        try {
            $this->await($handle->promise(), 0.2);
            self::fail('Expected pending bgapi to terminate at drain deadline');
        } catch (DrainException $e) {
            self::assertStringContainsString('Drain deadline expired', $e->getMessage());
        }

        $this->await($disconnect, 0.2);
        $this->runLoopFor(0.05);

        $snapshot = $client->health()->snapshot();
        self::assertSame(ConnectionState::Closed, $snapshot->connectionState);
        self::assertFalse($snapshot->isDraining);
        self::assertSame(0, $snapshot->pendingBgapiJobCount);
        self::assertSame(0, $snapshot->totalInflightCount);
        self::assertSame(1, $server->connectionCount());

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

    private function authenticatedClient(ScriptedFakeEslServer $server, RuntimeConfig $config)
    {
        $client = AsyncEslRuntime::make($config, $this->loop);
        $this->await($client->connect());

        return $client;
    }
}
