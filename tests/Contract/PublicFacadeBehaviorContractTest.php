<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Contract;

use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Config\SubscriptionConfig;
use Apntalk\EslReact\Exceptions\DrainException;
use Apntalk\EslReact\Tests\FakeServer\ScriptedFakeEslServer;
use Apntalk\EslReact\Tests\Support\AsyncTestCase;
use Apntalk\EslReact\Tests\Support\CollectingReplaySink;

final class PublicFacadeBehaviorContractTest extends AsyncTestCase
{
    public function testSubscriptionConfigSeedsInitialDesiredStateAndAppliesOnFirstConnect(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain CHANNEL_CREATE CHANNEL_ANSWER', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('filter Unique-ID uuid-seeded', $command);
            $server->writeCommandReply($connection, '+OK filter added');
        });

        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                subscriptions: SubscriptionConfig::forEvents('CHANNEL_CREATE', 'CHANNEL_ANSWER')
                    ->withFilter('Unique-ID', 'uuid-seeded'),
            ),
            $this->loop,
        );

        $this->await($client->connect());

        self::assertSame(
            ['auth ClueCon', 'event plain CHANNEL_CREATE CHANNEL_ANSWER', 'filter Unique-ID uuid-seeded'],
            $server->receivedCommands(),
        );
        self::assertSame(['CHANNEL_CREATE', 'CHANNEL_ANSWER'], $client->subscriptions()->activeEventNames());
        self::assertTrue($client->subscriptions()->hasFilters());

        $server->close();
    }

    public function testDisconnectUsesBoundedDrainForAcceptedInflightApiWork(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(static function ($connection, string $command): void {
            self::assertSame('api status', $command);
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('exit', $command);
            $server->writeCommandReply($connection, '+OK bye');
        });

        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(host: '127.0.0.1', port: $server->port(), password: 'ClueCon'),
            $this->loop,
        );

        $this->await($client->connect());
        $api = $client->api('status');

        $disconnect = $client->disconnect();

        try {
            $this->await($api, 0.5);
            self::fail('Expected accepted inflight api() work to terminate by drain deadline');
        } catch (DrainException $e) {
            self::assertStringContainsString('Drain deadline expired', $e->getMessage());
        }

        $this->await($disconnect, 0.5);
        self::assertSame('closed', $client->health()->snapshot()->connectionState->value);

        $server->close();
    }

    public function testBgapiHandleIsReturnedBeforeAckAndPopulatesJobUuidAfterAcceptance(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('bgapi status', $command);
            $this->loop->addTimer(0.01, function () use ($connection, $server): void {
                $server->writeBgapiAcceptedReply($connection, 'job-contract-1');
            });
        });

        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(host: '127.0.0.1', port: $server->port(), password: 'ClueCon'),
            $this->loop,
        );

        $this->await($client->connect());
        $handle = $client->bgapi('status');

        self::assertSame('', $handle->jobUuid());

        $this->waitUntil(
            fn(): bool => $handle->jobUuid() === 'job-contract-1',
            0.2,
        );

        self::assertSame('status', $handle->eslCommand());
        self::assertSame('job-contract-1', $handle->jobUuid());

        $server->close();
    }

    public function testReplayArtifactsExposeStableIdentityFieldsForSupportedApiPath(): void
    {
        $sink = new CollectingReplaySink();
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $server->writeApiResponse($connection, "+OK contract\n");
        });

        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                replayCaptureEnabled: true,
                replayCaptureSinks: [$sink],
            ),
            $this->loop,
        );

        $this->await($client->connect());
        $sink->reset();

        $this->await($client->api('status'));

        self::assertCount(2, $sink->captured());
        foreach ($sink->captured() as $artifact) {
            self::assertArrayHasKey('replay-artifact-version', $artifact->derivedMetadata());
            self::assertArrayHasKey('replay-artifact-name', $artifact->derivedMetadata());
            self::assertArrayHasKey('runtime-capture-path', $artifact->derivedMetadata());
            self::assertSame('1', $artifact->derivedMetadata()['replay-artifact-version']);
            self::assertSame('replay-envelope.v1', $artifact->schemaVersion());
            self::assertArrayHasKey('captured-type', $artifact->identityFacts());
            self::assertArrayHasKey('capture-sequence', $artifact->orderingFacts());
            self::assertSame(
                $artifact->derivedMetadata()['replay-artifact-name'],
                $artifact->derivedMetadata()['runtime-capture-path'],
            );
        }

        self::assertSame('api.dispatch', $sink->captured()[0]->derivedMetadata()['replay-artifact-name']);
        self::assertSame('api.reply', $sink->captured()[1]->derivedMetadata()['replay-artifact-name']);

        $server->close();
    }
}
