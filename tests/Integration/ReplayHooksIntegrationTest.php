<?php declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Integration;

use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Config\BackpressureConfig;
use Apntalk\EslReact\Config\CommandTimeoutConfig;
use Apntalk\EslReact\Config\HeartbeatConfig;
use Apntalk\EslReact\Config\RetryPolicy;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Exceptions\BackpressureException;
use Apntalk\EslReact\Exceptions\ConnectionException;
use Apntalk\EslReact\Exceptions\DrainException;
use Apntalk\EslReact\Runner\PreparedRuntimeBootstrapInput;
use Apntalk\EslReact\Runner\RuntimeSessionContext;
use Apntalk\EslReact\Tests\FakeServer\ScriptedFakeEslServer;
use Apntalk\EslReact\Tests\Support\AsyncTestCase;
use Apntalk\EslReact\Tests\Support\CollectingReplaySink;
use React\Socket\Connector;

final class ReplayHooksIntegrationTest extends AsyncTestCase
{
    public function testApiDispatchAndReplyAreCaptured(): void
    {
        $sink = new CollectingReplaySink();
        $server = $this->authenticatedServer();
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $server->writeApiResponse($connection, "+OK still-alive\n");
        });
        $client = $this->authenticatedClient($server, $sink);

        $reply = $this->await($client->api('status'));

        self::assertSame("+OK still-alive\n", $reply->body());

        $captured = $sink->captured();
        self::assertCount(2, $captured);
        self::assertSame('1', $captured[0]->derivedMetadata()['replay-artifact-version']);
        self::assertSame('api.dispatch', $captured[0]->derivedMetadata()['replay-artifact-name']);
        self::assertSame('api.dispatch', $captured[0]->derivedMetadata()['runtime-capture-path']);
        self::assertSame('replay-envelope.v1', $captured[0]->schemaVersion());
        self::assertSame('dispatch', $captured[0]->capturedType());
        self::assertSame('api', $captured[0]->derivedMetadata()['runtime-command-type']);
        self::assertSame('status', $captured[0]->capturedName());
        self::assertSame('api status', $captured[0]->rawPayload());
        self::assertSame('dispatch', $captured[0]->identityFacts()['captured-type']);

        self::assertSame('api.reply', $captured[1]->derivedMetadata()['replay-artifact-name']);
        self::assertSame('api.reply', $captured[1]->derivedMetadata()['runtime-capture-path']);
        self::assertSame('reply', $captured[1]->capturedType());
        self::assertSame('ApiReply', $captured[1]->capturedName());
        self::assertSame('authenticated', $captured[1]->derivedMetadata()['runtime-connection-state']);
        self::assertSame('1', $captured[1]->derivedMetadata()['runtime-connection-generation']);
        self::assertSame((string) $captured[1]->captureSequence(), $captured[1]->orderingFacts()['capture-sequence']);

        $server->close();
    }

    public function testPreparedBootstrapReplayInjectionAddsStableIdentityMetadata(): void
    {
        $sink = new CollectingReplaySink();
        $server = $this->authenticatedServer();
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $server->writeApiResponse($connection, "+OK still-alive\n");
        });

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeBootstrapInput(
                endpoint: 'worker://node-a/runtime-1',
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                ),
                connector: new Connector([], $this->loop),
                inboundPipeline: new \Apntalk\EslCore\Inbound\InboundPipeline(),
                sessionContext: new RuntimeSessionContext(
                    'runtime-session-1',
                    metadata: ['pbx_node' => 'node-a'],
                    workerSessionId: 'worker-session-1',
                    connectionProfile: 'profile-a',
                    providerIdentity: 'provider-a',
                    connectorIdentity: 'connector-a',
                ),
                replayCaptureSinksOverride: [$sink],
            ),
            $this->loop,
        );

        $this->await($handle->startupPromise());
        $sink->reset();

        $reply = $this->await($handle->client()->api('status'));

        self::assertSame("+OK still-alive\n", $reply->body());
        self::assertCount(2, $sink->captured());
        self::assertSame('api.dispatch', $sink->captured()[0]->derivedMetadata()['replay-artifact-name']);
        self::assertSame('1', $sink->captured()[0]->derivedMetadata()['replay-artifact-version']);
        self::assertSame('runtime-session-1', $sink->captured()[0]->derivedMetadata()['runtime_session_id']);
        self::assertSame('worker-session-1', $sink->captured()[0]->derivedMetadata()['worker_session_id']);
        self::assertSame('profile-a', $sink->captured()[0]->derivedMetadata()['connection_profile']);
        self::assertSame('provider-a', $sink->captured()[0]->derivedMetadata()['provider_identity']);
        self::assertSame('connector-a', $sink->captured()[0]->derivedMetadata()['connector_identity']);
        self::assertSame('node-a', $sink->captured()[0]->derivedMetadata()['pbx_node']);

        $server->close();
    }

    public function testBgapiDispatchAckAndCompletionAreCapturedDeterministically(): void
    {
        $jobUuid = 'job-replay-1';
        $sink = new CollectingReplaySink();
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient($server, $sink);

        $server->queueCommandHandler(function ($connection, string $command) use ($server, $jobUuid): void {
            self::assertSame('bgapi status', $command);
            $server->writeBgapiAcceptedReply($connection, $jobUuid);
        });

        $handle = $client->bgapi('status');

        $this->waitUntil(
            fn (): bool => $handle->jobUuid() === $jobUuid,
            0.2,
        );

        $server->emitBackgroundJobEvent($jobUuid, "+OK bgapi complete\n", 'status');
        $this->await($handle->promise());

        $paths = array_map(
            static fn ($envelope): string => $envelope->derivedMetadata()['runtime-capture-path'],
            $sink->captured(),
        );

        self::assertSame(
            ['bgapi.dispatch', 'command.reply', 'bgapi.ack', 'event.raw', 'bgapi.complete'],
            $paths,
        );

        $ack = $sink->captured()[2];
        self::assertSame($jobUuid, $ack->derivedMetadata()['runtime-job-uuid']);
        self::assertSame('status', $ack->derivedMetadata()['runtime-command-name']);

        $completion = $sink->captured()[4];
        self::assertSame('BACKGROUND_JOB', $completion->capturedName());
        self::assertSame($jobUuid, $completion->derivedMetadata()['runtime-job-uuid']);
        self::assertSame($jobUuid, $completion->protocolFacts()['job-uuid']);

        $server->close();
    }

    public function testInboundEventsAreCapturedOnTheLiveRuntimePath(): void
    {
        $sink = new CollectingReplaySink();
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient($server, $sink);

        $server->emitPlainEvent([
            'Event-Name' => 'CHANNEL_CREATE',
            'Event-Sequence' => '42',
            'Unique-ID' => 'uuid-replay',
            'Channel-Name' => 'sofia/internal/1000',
        ]);

        $this->waitUntil(
            fn (): bool => count($sink->captured()) === 1,
            0.2,
        );

        $captured = $sink->captured()[0];
        self::assertSame('event.raw', $captured->derivedMetadata()['replay-artifact-name']);
        self::assertSame('event.raw', $captured->derivedMetadata()['runtime-capture-path']);
        self::assertSame('event', $captured->capturedType());
        self::assertSame('CHANNEL_CREATE', $captured->capturedName());
        self::assertSame('42', $captured->protocolSequence());
        self::assertSame('uuid-replay', $captured->protocolFacts()['unique-id']);

        $server->close();
    }

    public function testDisabledReplayCaptureEmitsNothing(): void
    {
        $sink = new CollectingReplaySink();
        $server = $this->authenticatedServer();
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $server->writeApiResponse($connection, "+OK still-alive\n");
        });
        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                replayCaptureEnabled: false,
                replayCaptureSinks: [$sink],
            ),
            $this->loop,
        );

        $this->await($client->connect());
        $sink->reset();

        $this->await($client->api('status'));
        $server->emitPlainEvent([
            'Event-Name' => 'CHANNEL_CREATE',
            'Unique-ID' => 'disabled-replay',
        ]);
        $this->runLoopFor(0.02);

        self::assertSame([], $sink->captured());

        $server->close();
    }

    public function testReplaySinkFailuresAreContainedAndDoNotStopOtherSinks(): void
    {
        $failingSink = new CollectingReplaySink(true);
        $collectingSink = new CollectingReplaySink();
        $server = $this->authenticatedServer();
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $server->writeApiResponse($connection, "+OK still-alive\n");
        });
        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                replayCaptureEnabled: true,
                replayCaptureSinks: [$failingSink, $collectingSink],
            ),
            $this->loop,
        );

        $this->await($client->connect());
        $collectingSink->reset();

        $reply = $this->await($client->api('status'));

        self::assertSame("+OK still-alive\n", $reply->body());
        self::assertCount(2, $collectingSink->captured());

        $server->close();
    }

    public function testAcceptedSubscriptionMutationEmitsReplayArtifact(): void
    {
        $sink = new CollectingReplaySink();
        $server = $this->authenticatedServer();
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain CHANNEL_CREATE', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });
        $client = $this->authenticatedClient($server, $sink);

        $this->await($client->subscriptions()->subscribe('CHANNEL_CREATE'));

        self::assertCount(2, $sink->captured());
        $dispatch = $sink->captured()[0];
        self::assertSame('subscription.mutate', $dispatch->derivedMetadata()['replay-artifact-name']);
        $payload = $this->decodePayload($dispatch->rawPayload());
        self::assertSame('subscribe', $payload['mutation_kind']);
        self::assertSame([], $payload['desired_state_before']['event_names']);
        self::assertSame(['CHANNEL_CREATE'], $payload['desired_state_after']['event_names']);
        self::assertFalse($payload['noop']);

        $server->close();
    }

    public function testAcceptedFilterMutationEmitsReplayArtifact(): void
    {
        $sink = new CollectingReplaySink();
        $server = $this->authenticatedServer();
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('filter Unique-ID uuid-1', $command);
            $server->writeCommandReply($connection, '+OK filter added');
        });
        $client = $this->authenticatedClient($server, $sink);

        $this->await($client->subscriptions()->addFilter('Unique-ID', 'uuid-1'));

        self::assertCount(2, $sink->captured());
        $dispatch = $sink->captured()[0];
        self::assertSame('filter.mutate', $dispatch->derivedMetadata()['replay-artifact-name']);
        $payload = $this->decodePayload($dispatch->rawPayload());
        self::assertSame('add', $payload['mutation_kind']);
        self::assertSame([], $payload['desired_state_before']);
        self::assertSame(
            [['headerName' => 'Unique-ID', 'headerValue' => 'uuid-1']],
            $payload['desired_state_after'],
        );
        self::assertFalse($payload['noop']);

        $server->close();
    }

    public function testNoopMutationsEmitNoReplayArtifacts(): void
    {
        $sink = new CollectingReplaySink();
        $server = $this->authenticatedServer();
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain CHANNEL_CREATE', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('filter Unique-ID uuid-1', $command);
            $server->writeCommandReply($connection, '+OK filter added');
        });
        $client = $this->authenticatedClient($server, $sink);

        $this->await($client->subscriptions()->subscribe('CHANNEL_CREATE'));
        $this->await($client->subscriptions()->addFilter('Unique-ID', 'uuid-1'));

        $sink->reset();

        $this->await($client->subscriptions()->subscribe('CHANNEL_CREATE'));
        $this->await($client->subscriptions()->unsubscribe('CHANNEL_ANSWER'));
        $this->await($client->subscriptions()->addFilter('Unique-ID', 'uuid-1'));
        $this->await($client->subscriptions()->removeFilter('Unique-ID', 'missing'));

        self::assertSame([], $sink->captured());

        $server->close();
    }

    public function testRejectedMutationWorkWhileUnauthenticatedOrRecoveringEmitsNoReplayDispatchArtifacts(): void
    {
        $sink = new CollectingReplaySink();

        $unauthenticatedServer = new ScriptedFakeEslServer($this->loop);
        $unauthenticatedClient = AsyncEslRuntime::make(
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $unauthenticatedServer->port(),
                password: 'ClueCon',
                replayCaptureEnabled: true,
                replayCaptureSinks: [$sink],
            ),
            $this->loop,
        );

        try {
            $this->await($unauthenticatedClient->subscriptions()->subscribe('CHANNEL_CREATE'));
            self::fail('Expected unauthenticated mutation to fail');
        } catch (ConnectionException $e) {
            self::assertSame('Runtime is not authenticated', $e->getMessage());
        }
        self::assertSame([], $sink->captured());
        $this->runLoopFor(0.01);
        $unauthenticatedServer->close();

        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient(
            $server,
            $sink,
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                retryPolicy: RetryPolicy::withMaxAttempts(2, 0.01),
                heartbeat: HeartbeatConfig::disabled(),
                replayCaptureEnabled: true,
                replayCaptureSinks: [$sink],
            ),
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

        $sink->reset();
        try {
            $this->await($client->subscriptions()->subscribe('CHANNEL_ANSWER'));
            self::fail('Expected recovery mutation to fail');
        } catch (ConnectionException $e) {
            self::assertSame('Runtime is not authenticated', $e->getMessage());
        }
        self::assertSame([], $sink->captured());

        $server->close();
    }

    public function testRejectedMutationWorkDuringOverloadOrDrainEmitsNoReplayDispatchArtifacts(): void
    {
        $sink = new CollectingReplaySink();
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient(
            $server,
            $sink,
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                heartbeat: HeartbeatConfig::disabled(),
                backpressure: BackpressureConfig::withLimit(1, 0.05),
                replayCaptureEnabled: true,
                replayCaptureSinks: [$sink],
            ),
        );

        $server->queueCommandHandler(static function (): void {
            // keep api inflight
        });
        $first = $client->api('status');
        try {
            $this->await($client->subscriptions()->subscribe('CHANNEL_CREATE'), 0.1);
            self::fail('Expected overload mutation to fail');
        } catch (BackpressureException) {
        }
        self::assertCount(1, $sink->captured());
        self::assertSame('api.dispatch', $sink->captured()[0]->derivedMetadata()['replay-artifact-name']);
        $server->writeApiResponse($server->activeConnection(), "+OK settle\n");
        $this->await($first);

        $sink->reset();
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('exit', $command);
            $server->writeCommandReply($connection, '+OK bye');
        });
        $disconnect = $client->disconnect();
        try {
            $this->await($client->subscriptions()->subscribe('CHANNEL_CREATE'), 0.1);
            self::fail('Expected drain mutation to fail');
        } catch (DrainException) {
        }
        self::assertSame([], $sink->captured());
        $this->await($disconnect);

        $server->close();
    }

    public function testReplayCaptureContinuesAcrossReconnectForLaterTraffic(): void
    {
        $sink = new CollectingReplaySink();
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient(
            $server,
            $sink,
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                retryPolicy: RetryPolicy::withMaxAttempts(2, 0.01),
                heartbeat: HeartbeatConfig::disabled(),
                replayCaptureEnabled: true,
                replayCaptureSinks: [$sink],
            ),
        );

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $server->writeApiResponse($connection, "+OK after-reconnect\n");
        });

        $server->closeActiveConnection();

        $this->waitUntil(
            fn (): bool => $client->health()->snapshot()->connectionState === ConnectionState::Authenticated
                && $server->connectionCount() === 2,
            0.4,
        );

        $sink->reset();
        $reply = $this->await($client->api('status'));

        self::assertSame("+OK after-reconnect\n", $reply->body());
        self::assertCount(2, $sink->captured());
        self::assertSame('2', $sink->captured()[0]->derivedMetadata()['runtime-connection-generation']);

        $server->close();
    }

    public function testReplaySinkFailureDoesNotBreakReconnectContinuityForLaterTraffic(): void
    {
        $failingSink = new CollectingReplaySink(true);
        $collectingSink = new CollectingReplaySink();
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient(
            $server,
            $collectingSink,
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

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $server->writeApiResponse($connection, "+OK after-reconnect\n");
        });

        $server->closeActiveConnection();

        $this->waitUntil(
            fn (): bool => $client->health()->snapshot()->connectionState === ConnectionState::Authenticated
                && $server->connectionCount() === 2,
            0.4,
        );

        $collectingSink->reset();
        $reply = $this->await($client->api('status'));

        self::assertSame("+OK after-reconnect\n", $reply->body());
        self::assertCount(2, $collectingSink->captured());
        self::assertSame('api.dispatch', $collectingSink->captured()[0]->derivedMetadata()['replay-artifact-name']);
        self::assertSame('2', $collectingSink->captured()[0]->derivedMetadata()['runtime-connection-generation']);

        $server->close();
    }

    public function testReplayHooksDoNotBypassOverloadOrDrainGating(): void
    {
        $sink = new CollectingReplaySink();
        $server = $this->authenticatedServer();
        $client = $this->authenticatedClient(
            $server,
            $sink,
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                heartbeat: HeartbeatConfig::disabled(),
                backpressure: BackpressureConfig::withLimit(1, 0.05),
                replayCaptureEnabled: true,
                replayCaptureSinks: [$sink],
            ),
        );

        $server->queueCommandHandler(static function (): void {
            // Keep the first API inflight.
        });

        $first = $client->api('status');

        try {
            $this->await($client->api('status'), 0.1);
            self::fail('Expected overload rejection');
        } catch (BackpressureException) {
        }

        self::assertCount(1, $sink->captured());
        self::assertSame('api.dispatch', $sink->captured()[0]->derivedMetadata()['replay-artifact-name']);

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('exit', $command);
            $server->writeCommandReply($connection, '+OK bye');
        });

        $disconnect = $client->disconnect();

        try {
            $this->await($client->api('status'), 0.1);
            self::fail('Expected drain rejection');
        } catch (DrainException) {
        }

        self::assertCount(1, $sink->captured());

        $server->writeApiResponse($server->activeConnection(), "+OK settle\n");
        $this->await($first);
        $this->await($disconnect);

        self::assertSame(ConnectionState::Closed, $client->health()->snapshot()->connectionState);

        $server->close();
    }

    public function testDrainWithPendingBgapiProducesNoCompletionArtifactAndStaysTerminal(): void
    {
        $jobUuid = 'job-drain-replay';
        $sink = new CollectingReplaySink();
        $server = $this->authenticatedServer();
        $server->queueCommandHandler(function ($connection, string $command) use ($server, $jobUuid): void {
            self::assertSame('bgapi status', $command);
            $server->writeBgapiAcceptedReply($connection, $jobUuid);
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('exit', $command);
            $server->writeCommandReply($connection, '+OK bye');
        });
        $client = $this->authenticatedClient(
            $server,
            $sink,
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                heartbeat: HeartbeatConfig::disabled(),
                backpressure: BackpressureConfig::withLimit(4, 0.05),
                commandTimeout: CommandTimeoutConfig::default()->withBgapiOrphanTimeout(0.5),
                replayCaptureEnabled: true,
                replayCaptureSinks: [$sink],
            ),
        );

        $handle = $client->bgapi('status');
        $this->waitUntil(fn (): bool => $handle->jobUuid() === $jobUuid, 0.2);

        try {
            $this->await($client->disconnect(), 0.2);
            $this->await($handle->promise(), 0.2);
            self::fail('Expected pending bgapi to terminate during drain');
        } catch (DrainException) {
        }

        $artifactNames = array_map(
            static fn ($envelope): string => $envelope->derivedMetadata()['replay-artifact-name'],
            $sink->captured(),
        );

        self::assertContains('bgapi.dispatch', $artifactNames);
        self::assertContains('bgapi.ack', $artifactNames);
        self::assertNotContains('bgapi.complete', $artifactNames);
        self::assertSame(ConnectionState::Closed, $client->health()->snapshot()->connectionState);

        $server->close();
    }

    public function testMalformedInboundTrafficWithReplayEnabledDoesNotCorruptRuntimeBehavior(): void
    {
        $sink = new CollectingReplaySink();
        $server = $this->authenticatedServer();
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $server->writeApiResponse($connection, "+OK still-usable\n");
        });
        $client = $this->authenticatedClient($server, $sink);
        $sink->reset();

        $server->writeRawFrame($server->activeConnection(), "Content-Type: command/reply\n\n");
        $this->runLoopFor(0.02);

        $reply = $this->await($client->api('status'));

        self::assertSame("+OK still-usable\n", $reply->body());
        self::assertCount(2, $sink->captured());
        self::assertSame(ConnectionState::Authenticated, $client->health()->snapshot()->connectionState);

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

    private function authenticatedClient(
        ScriptedFakeEslServer $server,
        CollectingReplaySink $sink,
        ?RuntimeConfig $config = null,
    ) {
        $client = AsyncEslRuntime::make(
            $config ?? RuntimeConfig::create(
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

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $payload): array
    {
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
