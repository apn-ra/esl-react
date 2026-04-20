<?php declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Integration;

use Apntalk\EslCore\Events\BackgroundJobEvent;
use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Bgapi\BgapiJobHandle;
use Apntalk\EslReact\Config\CommandTimeoutConfig;
use Apntalk\EslReact\Config\HeartbeatConfig;
use Apntalk\EslReact\Config\RetryPolicy;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Exceptions\CommandTimeoutException;
use Apntalk\EslReact\Exceptions\ConnectionException;
use Apntalk\EslReact\Exceptions\DrainException;
use Apntalk\EslReact\Tests\FakeServer\ScriptedFakeEslServer;
use Apntalk\EslReact\Tests\Support\AsyncTestCase;

final class BgapiIntegrationTest extends AsyncTestCase
{
    public function testBgapiDispatchReturnsTrackedHandleAndResolvesOnCompletion(): void
    {
        $jobUuid = 'job-uuid-1';
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient($server);

        $server->queueCommandHandler(function ($connection, string $command) use ($server, $jobUuid): void {
            self::assertSame('bgapi originate sofia/internal/1000 &echo', $command);
            $server->writeBgapiAcceptedReply($connection, $jobUuid);
        });

        $handle = $client->bgapi('originate', 'sofia/internal/1000 &echo');

        self::assertInstanceOf(BgapiJobHandle::class, $handle);
        self::assertSame('', $handle->jobUuid());
        self::assertSame('originate', $handle->eslCommand());
        self::assertSame('sofia/internal/1000 &echo', $handle->eslArgs());

        $this->waitUntil(
            fn (): bool => $handle->jobUuid() === $jobUuid
                && $client->health()->snapshot()->pendingBgapiJobCount === 1,
            0.2,
        );

        $server->emitBackgroundJobEvent($jobUuid, "+OK originate complete\n", 'originate');

        $event = $this->await($handle->promise());

        self::assertInstanceOf(BackgroundJobEvent::class, $event);
        self::assertSame($jobUuid, $handle->jobUuid());
        self::assertSame($jobUuid, $event->jobUuid());
        self::assertSame("+OK originate complete\n", $event->result());
        self::assertSame(0, $client->health()->snapshot()->pendingBgapiJobCount);

        $server->close();
    }

    public function testMultiplePendingJobsDoNotCrossResolveWhenCompletionsArriveOutOfOrder(): void
    {
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient($server);

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('bgapi uuid_kill a-uuid', $command);
            $server->writeBgapiAcceptedReply($connection, 'job-a');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('bgapi uuid_kill b-uuid', $command);
            $server->writeBgapiAcceptedReply($connection, 'job-b');
        });

        $first = $client->bgapi('uuid_kill', 'a-uuid');
        $second = $client->bgapi('uuid_kill', 'b-uuid');

        $this->waitUntil(
            fn (): bool => $first->jobUuid() === 'job-a'
                && $second->jobUuid() === 'job-b'
                && $client->health()->snapshot()->pendingBgapiJobCount === 2,
            0.2,
        );

        $server->emitBackgroundJobEvent('job-b', "+OK second complete\n", 'uuid_kill');
        $server->emitBackgroundJobEvent('job-a', "+OK first complete\n", 'uuid_kill');

        $secondEvent = $this->await($second->promise());
        $firstEvent = $this->await($first->promise());

        self::assertSame('job-b', $secondEvent->jobUuid());
        self::assertSame("+OK second complete\n", $secondEvent->result());
        self::assertSame('job-a', $firstEvent->jobUuid());
        self::assertSame("+OK first complete\n", $firstEvent->result());
        self::assertSame(0, $client->health()->snapshot()->pendingBgapiJobCount);

        $server->close();
    }

    public function testMissingCompletionTimesOutAndLateCompletionIsIgnored(): void
    {
        $jobUuid = 'job-timeout';
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient(
            $server,
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                heartbeat: HeartbeatConfig::disabled(),
                commandTimeout: CommandTimeoutConfig::default()->withBgapiOrphanTimeout(0.05),
            ),
        );

        $server->queueCommandHandler(function ($connection, string $command) use ($server, $jobUuid): void {
            self::assertSame('bgapi status', $command);
            $server->writeBgapiAcceptedReply($connection, $jobUuid);
        });

        $handle = $client->bgapi('status');

        $resolved = false;
        $rejected = null;
        $handle->promise()->then(
            function () use (&$resolved): void {
                $resolved = true;
            },
            function (\Throwable $e) use (&$rejected): void {
                $rejected = $e;
            },
        );

        $this->waitUntil(
            fn (): bool => $handle->jobUuid() === $jobUuid
                && $client->health()->snapshot()->pendingBgapiJobCount === 1,
            0.2,
        );

        try {
            $this->await($handle->promise(), 0.2);
            self::fail('Expected bgapi completion timeout');
        } catch (CommandTimeoutException $e) {
            self::assertSame('bgapi status', $e->eslCommand());
        }

        self::assertFalse($resolved);
        self::assertInstanceOf(CommandTimeoutException::class, $rejected);
        self::assertSame(0, $client->health()->snapshot()->pendingBgapiJobCount);

        $server->emitBackgroundJobEvent($jobUuid, "+OK late completion\n", 'status');
        $this->runLoopFor(0.02);

        self::assertFalse($resolved);
        self::assertInstanceOf(CommandTimeoutException::class, $rejected);
        self::assertSame(0, $client->health()->snapshot()->pendingBgapiJobCount);

        $server->close();
    }

    public function testPendingBgapiJobSurvivesUnexpectedDisconnectAndCanResolveAfterReconnect(): void
    {
        $jobUuid = 'job-recover';
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient(
            $server,
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                retryPolicy: RetryPolicy::withMaxAttempts(2, 0.01),
                heartbeat: HeartbeatConfig::disabled(),
                commandTimeout: CommandTimeoutConfig::default()->withBgapiOrphanTimeout(0.2),
            ),
        );

        $server->queueCommandHandler(function ($connection, string $command) use ($server, $jobUuid): void {
            self::assertSame('bgapi originate sofia/internal/2000 &echo', $command);
            $server->writeBgapiAcceptedReply($connection, $jobUuid);
        });

        $handle = $client->bgapi('originate', 'sofia/internal/2000 &echo');

        $this->waitUntil(
            fn (): bool => $handle->jobUuid() === $jobUuid
                && $client->health()->snapshot()->pendingBgapiJobCount === 1,
            0.2,
        );

        $resolved = false;
        $rejected = false;
        $handle->promise()->then(
            function () use (&$resolved): void {
                $resolved = true;
            },
            function () use (&$rejected): void {
                $rejected = true;
            },
        );

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });

        $server->closeActiveConnection();

        $this->waitUntil(
            fn (): bool => $client->health()->snapshot()->connectionState === ConnectionState::Reconnecting,
            0.2,
        );

        try {
            $client->bgapi('status');
            self::fail('Expected bgapi() to fail during recovery');
        } catch (ConnectionException $e) {
            self::assertSame('Runtime is not authenticated', $e->getMessage());
        }

        $this->waitUntil(
            fn (): bool => $server->connectionCount() === 2
                && $client->health()->snapshot()->connectionState === ConnectionState::Authenticated,
            0.3,
        );

        self::assertFalse($resolved);
        self::assertFalse($rejected);
        self::assertSame(1, $client->health()->snapshot()->pendingBgapiJobCount);

        $server->emitBackgroundJobEvent($jobUuid, "+OK recovered completion\n", 'originate');

        $event = $this->await($handle->promise(), 0.2);

        self::assertSame($jobUuid, $event->jobUuid());
        self::assertSame("+OK recovered completion\n", $event->result());
        self::assertSame(0, $client->health()->snapshot()->pendingBgapiJobCount);

        $server->close();
    }

    public function testBgapiBeforeAuthFailsClosedAndExplicitDisconnectRejectsPendingJob(): void
    {
        $jobUuid = 'job-close';
        $server = $this->authenticatedServer();
        $unauthenticatedClient = AsyncEslRuntime::make(
            RuntimeConfig::create(host: '127.0.0.1', port: $server->port(), password: 'ClueCon'),
            $this->loop,
        );

        try {
            $unauthenticatedClient->bgapi('status');
            self::fail('Expected bgapi() to fail before auth');
        } catch (ConnectionException $e) {
            self::assertSame('Runtime is not authenticated', $e->getMessage());
        }

        $client = $this->authenticatedClient($server);

        $server->queueCommandHandler(function ($connection, string $command) use ($server, $jobUuid): void {
            self::assertSame('bgapi status', $command);
            $server->writeBgapiAcceptedReply($connection, $jobUuid);
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('exit', $command);
            $server->writeCommandReply($connection, '+OK bye');
        });

        $handle = $client->bgapi('status');

        $this->waitUntil(
            fn (): bool => $handle->jobUuid() === $jobUuid
                && $client->health()->snapshot()->pendingBgapiJobCount === 1,
            0.2,
        );

        $this->await($client->disconnect());

        try {
            $this->await($handle->promise(), 0.2);
            self::fail('Expected pending bgapi job to reject on explicit disconnect');
        } catch (DrainException $e) {
            self::assertStringContainsString('Drain deadline expired', $e->getMessage());
        }

        self::assertSame(ConnectionState::Closed, $client->health()->snapshot()->connectionState);
        self::assertSame(0, $client->health()->snapshot()->pendingBgapiJobCount);

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
