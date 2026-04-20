<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Integration;

use Apntalk\EslCore\Contracts\InboundPipelineInterface;
use Apntalk\EslCore\Correlation\EventEnvelope;
use Apntalk\EslCore\Events\BackgroundJobEvent;
use Apntalk\EslCore\Inbound\DecodedInboundMessage;
use Apntalk\EslCore\Inbound\InboundPipeline;
use Apntalk\EslCore\Replies\ApiReply;
use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Bgapi\BgapiJobHandle;
use Apntalk\EslReact\Config\CommandTimeoutConfig;
use Apntalk\EslReact\Config\HeartbeatConfig;
use Apntalk\EslReact\Config\RetryPolicy;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Exceptions\AuthenticationException;
use Apntalk\EslReact\Exceptions\CommandTimeoutException;
use Apntalk\EslReact\Exceptions\ConnectionException;
use Apntalk\EslReact\Runner\PreparedRuntimeBootstrapInput;
use Apntalk\EslReact\Runner\PreparedRuntimeInput;
use Apntalk\EslReact\Runner\RuntimeFeedbackSnapshot;
use Apntalk\EslReact\Runner\RuntimeLifecycleSnapshot;
use Apntalk\EslReact\Runner\RuntimeReconnectPhase;
use Apntalk\EslReact\Runner\RuntimeReconnectStopReason;
use Apntalk\EslReact\Runner\RuntimeRunnerState;
use Apntalk\EslReact\Runner\RuntimeSessionContext;
use Apntalk\EslReact\Runner\RuntimeStatusPhase;
use Apntalk\EslReact\Session\SessionState;
use Apntalk\EslReact\Tests\FakeServer\ScriptedFakeEslServer;
use Apntalk\EslReact\Tests\Support\AsyncTestCase;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;
use RuntimeException;

final class RuntimeRunnerIntegrationTest extends AsyncTestCase
{
    /**
     * @return array{
     *   runner: string,
     *   connection: ?string,
     *   session: ?string,
     *   live: bool,
     *   reconnecting: bool,
     *   draining: bool,
     *   stopped: bool,
     *   failed: bool,
     *   reconnectAttempts: int
     * }
     */
    private function lifecycleMarker(RuntimeLifecycleSnapshot $snapshot): array
    {
        return [
            'runner' => $snapshot->runnerState->value,
            'connection' => $snapshot->connectionState()?->value,
            'session' => $snapshot->sessionState()?->value,
            'live' => $snapshot->isLive(),
            'reconnecting' => $snapshot->isReconnecting(),
            'draining' => $snapshot->isDraining(),
            'stopped' => $snapshot->isStopped(),
            'failed' => $snapshot->isFailed(),
            'reconnectAttempts' => $snapshot->reconnectAttempts(),
        ];
    }

    public function testRunnerConsumesPreparedInputStartsRuntimeAndTransitionsToRunning(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $this->loop->addTimer(0.01, function () use ($connection, $server): void {
                $server->writeCommandReply($connection, '+OK accepted');
            });
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $server->writeApiResponse($connection, "+OK runner-live\n");
        });

        $input = new PreparedRuntimeInput(
            endpoint: sprintf('tcp://127.0.0.1:%d', $server->port()),
            runtimeConfig: RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
            ),
        );

        $handle = AsyncEslRuntime::runner()->run($input, $this->loop);

        self::assertSame('tcp://127.0.0.1:' . $server->port(), $handle->endpoint());
        self::assertSame(RuntimeRunnerState::Starting, $handle->state());
        self::assertTrue($handle->lifecycleSnapshot()->isStarting());

        $this->await($handle->startupPromise());

        self::assertSame(RuntimeRunnerState::Running, $handle->state());
        self::assertNull($handle->startupError());

        $lifecycle = $handle->lifecycleSnapshot();
        self::assertInstanceOf(RuntimeLifecycleSnapshot::class, $lifecycle);
        self::assertSame(RuntimeRunnerState::Running, $lifecycle->runnerState);
        self::assertSame(ConnectionState::Authenticated, $lifecycle->connectionState());
        self::assertSame(SessionState::Active, $lifecycle->sessionState());
        self::assertTrue($lifecycle->isConnected());
        self::assertTrue($lifecycle->isAuthenticated());
        self::assertTrue($lifecycle->isLive());
        self::assertFalse($lifecycle->isStarting());
        self::assertFalse($lifecycle->isReconnecting());
        self::assertFalse($lifecycle->isDraining());
        self::assertFalse($lifecycle->isStopped());
        self::assertFalse($lifecycle->isFailed());
        self::assertNull($lifecycle->startupErrorClass);
        self::assertNull($lifecycle->startupErrorMessage);

        $reply = $this->await($handle->client()->api('status'));

        self::assertInstanceOf(ApiReply::class, $reply);
        self::assertSame("+OK runner-live\n", $reply->body());

        $snapshot = $handle->client()->health()->snapshot();
        self::assertSame(ConnectionState::Authenticated, $snapshot->connectionState);
        self::assertSame(SessionState::Active, $snapshot->sessionState);
        self::assertTrue($snapshot->isLive);

        $server->closeActiveConnection();
        $server->close();
    }

    public function testRunnerLifecycleChangeListenerEmitsCurrentReconnectDrainAndStopSnapshots(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $this->loop->addTimer(0.05, function () use ($connection, $server): void {
                $server->writeCommandReply($connection, '+OK accepted');
            });
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $this->loop->addTimer(0.12, function () use ($connection, $server): void {
                $server->writeCommandReply($connection, '+OK accepted');
            });
        });

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeInput(
                endpoint: sprintf('tcp://127.0.0.1:%d', $server->port()),
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                    retryPolicy: RetryPolicy::withMaxAttempts(2, 0.2),
                    heartbeat: HeartbeatConfig::disabled(),
                ),
            ),
            $this->loop,
        );

        $markers = [];
        $handle->onLifecycleChange(function (RuntimeLifecycleSnapshot $snapshot) use (&$markers): void {
            $markers[] = $this->lifecycleMarker($snapshot);
        });

        self::assertSame('starting', $markers[0]['runner']);
        self::assertContains($markers[0]['connection'], ['disconnected', 'connecting']);
        self::assertSame('not_started', $markers[0]['session']);

        $this->await($handle->startupPromise());

        $this->waitUntil(
            function () use (&$markers): bool {
                return array_filter(
                    $markers,
                    static fn(array $marker): bool => $marker['runner'] === 'running'
                        && $marker['connection'] === 'authenticated'
                        && $marker['session'] === 'active'
                        && $marker['live'] === true
                ) !== [];
            },
            0.2,
        );

        $beforeTransportLossMarkerCount = count($markers);
        $server->closeActiveConnection();

        $this->waitUntil(
            function () use (&$markers): bool {
                return array_filter(
                    $markers,
                    static fn(array $marker): bool => $marker['connection'] === 'reconnecting'
                        && $marker['session'] === 'disconnected'
                        && $marker['reconnecting'] === true
                        && $marker['draining'] === false
                ) !== [];
            },
            0.7,
        );

        foreach (array_slice($markers, $beforeTransportLossMarkerCount) as $marker) {
            if ($marker['connection'] === 'reconnecting') {
                break;
            }

            self::assertFalse(
                $marker['connection'] === 'disconnected'
                && $marker['reconnecting'] === false
                && $marker['draining'] === false
                && $marker['stopped'] === false
                && $marker['failed'] === false,
                'Unexpected transport loss must not emit a non-terminal disconnected lifecycle marker before reconnecting',
            );
        }

        $this->waitUntil(
            function () use (&$markers): bool {
                return count(array_filter(
                    $markers,
                    static fn(array $marker): bool => $marker['runner'] === 'running'
                        && $marker['connection'] === 'authenticated'
                        && $marker['session'] === 'active'
                        && $marker['live'] === true
                )) >= 2;
            },
            1.0,
        );

        $this->await($handle->client()->disconnect());

        $this->waitUntil(
            function () use (&$markers): bool {
                return array_filter(
                    $markers,
                    static fn(array $marker): bool => $marker['connection'] === 'draining'
                        && $marker['draining'] === true
                        && $marker['reconnecting'] === false
                        && $marker['stopped'] === false
                ) !== [];
            },
            0.2,
        );

        $this->waitUntil(
            function () use (&$markers): bool {
                return array_filter(
                    $markers,
                    static fn(array $marker): bool => $marker['connection'] === 'closed'
                        && $marker['session'] === 'disconnected'
                        && $marker['draining'] === false
                        && $marker['stopped'] === true
                        && $marker['reconnecting'] === false
                ) !== [];
            },
            0.2,
        );

        $server->close();
    }

    public function testRunnerConsumesPreparedBootstrapInputAndUsesPreparedConnector(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $server->writeApiResponse($connection, "+OK prepared-bootstrap\n");
        });

        $connector = new class ($this->loop, $server->address()) implements ConnectorInterface {
            /** @var list<string> */
            public array $requestedUris = [];

            public function __construct(
                private readonly LoopInterface $loop,
                private readonly string $targetUri,
            ) {}

            public function connect($uri)
            {
                $this->requestedUris[] = (string) $uri;

                return (new Connector([], $this->loop))->connect($this->targetUri);
            }
        };

        $pipeline = new InboundPipeline();
        $pipeline->push("Content-Type: auth/request\n");
        self::assertGreaterThan(0, $pipeline->bufferedByteCount());

        $context = new RuntimeSessionContext(
            sessionId: 'runner-session-1',
            metadata: ['pbx' => 'node-a', 'attempt' => 1],
        );
        $input = new PreparedRuntimeBootstrapInput(
            endpoint: 'tcp://prepared-bootstrap',
            runtimeConfig: RuntimeConfig::create(
                host: 'config-only.invalid',
                port: 65000,
                password: 'ClueCon',
            ),
            connector: $connector,
            inboundPipeline: $pipeline,
            sessionContext: $context,
            dialUri: 'tls://pbx.example.test:7443',
        );

        $handle = AsyncEslRuntime::runner()->run($input, $this->loop);

        self::assertSame(['tls://pbx.example.test:7443'], $connector->requestedUris);
        self::assertSame(0, $pipeline->bufferedByteCount());
        self::assertSame($context, $handle->sessionContext());

        $this->await($handle->startupPromise());

        self::assertSame(RuntimeRunnerState::Running, $handle->state());

        $reply = $this->await($handle->client()->api('status'));
        self::assertInstanceOf(ApiReply::class, $reply);
        self::assertSame("+OK prepared-bootstrap\n", $reply->body());

        $server->close();
    }

    public function testRunnerUsesPreparedInboundPipelineForLiveIngress(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $server->writeApiResponse($connection, "+OK pipeline-live\n");
        });

        $pipeline = new class implements InboundPipelineInterface {
            public int $resetCount = 0;
            public int $pushCount = 0;
            public int $drainCount = 0;
            /** @var list<DecodedInboundMessage> */
            public array $decoded = [];

            private readonly InboundPipeline $inner;

            public function __construct()
            {
                $this->inner = InboundPipeline::withDefaults();
            }

            public function push(string $bytes): void
            {
                $this->pushCount++;
                $this->inner->push($bytes);
            }

            public function drain(): array
            {
                $this->drainCount++;
                $decoded = $this->inner->drain();
                $this->decoded = [...$this->decoded, ...$decoded];

                return $decoded;
            }

            public function decode(string $bytes): array
            {
                $this->push($bytes);

                return $this->drain();
            }

            public function finish(): void
            {
                $this->inner->finish();
            }

            public function reset(): void
            {
                $this->resetCount++;
                $this->inner->reset();
            }

            public function bufferedByteCount(): int
            {
                return $this->inner->bufferedByteCount();
            }
        };

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeBootstrapInput(
                endpoint: 'worker://node-a/prepared-pipeline',
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                ),
                connector: new Connector([], $this->loop),
                inboundPipeline: $pipeline,
                sessionContext: new RuntimeSessionContext('runner-session-pipeline'),
            ),
            $this->loop,
        );

        $this->await($handle->startupPromise());
        $reply = $this->await($handle->client()->api('status'));

        self::assertInstanceOf(ApiReply::class, $reply);
        self::assertSame("+OK pipeline-live\n", $reply->body());
        self::assertGreaterThanOrEqual(2, $pipeline->resetCount);
        self::assertGreaterThan(0, $pipeline->pushCount);
        self::assertGreaterThan(0, $pipeline->drainCount);
        self::assertNotEmpty($pipeline->decoded);
        self::assertNotSame([], array_filter(
            $pipeline->decoded,
            static fn(DecodedInboundMessage $message): bool => $message->isServerAuthRequest(),
        ));
        self::assertNotSame([], array_filter(
            $pipeline->decoded,
            static fn(DecodedInboundMessage $message): bool => $message->isReply(),
        ));

        $server->close();
    }

    public function testRunnerFeedbackSnapshotPackagesIdentityAndHealthTruth(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('bgapi status', $command);
            $server->writeBgapiAcceptedReply($connection, 'job-feedback-1');
        });

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeBootstrapInput(
                endpoint: 'worker://node-a/session-identity',
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                ),
                connector: new Connector([], $this->loop),
                inboundPipeline: new InboundPipeline(),
                sessionContext: new RuntimeSessionContext(
                    'runtime-session-1',
                    metadata: ['pbx_node' => 'node-a'],
                    workerSessionId: 'worker-session-1',
                    connectionProfile: 'profile-a',
                    providerIdentity: 'provider-a',
                    connectorIdentity: 'connector-a',
                ),
            ),
            $this->loop,
        );

        $this->await($handle->startupPromise());

        $feedback = $handle->feedbackSnapshot();

        self::assertInstanceOf(RuntimeFeedbackSnapshot::class, $feedback);
        self::assertSame('worker://node-a/session-identity', $feedback->endpoint);
        self::assertSame('runtime-session-1', $feedback->identity()?->sessionId());
        self::assertSame('worker-session-1', $feedback->identity()?->workerSessionId());
        self::assertSame('profile-a', $feedback->identity()?->connectionProfile());
        self::assertSame('provider-a', $feedback->identity()?->providerIdentity());
        self::assertSame('connector-a', $feedback->identity()?->connectorIdentity());
        self::assertSame([
            'runtime_session_id' => 'runtime-session-1',
            'worker_session_id' => 'worker-session-1',
            'connection_profile' => 'profile-a',
            'provider_identity' => 'provider-a',
            'connector_identity' => 'connector-a',
            'pbx_node' => 'node-a',
        ], $feedback->identity()?->identityMetadata());
        self::assertFalse($feedback->isDraining());
        self::assertSame([], $feedback->activeSubscriptions());
        self::assertFalse($feedback->subscriptionState()->subscribeAll);
        self::assertSame([], $feedback->subscriptionState()->eventNames);
        self::assertSame([], $feedback->subscriptionState()->filters);
        self::assertFalse($feedback->observedSubscriptionState()->subscribeAll);
        self::assertSame([], $feedback->observedSubscriptionState()->eventNames);
        self::assertSame([], $feedback->observedSubscriptionState()->filters);
        self::assertTrue($feedback->observedSubscriptionState()->isCurrentForActiveSession);
        self::assertSame(RuntimeReconnectPhase::Idle, $feedback->reconnectState()->phase);
        self::assertNull($feedback->reconnectState()->attemptNumber);
        self::assertFalse($feedback->reconnectState()->isRetryScheduled);
        self::assertNull($feedback->reconnectState()->backoffDelaySeconds);
        self::assertNull($feedback->reconnectState()->nextRetryDueAtMicros);
        self::assertNull($feedback->reconnectState()->remainingDelaySeconds);
        self::assertFalse($feedback->reconnectState()->isTerminallyStopped);
        self::assertFalse($feedback->reconnectState()->isRetryExhausted);
        self::assertFalse($feedback->reconnectState()->requiresExternalIntervention);
        self::assertFalse($feedback->reconnectState()->isFailClosedTerminalState);
        self::assertNull($feedback->reconnectState()->terminalStopReason);
        self::assertNull($feedback->reconnectState()->terminalStoppedAtMicros);
        self::assertNull($feedback->reconnectState()->lastRetryAttemptStartedAtMicros);
        self::assertNull($feedback->reconnectState()->lastScheduledRetryDueAtMicros);
        self::assertNull($feedback->reconnectState()->lastScheduledBackoffDelaySeconds);
        self::assertNull($feedback->reconnectState()->terminalStoppedDurationSeconds);
        self::assertSame(0, $feedback->reconnectAttempts());
        self::assertFalse($feedback->isReconnectRetryScheduled());
        self::assertSame(0, $feedback->activeApiCommandCount());
        self::assertSame(0, $feedback->queuedApiCommandCount());

        $bgapiHandle = $handle->client()->bgapi('status');
        $bgapiHandle->promise()->then(static fn(): null => null, static fn(): null => null);

        $this->waitUntil(
            fn(): bool => $handle->feedbackSnapshot()->pendingBgapiJobCount() === 1,
            0.2,
        );

        $pendingFeedback = $handle->feedbackSnapshot();

        self::assertSame(1, $pendingFeedback->pendingBgapiJobCount());
        self::assertGreaterThanOrEqual(1, $pendingFeedback->totalInflightCount());
        self::assertSame(
            $pendingFeedback->inflightCommandCount() + $pendingFeedback->pendingBgapiJobCount(),
            $pendingFeedback->totalInflightCount(),
        );
        self::assertGreaterThanOrEqual(0, $pendingFeedback->activeApiCommandCount());
        self::assertGreaterThanOrEqual(0, $pendingFeedback->queuedApiCommandCount());
        self::assertSame(
            $pendingFeedback->activeApiCommandCount() + $pendingFeedback->queuedApiCommandCount(),
            $pendingFeedback->inflightCommandCount(),
        );
        self::assertSame(ConnectionState::Authenticated, $pendingFeedback->connectionState());
        self::assertSame(SessionState::Active, $pendingFeedback->sessionState());
        self::assertTrue($pendingFeedback->isLive());

        $this->await($handle->client()->disconnect());
        $server->close();
    }

    public function testRunnerFeedbackSnapshotExposesDesiredSubscriptionStateAndRetrySchedulingTruth(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain all', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('filter Unique-ID uuid-1', $command);
            $server->writeCommandReply($connection, '+OK filter added');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain all', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('filter Unique-ID uuid-1', $command);
            $server->writeCommandReply($connection, '+OK filter added');
        });

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeBootstrapInput(
                endpoint: 'worker://node-a/session-feedback',
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                    retryPolicy: RetryPolicy::withMaxAttempts(2, 0.05),
                    heartbeat: HeartbeatConfig::disabled(),
                ),
                connector: new Connector([], $this->loop),
                inboundPipeline: new InboundPipeline(),
                sessionContext: new RuntimeSessionContext('runtime-session-2'),
            ),
            $this->loop,
        );

        $this->await($handle->startupPromise());
        $this->await($handle->client()->subscriptions()->subscribeAll());
        $this->await($handle->client()->subscriptions()->addFilter('Unique-ID', 'uuid-1'));

        $desired = $handle->feedbackSnapshot()->subscriptionState();

        self::assertTrue($desired->subscribeAll);
        self::assertSame([], $desired->eventNames);
        self::assertSame([
            ['headerName' => 'Unique-ID', 'headerValue' => 'uuid-1'],
        ], $desired->filters);
        self::assertSame([], $handle->feedbackSnapshot()->activeSubscriptions());
        self::assertTrue($handle->feedbackSnapshot()->observedSubscriptionState()->subscribeAll);
        self::assertSame([], $handle->feedbackSnapshot()->observedSubscriptionState()->eventNames);
        self::assertSame([
            ['headerName' => 'Unique-ID', 'headerValue' => 'uuid-1'],
        ], $handle->feedbackSnapshot()->observedSubscriptionState()->filters);
        self::assertTrue($handle->feedbackSnapshot()->observedSubscriptionState()->isCurrentForActiveSession);

        $server->closeActiveConnection();

        $this->waitUntil(
            fn(): bool => $handle->feedbackSnapshot()->isReconnectRetryScheduled(),
            0.3,
        );

        $reconnecting = $handle->feedbackSnapshot();
        self::assertSame(ConnectionState::Reconnecting, $reconnecting->connectionState());
        self::assertFalse($reconnecting->isDraining());
        self::assertTrue($reconnecting->isReconnectRetryScheduled());
        self::assertSame(RuntimeReconnectPhase::WaitingToRetry, $reconnecting->reconnectState()->phase);
        self::assertSame(1, $reconnecting->reconnectState()->attemptNumber);
        self::assertTrue($reconnecting->reconnectState()->isRetryScheduled);
        self::assertGreaterThan(0.0, $reconnecting->reconnectState()->backoffDelaySeconds ?? 0.0);
        self::assertNotNull($reconnecting->reconnectState()->nextRetryDueAtMicros);
        self::assertNotNull($reconnecting->reconnectState()->remainingDelaySeconds);
        self::assertFalse($reconnecting->reconnectState()->isTerminallyStopped);
        self::assertFalse($reconnecting->reconnectState()->isRetryExhausted);
        self::assertFalse($reconnecting->reconnectState()->requiresExternalIntervention);
        self::assertFalse($reconnecting->reconnectState()->isFailClosedTerminalState);
        self::assertNull($reconnecting->reconnectState()->terminalStopReason);
        self::assertNull($reconnecting->reconnectState()->terminalStoppedAtMicros);
        self::assertNull($reconnecting->reconnectState()->lastRetryAttemptStartedAtMicros);
        self::assertNotNull($reconnecting->reconnectState()->lastScheduledRetryDueAtMicros);
        self::assertGreaterThan(0.0, $reconnecting->reconnectState()->lastScheduledBackoffDelaySeconds ?? 0.0);
        self::assertNull($reconnecting->reconnectState()->terminalStoppedDurationSeconds);
        self::assertTrue($reconnecting->subscriptionState()->subscribeAll);
        self::assertSame([
            ['headerName' => 'Unique-ID', 'headerValue' => 'uuid-1'],
        ], $reconnecting->subscriptionState()->filters);
        self::assertFalse($reconnecting->observedSubscriptionState()->subscribeAll);
        self::assertSame([], $reconnecting->observedSubscriptionState()->eventNames);
        self::assertSame([], $reconnecting->observedSubscriptionState()->filters);
        self::assertFalse($reconnecting->observedSubscriptionState()->isCurrentForActiveSession);

        $this->waitUntil(
            fn(): bool => $handle->feedbackSnapshot()->connectionState() === ConnectionState::Authenticated
                && $handle->feedbackSnapshot()->isReconnectRetryScheduled() === false,
            1.0,
        );

        $recovered = $handle->feedbackSnapshot();
        self::assertSame(ConnectionState::Authenticated, $recovered->connectionState());
        self::assertFalse($recovered->isReconnectRetryScheduled());
        self::assertSame(RuntimeReconnectPhase::Idle, $recovered->reconnectState()->phase);
        self::assertNull($recovered->reconnectState()->attemptNumber);
        self::assertFalse($recovered->reconnectState()->isRetryScheduled);
        self::assertNull($recovered->reconnectState()->backoffDelaySeconds);
        self::assertNull($recovered->reconnectState()->nextRetryDueAtMicros);
        self::assertNull($recovered->reconnectState()->remainingDelaySeconds);
        self::assertFalse($recovered->reconnectState()->isTerminallyStopped);
        self::assertFalse($recovered->reconnectState()->isRetryExhausted);
        self::assertFalse($recovered->reconnectState()->requiresExternalIntervention);
        self::assertFalse($recovered->reconnectState()->isFailClosedTerminalState);
        self::assertNull($recovered->reconnectState()->terminalStopReason);
        self::assertNull($recovered->reconnectState()->terminalStoppedAtMicros);
        self::assertNotNull($recovered->reconnectState()->lastRetryAttemptStartedAtMicros);
        self::assertNotNull($recovered->reconnectState()->lastScheduledRetryDueAtMicros);
        self::assertGreaterThan(0.0, $recovered->reconnectState()->lastScheduledBackoffDelaySeconds ?? 0.0);
        self::assertNull($recovered->reconnectState()->terminalStoppedDurationSeconds);
        self::assertTrue($recovered->subscriptionState()->subscribeAll);
        self::assertSame([
            ['headerName' => 'Unique-ID', 'headerValue' => 'uuid-1'],
        ], $recovered->subscriptionState()->filters);
        self::assertTrue($recovered->observedSubscriptionState()->subscribeAll);
        self::assertSame([], $recovered->observedSubscriptionState()->eventNames);
        self::assertSame([
            ['headerName' => 'Unique-ID', 'headerValue' => 'uuid-1'],
        ], $recovered->observedSubscriptionState()->filters);
        self::assertTrue($recovered->observedSubscriptionState()->isCurrentForActiveSession);

        $this->await($handle->client()->disconnect());
        $server->close();
    }

    public function testRunnerDisconnectWhileRetryScheduledCancelsReconnectWithoutDrain(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeInput(
                endpoint: sprintf('tcp://127.0.0.1:%d', $server->port()),
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                    retryPolicy: RetryPolicy::withMaxAttempts(2, 0.05),
                    heartbeat: HeartbeatConfig::disabled(),
                ),
            ),
            $this->loop,
        );

        $markers = [];
        $handle->onLifecycleChange(function (RuntimeLifecycleSnapshot $snapshot) use (&$markers): void {
            $markers[] = $this->lifecycleMarker($snapshot);
        });

        $this->await($handle->startupPromise());
        $server->closeActiveConnection();

        $this->waitUntil(
            fn(): bool => $handle->feedbackSnapshot()->reconnectState()->phase === RuntimeReconnectPhase::WaitingToRetry,
            0.2,
        );

        $scheduled = $handle->feedbackSnapshot();
        self::assertSame(ConnectionState::Reconnecting, $scheduled->connectionState());
        self::assertTrue($scheduled->isReconnectRetryScheduled());
        self::assertFalse($scheduled->isDraining());
        self::assertSame(1, $server->connectionCount());

        $markerCountBeforeShutdown = count($markers);
        $this->await($handle->client()->disconnect());
        $this->runLoopFor(0.08);

        $closed = $handle->feedbackSnapshot();
        self::assertSame(ConnectionState::Closed, $closed->connectionState());
        self::assertSame(SessionState::Disconnected, $closed->sessionState());
        self::assertFalse($closed->isReconnectRetryScheduled());
        self::assertSame(RuntimeReconnectPhase::Idle, $closed->reconnectState()->phase);
        self::assertTrue($closed->reconnectState()->isTerminallyStopped);
        self::assertFalse($closed->reconnectState()->isRetryExhausted);
        self::assertTrue($closed->reconnectState()->requiresExternalIntervention);
        self::assertFalse($closed->reconnectState()->isFailClosedTerminalState);
        self::assertSame(RuntimeReconnectStopReason::ExplicitShutdown, $closed->reconnectState()->terminalStopReason);
        self::assertSame(1, $server->connectionCount());

        foreach (array_slice($markers, $markerCountBeforeShutdown) as $marker) {
            self::assertFalse($marker['draining'], 'Shutdown during a pending retry must not enter drain mode');
        }

        $server->close();
    }

    public function testRunnerFeedbackSnapshotExposesReconnectPhaseAndTimingDetail(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain all', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('filter Unique-ID uuid-reconnect-phase', $command);
            $server->writeCommandReply($connection, '+OK filter added');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $this->loop->addTimer(0.02, function () use ($connection, $server): void {
                $server->writeCommandReply($connection, '+OK accepted');
            });
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain all', $command);
            $this->loop->addTimer(0.02, function () use ($connection, $server): void {
                $server->writeCommandReply($connection, '+OK event listener enabled plain');
            });
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('filter Unique-ID uuid-reconnect-phase', $command);
            $this->loop->addTimer(0.02, function () use ($connection, $server): void {
                $server->writeCommandReply($connection, '+OK filter added');
            });
        });

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeBootstrapInput(
                endpoint: 'worker://node-a/session-reconnect-phase',
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                    retryPolicy: RetryPolicy::withMaxAttempts(2, 0.1),
                    heartbeat: HeartbeatConfig::disabled(),
                ),
                connector: new Connector([], $this->loop),
                inboundPipeline: new InboundPipeline(),
                sessionContext: new RuntimeSessionContext('runtime-session-3'),
            ),
            $this->loop,
        );

        $this->await($handle->startupPromise());
        $this->await($handle->client()->subscriptions()->subscribeAll());
        $this->await($handle->client()->subscriptions()->addFilter('Unique-ID', 'uuid-reconnect-phase'));

        $server->closeActiveConnection();

        $this->waitUntil(
            fn(): bool => $handle->feedbackSnapshot()->reconnectState()->phase === RuntimeReconnectPhase::WaitingToRetry,
            0.3,
        );

        $waiting = $handle->feedbackSnapshot()->reconnectState();
        self::assertSame(RuntimeReconnectPhase::WaitingToRetry, $waiting->phase);
        self::assertSame(1, $waiting->attemptNumber);
        self::assertTrue($waiting->isRetryScheduled);
        self::assertEqualsWithDelta(0.1, $waiting->backoffDelaySeconds ?? 0.0, 0.02);
        self::assertNotNull($waiting->nextRetryDueAtMicros);
        self::assertNotNull($waiting->remainingDelaySeconds);
        self::assertGreaterThan(0.0, $waiting->remainingDelaySeconds ?? 0.0);
        self::assertLessThanOrEqual(($waiting->backoffDelaySeconds ?? 0.0) + 0.02, $waiting->remainingDelaySeconds ?? 0.0);
        self::assertFalse($waiting->isTerminallyStopped);
        self::assertFalse($waiting->isRetryExhausted);
        self::assertFalse($waiting->requiresExternalIntervention);
        self::assertFalse($waiting->isFailClosedTerminalState);
        self::assertNull($waiting->terminalStopReason);
        self::assertNull($waiting->terminalStoppedAtMicros);
        self::assertNull($waiting->lastRetryAttemptStartedAtMicros);
        self::assertNotNull($waiting->lastScheduledRetryDueAtMicros);
        self::assertEqualsWithDelta(
            $waiting->nextRetryDueAtMicros ?? 0.0,
            $waiting->lastScheduledRetryDueAtMicros ?? 0.0,
            1000.0,
        );
        self::assertEqualsWithDelta(0.1, $waiting->lastScheduledBackoffDelaySeconds ?? 0.0, 0.02);
        self::assertNull($waiting->terminalStoppedDurationSeconds);

        $this->waitUntil(
            fn(): bool => $handle->feedbackSnapshot()->reconnectState()->phase === RuntimeReconnectPhase::AttemptingReconnect,
            0.5,
        );

        $attempting = $handle->feedbackSnapshot()->reconnectState();
        self::assertSame(RuntimeReconnectPhase::AttemptingReconnect, $attempting->phase);
        self::assertSame(1, $attempting->attemptNumber);
        self::assertFalse($attempting->isRetryScheduled);
        self::assertEqualsWithDelta(0.1, $attempting->backoffDelaySeconds ?? 0.0, 0.02);
        self::assertNull($attempting->nextRetryDueAtMicros);
        self::assertNull($attempting->remainingDelaySeconds);
        self::assertFalse($attempting->isTerminallyStopped);
        self::assertFalse($attempting->isRetryExhausted);
        self::assertFalse($attempting->requiresExternalIntervention);
        self::assertFalse($attempting->isFailClosedTerminalState);
        self::assertNull($attempting->terminalStopReason);
        self::assertNull($attempting->terminalStoppedAtMicros);
        self::assertNotNull($attempting->lastRetryAttemptStartedAtMicros);
        self::assertNotNull($attempting->lastScheduledRetryDueAtMicros);
        self::assertEqualsWithDelta(0.1, $attempting->lastScheduledBackoffDelaySeconds ?? 0.0, 0.02);
        self::assertNull($attempting->terminalStoppedDurationSeconds);

        $this->waitUntil(
            fn(): bool => $handle->feedbackSnapshot()->reconnectState()->phase === RuntimeReconnectPhase::RestoringSession,
            0.5,
        );

        $restoring = $handle->feedbackSnapshot()->reconnectState();
        self::assertSame(RuntimeReconnectPhase::RestoringSession, $restoring->phase);
        self::assertSame(1, $restoring->attemptNumber);
        self::assertFalse($restoring->isRetryScheduled);
        self::assertEqualsWithDelta(0.1, $restoring->backoffDelaySeconds ?? 0.0, 0.02);
        self::assertNull($restoring->nextRetryDueAtMicros);
        self::assertNull($restoring->remainingDelaySeconds);
        self::assertFalse($restoring->isTerminallyStopped);
        self::assertFalse($restoring->isRetryExhausted);
        self::assertFalse($restoring->requiresExternalIntervention);
        self::assertFalse($restoring->isFailClosedTerminalState);
        self::assertNull($restoring->terminalStopReason);
        self::assertNull($restoring->terminalStoppedAtMicros);
        self::assertNotNull($restoring->lastRetryAttemptStartedAtMicros);
        self::assertNotNull($restoring->lastScheduledRetryDueAtMicros);
        self::assertEqualsWithDelta(0.1, $restoring->lastScheduledBackoffDelaySeconds ?? 0.0, 0.02);
        self::assertNull($restoring->terminalStoppedDurationSeconds);

        $this->waitUntil(
            fn(): bool => $handle->feedbackSnapshot()->reconnectState()->phase === RuntimeReconnectPhase::Idle
                && $handle->feedbackSnapshot()->connectionState() === ConnectionState::Authenticated,
            1.0,
        );

        $recovered = $handle->feedbackSnapshot()->reconnectState();
        self::assertSame(RuntimeReconnectPhase::Idle, $recovered->phase);
        self::assertNull($recovered->attemptNumber);
        self::assertFalse($recovered->isRetryScheduled);
        self::assertNull($recovered->backoffDelaySeconds);
        self::assertNull($recovered->nextRetryDueAtMicros);
        self::assertNull($recovered->remainingDelaySeconds);
        self::assertFalse($recovered->isTerminallyStopped);
        self::assertFalse($recovered->isRetryExhausted);
        self::assertFalse($recovered->requiresExternalIntervention);
        self::assertFalse($recovered->isFailClosedTerminalState);
        self::assertNull($recovered->terminalStopReason);
        self::assertNull($recovered->terminalStoppedAtMicros);
        self::assertNotNull($recovered->lastRetryAttemptStartedAtMicros);
        self::assertNotNull($recovered->lastScheduledRetryDueAtMicros);
        self::assertEqualsWithDelta(0.1, $recovered->lastScheduledBackoffDelaySeconds ?? 0.0, 0.02);
        self::assertNull($recovered->terminalStoppedDurationSeconds);

        $this->await($handle->client()->disconnect());
        $server->close();
    }

    public function testRunnerReusesPreparedDialUriForReconnectAttempts(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });

        $connector = new class ($this->loop, $server->address()) implements ConnectorInterface {
            /** @var list<string> */
            public array $requestedUris = [];

            public function __construct(
                private readonly LoopInterface $loop,
                private readonly string $targetUri,
            ) {}

            public function connect($uri)
            {
                $this->requestedUris[] = (string) $uri;

                return (new Connector([], $this->loop))->connect($this->targetUri);
            }
        };

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeBootstrapInput(
                endpoint: 'worker://node-a/session-1',
                runtimeConfig: RuntimeConfig::create(
                    host: 'config-only.invalid',
                    port: 65000,
                    password: 'ClueCon',
                    retryPolicy: RetryPolicy::withMaxAttempts(2, 0.05),
                    heartbeat: HeartbeatConfig::disabled(),
                ),
                connector: $connector,
                inboundPipeline: new InboundPipeline(),
                sessionContext: new RuntimeSessionContext('runner-session-2'),
                dialUri: 'tls://pbx.example.test:7443',
            ),
            $this->loop,
        );

        $this->await($handle->startupPromise());

        $server->closeActiveConnection();

        $this->waitUntil(
            fn(): bool => count($connector->requestedUris) >= 2
                && $handle->lifecycleSnapshot()->connectionState() === ConnectionState::Authenticated,
            0.5,
        );

        self::assertSame(
            ['tls://pbx.example.test:7443', 'tls://pbx.example.test:7443'],
            array_slice($connector->requestedUris, 0, 2),
        );

        $this->await($handle->client()->disconnect());
        $server->close();
    }

    public function testRunnerFailsClosedWhenReconnectRestoreReceivesServerErrorReply(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain all', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain all', $command);
            $server->writeCommandReply($connection, '-ERR restore denied');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain all', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeInput(
                endpoint: sprintf('tcp://127.0.0.1:%d', $server->port()),
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                    retryPolicy: RetryPolicy::withMaxAttempts(3, 0.05),
                    heartbeat: HeartbeatConfig::disabled(),
                ),
            ),
            $this->loop,
        );

        $markers = [];
        $handle->onLifecycleChange(function (RuntimeLifecycleSnapshot $snapshot) use (&$markers): void {
            $markers[] = $this->lifecycleMarker($snapshot);
        });

        $this->await($handle->startupPromise());
        $this->await($handle->client()->subscriptions()->subscribeAll());

        $initialStatus = $handle->statusSnapshot();
        $initialSuccessfulConnectAt = $initialStatus->lastSuccessfulConnectAtMicros;
        self::assertNotNull($initialSuccessfulConnectAt);

        $server->closeActiveConnection();
        $this->runLoopFor(1.0);

        self::assertSame(
            [
                'auth ClueCon',
                'event plain all',
                'auth ClueCon',
                'event plain all',
                'auth ClueCon',
                'event plain all',
            ],
            $server->receivedCommands(),
        );
        self::assertNotSame([], array_filter(
            $markers,
            static fn(array $marker): bool => $marker['runner'] === 'running'
                && $marker['connection'] !== 'authenticated'
                && $marker['live'] === false
                && $marker['draining'] === false,
        ));

        $recovered = $handle->statusSnapshot();
        self::assertSame(RuntimeStatusPhase::Active, $recovered->phase);
        self::assertFalse($recovered->isRecoveryInProgress);
        self::assertSame(ConnectionException::class, $recovered->lastFailureClass);
        self::assertSame('Reconnect restore command failed for event plain all: restore denied', $recovered->lastFailureMessage);
        self::assertSame(ConnectionException::class, $recovered->lastDisconnectReasonClass);
        self::assertSame('Reconnect restore command failed for event plain all: restore denied', $recovered->lastDisconnectReasonMessage);
        self::assertNotNull($recovered->lastSuccessfulConnectAtMicros);
        self::assertGreaterThan($initialSuccessfulConnectAt ?? 0.0, $recovered->lastSuccessfulConnectAtMicros ?? 0.0);
        self::assertSame(ConnectionState::Authenticated, $handle->feedbackSnapshot()->connectionState());
        self::assertSame(SessionState::Active, $handle->feedbackSnapshot()->sessionState());
        self::assertTrue($handle->feedbackSnapshot()->isLive());
        self::assertTrue($handle->feedbackSnapshot()->observedSubscriptionState()->subscribeAll);
        self::assertTrue($handle->feedbackSnapshot()->observedSubscriptionState()->isCurrentForActiveSession);

        $this->await($handle->client()->disconnect());
        $server->close();
    }

    public function testRunnerMarksFailedWhenStartupFails(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth wrong-password', $command);
            $server->writeCommandReply($connection, '-ERR invalid password');
        });

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeInput(
                endpoint: sprintf('tcp://127.0.0.1:%d', $server->port()),
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'wrong-password',
                ),
            ),
            $this->loop,
        );

        $markers = [];
        $handle->onLifecycleChange(function (RuntimeLifecycleSnapshot $snapshot) use (&$markers): void {
            $markers[] = $this->lifecycleMarker($snapshot);
        });

        try {
            $this->await($handle->startupPromise());
            self::fail('Expected startup to fail');
        } catch (AuthenticationException $e) {
            self::assertSame('invalid password', $e->getMessage());
        }

        self::assertSame(RuntimeRunnerState::Failed, $handle->state());
        self::assertInstanceOf(AuthenticationException::class, $handle->startupError());

        $lifecycle = $handle->lifecycleSnapshot();
        self::assertSame(RuntimeRunnerState::Failed, $lifecycle->runnerState);
        self::assertTrue($lifecycle->isFailed());
        self::assertSame(AuthenticationException::class, $lifecycle->startupErrorClass);
        self::assertSame('invalid password', $lifecycle->startupErrorMessage);
        self::assertSame(ConnectionState::Disconnected, $lifecycle->connectionState());
        self::assertSame(SessionState::Failed, $lifecycle->sessionState());
        $feedback = $handle->feedbackSnapshot();
        self::assertSame(RuntimeReconnectPhase::Idle, $feedback->reconnectState()->phase);
        self::assertTrue($feedback->reconnectState()->isTerminallyStopped);
        self::assertFalse($feedback->reconnectState()->isRetryExhausted);
        self::assertTrue($feedback->reconnectState()->requiresExternalIntervention);
        self::assertTrue($feedback->reconnectState()->isFailClosedTerminalState);
        self::assertSame(RuntimeReconnectStopReason::AuthenticationRejected, $feedback->reconnectState()->terminalStopReason);
        self::assertNotNull($feedback->reconnectState()->terminalStoppedAtMicros);
        self::assertNull($feedback->reconnectState()->lastRetryAttemptStartedAtMicros);
        self::assertNull($feedback->reconnectState()->lastScheduledRetryDueAtMicros);
        self::assertNull($feedback->reconnectState()->lastScheduledBackoffDelaySeconds);
        self::assertGreaterThanOrEqual(0.0, $feedback->reconnectState()->terminalStoppedDurationSeconds ?? -1.0);
        $status = $handle->statusSnapshot();
        self::assertSame(RuntimeStatusPhase::Failed, $status->phase);
        self::assertFalse($status->isRuntimeActive);
        self::assertFalse($status->isRecoveryInProgress);
        self::assertNull($status->lastSuccessfulConnectAtMicros);
        self::assertNotNull($status->lastFailureAtMicros);
        self::assertSame(AuthenticationException::class, $status->lastFailureClass);
        self::assertSame('invalid password', $status->lastFailureMessage);
        self::assertSame(AuthenticationException::class, $status->startupErrorClass);
        self::assertSame('invalid password', $status->startupErrorMessage);
        self::assertSame('starting', $markers[0]['runner']);
        self::assertSame('failed', $markers[array_key_last($markers)]['runner']);
        self::assertTrue($markers[array_key_last($markers)]['failed']);
        self::assertSame('failed', $markers[array_key_last($markers)]['session']);

        $server->close();
    }

    public function testRunnerFeedbackSnapshotExposesHandshakeTimeoutTerminalReconnectTruth(): void
    {
        $server = new ScriptedFakeEslServer($this->loop, autoAuthRequest: false);

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeInput(
                endpoint: sprintf('tcp://127.0.0.1:%d', $server->port()),
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                    retryPolicy: RetryPolicy::withMaxAttempts(2, 0.01),
                    heartbeat: HeartbeatConfig::disabled(),
                    commandTimeout: CommandTimeoutConfig::withApiTimeout(0.05),
                ),
            ),
            $this->loop,
        );

        try {
            $this->await($handle->startupPromise(), 0.3);
            self::fail('Expected startup to fail on connect/auth handshake timeout');
        } catch (CommandTimeoutException $e) {
            self::assertSame('connect/auth handshake', $e->eslCommand());
        }

        self::assertSame(RuntimeRunnerState::Failed, $handle->state());
        self::assertInstanceOf(CommandTimeoutException::class, $handle->startupError());

        $feedback = $handle->feedbackSnapshot();
        self::assertSame(ConnectionState::Disconnected, $feedback->connectionState());
        self::assertSame(SessionState::Failed, $feedback->sessionState());
        self::assertSame(RuntimeReconnectPhase::Idle, $feedback->reconnectState()->phase);
        self::assertFalse($feedback->reconnectState()->isRetryScheduled);
        self::assertTrue($feedback->reconnectState()->isTerminallyStopped);
        self::assertFalse($feedback->reconnectState()->isRetryExhausted);
        self::assertTrue($feedback->reconnectState()->requiresExternalIntervention);
        self::assertTrue($feedback->reconnectState()->isFailClosedTerminalState);
        self::assertSame(RuntimeReconnectStopReason::HandshakeTimeout, $feedback->reconnectState()->terminalStopReason);
        self::assertNotNull($feedback->reconnectState()->terminalStoppedAtMicros);
        self::assertNull($feedback->reconnectState()->lastRetryAttemptStartedAtMicros);
        self::assertNull($feedback->reconnectState()->lastScheduledRetryDueAtMicros);
        self::assertNull($feedback->reconnectState()->lastScheduledBackoffDelaySeconds);
        self::assertGreaterThanOrEqual(0.0, $feedback->reconnectState()->terminalStoppedDurationSeconds ?? -1.0);

        $status = $handle->statusSnapshot();
        self::assertSame(RuntimeStatusPhase::Failed, $status->phase);
        self::assertFalse($status->isRuntimeActive);
        self::assertFalse($status->isRecoveryInProgress);
        self::assertSame(CommandTimeoutException::class, $status->lastFailureClass);
        self::assertSame(RuntimeReconnectStopReason::HandshakeTimeout, $status->reconnectState->terminalStopReason);

        $server->close();
    }

    public function testRunnerFeedbackSnapshotExposesHandshakeProtocolFailureTerminalReconnectTruth(): void
    {
        $server = new ScriptedFakeEslServer(
            $this->loop,
            autoAuthRequest: false,
            onConnection: function ($connection) use (&$server): void {
                $this->loop->addTimer(0.01, function () use ($connection, $server): void {
                    $server->writeRawFrame($connection, "Content-Type auth/request\n\n");
                });
            },
        );

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeInput(
                endpoint: sprintf('tcp://127.0.0.1:%d', $server->port()),
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                    retryPolicy: RetryPolicy::withMaxAttempts(2, 0.01),
                    heartbeat: HeartbeatConfig::disabled(),
                    commandTimeout: CommandTimeoutConfig::withApiTimeout(0.05),
                ),
            ),
            $this->loop,
        );

        try {
            $this->await($handle->startupPromise(), 0.3);
            self::fail('Expected startup to fail on malformed connect/auth handshake traffic');
        } catch (ConnectionException $e) {
            self::assertSame('Malformed inbound frame during connect/auth handshake', $e->getMessage());
        }

        self::assertSame(RuntimeRunnerState::Failed, $handle->state());
        self::assertInstanceOf(ConnectionException::class, $handle->startupError());

        $feedback = $handle->feedbackSnapshot();
        self::assertSame(ConnectionState::Disconnected, $feedback->connectionState());
        self::assertSame(SessionState::Failed, $feedback->sessionState());
        self::assertSame(RuntimeReconnectPhase::Idle, $feedback->reconnectState()->phase);
        self::assertFalse($feedback->reconnectState()->isRetryScheduled);
        self::assertTrue($feedback->reconnectState()->isTerminallyStopped);
        self::assertFalse($feedback->reconnectState()->isRetryExhausted);
        self::assertTrue($feedback->reconnectState()->requiresExternalIntervention);
        self::assertTrue($feedback->reconnectState()->isFailClosedTerminalState);
        self::assertSame(
            RuntimeReconnectStopReason::HandshakeProtocolFailure,
            $feedback->reconnectState()->terminalStopReason,
        );
        self::assertNotNull($feedback->reconnectState()->terminalStoppedAtMicros);
        self::assertNull($feedback->reconnectState()->lastRetryAttemptStartedAtMicros);
        self::assertNull($feedback->reconnectState()->lastScheduledRetryDueAtMicros);
        self::assertNull($feedback->reconnectState()->lastScheduledBackoffDelaySeconds);
        self::assertGreaterThanOrEqual(0.0, $feedback->reconnectState()->terminalStoppedDurationSeconds ?? -1.0);

        $status = $handle->statusSnapshot();
        self::assertSame(RuntimeStatusPhase::Failed, $status->phase);
        self::assertFalse($status->isRuntimeActive);
        self::assertFalse($status->isRecoveryInProgress);
        self::assertSame(ConnectionException::class, $status->lastFailureClass);
        self::assertSame(
            RuntimeReconnectStopReason::HandshakeProtocolFailure,
            $status->reconnectState->terminalStopReason,
        );

        $server->close();
    }

    public function testRunnerLifecycleSnapshotObservesReconnectAndTerminalDisconnect(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeInput(
                endpoint: sprintf('tcp://127.0.0.1:%d', $server->port()),
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                    retryPolicy: RetryPolicy::withMaxAttempts(2, 0.05),
                    heartbeat: HeartbeatConfig::disabled(),
                ),
            ),
            $this->loop,
        );

        $this->await($handle->startupPromise());

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });

        $server->closeActiveConnection();

        $this->waitUntil(
            fn(): bool => $handle->lifecycleSnapshot()->isReconnecting(),
            0.2,
        );

        $reconnecting = $handle->lifecycleSnapshot();
        self::assertSame(RuntimeRunnerState::Running, $reconnecting->runnerState);
        self::assertSame(ConnectionState::Reconnecting, $reconnecting->connectionState());
        self::assertSame(SessionState::Disconnected, $reconnecting->sessionState());
        self::assertFalse($reconnecting->isConnected());
        self::assertFalse($reconnecting->isAuthenticated());
        self::assertFalse($reconnecting->isLive());
        self::assertTrue($reconnecting->isReconnecting());
        self::assertFalse($reconnecting->isDraining());
        $reconnectingStatus = $handle->statusSnapshot();
        self::assertSame(RuntimeStatusPhase::Reconnecting, $reconnectingStatus->phase);
        self::assertTrue($reconnectingStatus->isRuntimeActive);
        self::assertTrue($reconnectingStatus->isRecoveryInProgress);
        self::assertNotNull($reconnectingStatus->lastSuccessfulConnectAtMicros);
        self::assertNotNull($reconnectingStatus->lastDisconnectAtMicros);
        self::assertNull($reconnectingStatus->lastDisconnectReasonClass);

        $this->waitUntil(
            fn(): bool => $handle->lifecycleSnapshot()->connectionState() === ConnectionState::Authenticated,
            0.5,
        );

        $recoveredStatus = $handle->statusSnapshot();
        self::assertSame(RuntimeStatusPhase::Active, $recoveredStatus->phase);
        self::assertTrue($recoveredStatus->isRuntimeActive);
        self::assertFalse($recoveredStatus->isRecoveryInProgress);
        self::assertTrue($recoveredStatus->hasHeartbeatObservation());
        self::assertNotNull($recoveredStatus->lastSuccessfulConnectAtMicros);

        $this->await($handle->client()->disconnect());

        $closed = $handle->lifecycleSnapshot();
        self::assertSame(RuntimeRunnerState::Running, $closed->runnerState);
        self::assertSame(ConnectionState::Closed, $closed->connectionState());
        self::assertSame(SessionState::Disconnected, $closed->sessionState());
        self::assertFalse($closed->isConnected());
        self::assertFalse($closed->isLive());
        self::assertFalse($closed->isDraining());
        self::assertTrue($closed->isStopped());
        $feedback = $handle->feedbackSnapshot();
        self::assertSame(RuntimeReconnectPhase::Idle, $feedback->reconnectState()->phase);
        self::assertTrue($feedback->reconnectState()->isTerminallyStopped);
        self::assertFalse($feedback->reconnectState()->isRetryExhausted);
        self::assertTrue($feedback->reconnectState()->requiresExternalIntervention);
        self::assertFalse($feedback->reconnectState()->isFailClosedTerminalState);
        self::assertSame(RuntimeReconnectStopReason::ExplicitShutdown, $feedback->reconnectState()->terminalStopReason);
        self::assertNotNull($feedback->reconnectState()->terminalStoppedAtMicros);
        self::assertNotNull($feedback->reconnectState()->lastRetryAttemptStartedAtMicros);
        self::assertNotNull($feedback->reconnectState()->lastScheduledRetryDueAtMicros);
        self::assertEqualsWithDelta(0.05, $feedback->reconnectState()->lastScheduledBackoffDelaySeconds ?? 0.0, 0.01);
        self::assertGreaterThanOrEqual(0.0, $feedback->reconnectState()->terminalStoppedDurationSeconds ?? -1.0);
        self::assertGreaterThanOrEqual(
            $feedback->reconnectState()->lastRetryAttemptStartedAtMicros ?? 0.0,
            $feedback->reconnectState()->terminalStoppedAtMicros ?? 0.0,
        );
        $closedStatus = $handle->statusSnapshot();
        self::assertSame(RuntimeStatusPhase::Closed, $closedStatus->phase);
        self::assertFalse($closedStatus->isRuntimeActive);
        self::assertFalse($closedStatus->isRecoveryInProgress);
        self::assertNotNull($closedStatus->lastSuccessfulConnectAtMicros);
        self::assertNotNull($closedStatus->lastDisconnectAtMicros);
        self::assertNull($closedStatus->lastDisconnectReasonClass);
        self::assertNull($closedStatus->lastDisconnectReasonMessage);

        $server->close();
    }

    public function testRunnerStatusPreservesTransportErrorCauseAcrossUnexpectedClose(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });

        $connector = new class ($this->loop, $server->address()) implements ConnectorInterface {
            public ?ConnectionInterface $connection = null;

            public function __construct(
                private readonly LoopInterface $loop,
                private readonly string $targetUri,
            ) {}

            public function connect($uri)
            {
                return (new Connector([], $this->loop))
                    ->connect($this->targetUri)
                    ->then(function (ConnectionInterface $connection): ConnectionInterface {
                        $this->connection = $connection;

                        return $connection;
                    });
            }
        };

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeBootstrapInput(
                endpoint: sprintf('tcp://127.0.0.1:%d', $server->port()),
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                    retryPolicy: RetryPolicy::withMaxAttempts(2, 0.05),
                    heartbeat: HeartbeatConfig::disabled(),
                ),
                connector: $connector,
                inboundPipeline: new InboundPipeline(),
                sessionContext: new RuntimeSessionContext('runtime-session-transport-error'),
            ),
            $this->loop,
        );

        $this->await($handle->startupPromise());
        self::assertInstanceOf(ConnectionInterface::class, $connector->connection);

        $connector->connection->emit('error', [new RuntimeException('forced transport error')]);
        $connector->connection->close();

        $this->waitUntil(
            fn(): bool => $handle->statusSnapshot()->phase === RuntimeStatusPhase::Reconnecting,
            0.2,
        );

        $reconnecting = $handle->statusSnapshot();
        self::assertSame(RuntimeStatusPhase::Reconnecting, $reconnecting->phase);
        self::assertTrue($reconnecting->isRuntimeActive);
        self::assertTrue($reconnecting->isRecoveryInProgress);
        self::assertSame(RuntimeException::class, $reconnecting->lastFailureClass);
        self::assertSame('forced transport error', $reconnecting->lastFailureMessage);
        self::assertSame(RuntimeException::class, $reconnecting->lastDisconnectReasonClass);
        self::assertSame('forced transport error', $reconnecting->lastDisconnectReasonMessage);
        self::assertFalse($reconnecting->health->isDraining);

        $this->waitUntil(
            fn(): bool => $handle->statusSnapshot()->phase === RuntimeStatusPhase::Active
                && $server->connectionCount() === 2,
            0.5,
        );

        $recovered = $handle->statusSnapshot();
        self::assertSame(RuntimeStatusPhase::Active, $recovered->phase);
        self::assertFalse($recovered->isRecoveryInProgress);
        self::assertSame(RuntimeException::class, $recovered->lastDisconnectReasonClass);
        self::assertSame('forced transport error', $recovered->lastDisconnectReasonMessage);

        $this->await($handle->client()->disconnect());
        $server->close();
    }

    public function testRunnerFeedbackSnapshotExposesRetryExhaustedTerminalReconnectTruth(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });

        $connector = new class ($this->loop, $server->address()) implements ConnectorInterface {
            private int $attempts = 0;

            public function __construct(
                private readonly LoopInterface $loop,
                private readonly string $targetUri,
            ) {}

            public function connect($uri)
            {
                $this->attempts++;

                if ($this->attempts === 1) {
                    return (new Connector([], $this->loop))->connect($this->targetUri);
                }

                return \React\Promise\reject(new RuntimeException('forced reconnect attempt failure'));
            }
        };

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeBootstrapInput(
                endpoint: sprintf('tcp://127.0.0.1:%d', $server->port()),
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                    retryPolicy: RetryPolicy::withMaxAttempts(1, 0.01),
                    heartbeat: HeartbeatConfig::disabled(),
                ),
                connector: $connector,
                inboundPipeline: new InboundPipeline(),
                sessionContext: new RuntimeSessionContext('runtime-session-exhausted'),
            ),
            $this->loop,
        );

        $this->await($handle->startupPromise());

        $server->closeActiveConnection();
        $server->close();

        $this->waitUntil(
            fn(): bool => $handle->feedbackSnapshot()->reconnectState()->isTerminallyStopped,
            0.5,
        );

        $feedback = $handle->feedbackSnapshot();
        self::assertSame(RuntimeReconnectPhase::Exhausted, $feedback->reconnectState()->phase);
        self::assertTrue($feedback->reconnectState()->isTerminallyStopped);
        self::assertTrue($feedback->reconnectState()->isRetryExhausted);
        self::assertTrue($feedback->reconnectState()->requiresExternalIntervention);
        self::assertTrue($feedback->reconnectState()->isFailClosedTerminalState);
        self::assertSame(RuntimeReconnectStopReason::RetryExhausted, $feedback->reconnectState()->terminalStopReason);
        self::assertFalse($feedback->reconnectState()->isRetryScheduled);
        self::assertNotNull($feedback->reconnectState()->terminalStoppedAtMicros);
        self::assertNotNull($feedback->reconnectState()->lastRetryAttemptStartedAtMicros);
        self::assertNotNull($feedback->reconnectState()->lastScheduledRetryDueAtMicros);
        self::assertEqualsWithDelta(0.01, $feedback->reconnectState()->lastScheduledBackoffDelaySeconds ?? 0.0, 0.005);
        self::assertGreaterThanOrEqual(0.0, $feedback->reconnectState()->terminalStoppedDurationSeconds ?? -1.0);
        self::assertGreaterThanOrEqual(
            $feedback->reconnectState()->lastRetryAttemptStartedAtMicros ?? 0.0,
            $feedback->reconnectState()->terminalStoppedAtMicros ?? 0.0,
        );
        self::assertEqualsWithDelta(
            $feedback->reconnectState()->lastScheduledRetryDueAtMicros ?? 0.0,
            $feedback->reconnectState()->lastRetryAttemptStartedAtMicros ?? 0.0,
            5_000.0,
        );
        self::assertSame(ConnectionState::Disconnected, $feedback->connectionState());
        self::assertSame(SessionState::Disconnected, $feedback->sessionState());
        $status = $handle->statusSnapshot();
        self::assertSame(RuntimeStatusPhase::Disconnected, $status->phase);
        self::assertTrue($status->isRuntimeActive);
        self::assertFalse($status->isRecoveryInProgress);
        self::assertNotNull($status->lastSuccessfulConnectAtMicros);
        self::assertNotNull($status->lastDisconnectAtMicros);
        self::assertSame(ConnectionException::class, $status->lastFailureClass);
        self::assertNotNull($status->lastFailureAtMicros);
        self::assertSame(RuntimeReconnectStopReason::RetryExhausted, $status->reconnectState->terminalStopReason);
    }

    public function testRunnerLifecycleObservationRemainsTruthfulDuringEventAndBgapiActivity(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain CHANNEL_CREATE BACKGROUND_JOB', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('bgapi status', $command);
            $server->writeBgapiAcceptedReply($connection, 'runner-bgapi-job-1');
        });

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeInput(
                endpoint: sprintf('tcp://127.0.0.1:%d', $server->port()),
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                    heartbeat: HeartbeatConfig::disabled(),
                ),
            ),
            $this->loop,
        );

        $markers = [];
        $handle->onLifecycleChange(function (RuntimeLifecycleSnapshot $snapshot) use (&$markers): void {
            $markers[] = $this->lifecycleMarker($snapshot);
        });

        $this->await($handle->startupPromise());

        $eventDeferred = new Deferred();
        $handle->client()->events()->onRawEnvelope(
            function (EventEnvelope $envelope) use ($eventDeferred): void {
                if ($envelope->event()->eventName() === 'CHANNEL_CREATE') {
                    $eventDeferred->resolve($envelope);
                }
            },
        );

        $this->await($handle->client()->subscriptions()->subscribe('CHANNEL_CREATE', 'BACKGROUND_JOB'));

        $server->emitPlainEvent([
            'Event-Name' => 'CHANNEL_CREATE',
            'Event-Sequence' => '501',
            'Unique-ID' => 'runner-event-1',
            'Channel-Name' => 'sofia/internal/runner-event',
        ]);

        $envelope = $this->await($eventDeferred->promise(), 0.2);
        self::assertInstanceOf(EventEnvelope::class, $envelope);
        self::assertSame('CHANNEL_CREATE', $envelope->event()->eventName());
        self::assertSame('501', $envelope->metadata()->protocolSequence());

        $job = $handle->client()->bgapi('status');
        self::assertInstanceOf(BgapiJobHandle::class, $job);
        self::assertSame('status', $job->eslCommand());
        self::assertSame('', $job->eslArgs());

        $this->waitUntil(
            fn(): bool => $job->jobUuid() === 'runner-bgapi-job-1'
                && $handle->lifecycleSnapshot()->health?->pendingBgapiJobCount === 1,
            0.2,
        );

        $duringBgapi = $handle->lifecycleSnapshot();
        self::assertSame(RuntimeRunnerState::Running, $duringBgapi->runnerState);
        self::assertSame(ConnectionState::Authenticated, $duringBgapi->connectionState());
        self::assertSame(SessionState::Active, $duringBgapi->sessionState());
        self::assertTrue($duringBgapi->isLive());
        self::assertFalse($duringBgapi->isReconnecting());
        self::assertFalse($duringBgapi->isDraining());
        self::assertFalse($duringBgapi->isStopped());
        self::assertSame(['CHANNEL_CREATE', 'BACKGROUND_JOB'], $duringBgapi->health?->activeSubscriptions);

        $server->emitBackgroundJobEvent('runner-bgapi-job-1', "+OK runner bgapi complete\n", 'status');

        $completion = $this->await($job->promise(), 0.2);
        self::assertInstanceOf(BackgroundJobEvent::class, $completion);
        self::assertSame('runner-bgapi-job-1', $completion->jobUuid());
        self::assertSame("+OK runner bgapi complete\n", $completion->result());

        $afterActivity = $handle->lifecycleSnapshot();
        self::assertSame(ConnectionState::Authenticated, $afterActivity->connectionState());
        self::assertSame(SessionState::Active, $afterActivity->sessionState());
        self::assertTrue($afterActivity->isLive());
        self::assertFalse($afterActivity->isReconnecting());
        self::assertFalse($afterActivity->isDraining());
        self::assertFalse($afterActivity->isStopped());
        self::assertSame(0, $afterActivity->health?->pendingBgapiJobCount);

        self::assertSame([], array_filter(
            $markers,
            static fn(array $marker): bool => $marker['reconnecting'] === true
                || $marker['connection'] === 'reconnecting'
                || $marker['draining'] === true
                || $marker['connection'] === 'draining'
                || $marker['stopped'] === true
        ));

        $this->await($handle->client()->disconnect());
        $server->close();
    }

    public function testRunnerRetainsPendingBgapiAndRestoresEventFlowAcrossUnexpectedReconnect(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain CHANNEL_CREATE BACKGROUND_JOB', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('bgapi status', $command);
            $server->writeBgapiAcceptedReply($connection, 'runner-bgapi-reconnect-job');
        });

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeInput(
                endpoint: sprintf('tcp://127.0.0.1:%d', $server->port()),
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                    retryPolicy: RetryPolicy::withMaxAttempts(2, 0.01),
                    heartbeat: HeartbeatConfig::disabled(),
                ),
            ),
            $this->loop,
        );

        $markers = [];
        $handle->onLifecycleChange(function (RuntimeLifecycleSnapshot $snapshot) use (&$markers): void {
            $markers[] = $this->lifecycleMarker($snapshot);
        });

        $this->await($handle->startupPromise());
        $this->await($handle->client()->subscriptions()->subscribe('CHANNEL_CREATE', 'BACKGROUND_JOB'));

        $eventDeferred = new Deferred();
        $handle->client()->events()->onRawEnvelope(
            function (EventEnvelope $envelope) use ($eventDeferred): void {
                if ($envelope->event()->eventName() === 'CHANNEL_CREATE') {
                    $eventDeferred->resolve($envelope);
                }
            },
        );

        $job = $handle->client()->bgapi('status');
        $this->waitUntil(
            fn(): bool => $job->jobUuid() === 'runner-bgapi-reconnect-job'
                && $handle->lifecycleSnapshot()->health?->pendingBgapiJobCount === 1,
            0.2,
        );

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain CHANNEL_CREATE BACKGROUND_JOB', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });

        $server->closeActiveConnection();

        $this->waitUntil(
            function () use ($handle, &$markers): bool {
                $snapshot = $handle->lifecycleSnapshot();

                return $snapshot->connectionState() === ConnectionState::Reconnecting
                    && $snapshot->sessionState() === SessionState::Disconnected
                    && $snapshot->health?->pendingBgapiJobCount === 1
                    && array_filter(
                        $markers,
                        static fn(array $marker): bool => $marker['connection'] === 'reconnecting'
                            && $marker['session'] === 'disconnected'
                            && $marker['reconnecting'] === true
                            && $marker['draining'] === false
                    ) !== [];
            },
            0.2,
        );

        $reconnecting = $handle->lifecycleSnapshot();
        self::assertFalse($reconnecting->isLive());
        self::assertTrue($reconnecting->isReconnecting());
        self::assertFalse($reconnecting->isDraining());
        self::assertFalse($reconnecting->isStopped());
        self::assertSame(1, $reconnecting->health?->pendingBgapiJobCount);

        try {
            $handle->client()->bgapi('status');
            self::fail('Expected new bgapi work to fail closed while reconnecting');
        } catch (ConnectionException $e) {
            self::assertSame('Runtime is not authenticated', $e->getMessage());
        }

        try {
            $this->await($handle->client()->subscriptions()->subscribe('CHANNEL_ANSWER'));
            self::fail('Expected subscription mutation to fail closed while reconnecting');
        } catch (ConnectionException $e) {
            self::assertSame('Runtime is not authenticated', $e->getMessage());
        }

        $this->waitUntil(
            fn(): bool => $handle->lifecycleSnapshot()->connectionState() === ConnectionState::Authenticated
                && $handle->lifecycleSnapshot()->sessionState() === SessionState::Active
                && $handle->lifecycleSnapshot()->isLive()
                && $handle->lifecycleSnapshot()->health?->pendingBgapiJobCount === 1
                && $handle->lifecycleSnapshot()->health?->activeSubscriptions === ['CHANNEL_CREATE', 'BACKGROUND_JOB'],
            0.5,
        );

        $server->emitPlainEvent([
            'Event-Name' => 'CHANNEL_CREATE',
            'Event-Sequence' => '601',
            'Unique-ID' => 'runner-reconnect-event-1',
        ]);
        $server->emitBackgroundJobEvent('runner-bgapi-reconnect-job', "+OK runner bgapi recovered\n", 'status');

        $event = $this->await($eventDeferred->promise(), 0.2);
        self::assertInstanceOf(EventEnvelope::class, $event);
        self::assertSame('CHANNEL_CREATE', $event->event()->eventName());
        self::assertSame('601', $event->metadata()->protocolSequence());

        $completion = $this->await($job->promise(), 0.2);
        self::assertInstanceOf(BackgroundJobEvent::class, $completion);
        self::assertSame('runner-bgapi-reconnect-job', $completion->jobUuid());
        self::assertSame("+OK runner bgapi recovered\n", $completion->result());

        $recovered = $handle->lifecycleSnapshot();
        self::assertSame(ConnectionState::Authenticated, $recovered->connectionState());
        self::assertSame(SessionState::Active, $recovered->sessionState());
        self::assertTrue($recovered->isLive());
        self::assertFalse($recovered->isReconnecting());
        self::assertFalse($recovered->isDraining());
        self::assertFalse($recovered->isStopped());
        self::assertSame(0, $recovered->health?->pendingBgapiJobCount);

        self::assertSame([], array_filter(
            $markers,
            static fn(array $marker): bool => $marker['draining'] === true
                || $marker['connection'] === 'draining'
        ));

        $this->await($handle->client()->disconnect());
        $server->close();
    }

    public function testRunnerKeepsPendingBgapiTruthWhileHeartbeatDegradesAndRecovers(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('event plain BACKGROUND_JOB', $command);
            $server->writeCommandReply($connection, '+OK event listener enabled plain');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('bgapi status', $command);
            $server->writeBgapiAcceptedReply($connection, 'runner-bgapi-degraded-job');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $this->loop->addTimer(0.03, function () use ($connection, $server): void {
                $server->writeApiResponse($connection, "+OK liveness-recovered-with-bgapi\n");
            });
        });

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeInput(
                endpoint: sprintf('tcp://127.0.0.1:%d', $server->port()),
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                    retryPolicy: RetryPolicy::disabled(),
                    heartbeat: HeartbeatConfig::withInterval(0.05, 0.01),
                ),
            ),
            $this->loop,
        );

        $markers = [];
        $handle->onLifecycleChange(function (RuntimeLifecycleSnapshot $snapshot) use (&$markers): void {
            $markers[] = $this->lifecycleMarker($snapshot);
        });

        $this->await($handle->startupPromise());
        $this->await($handle->client()->subscriptions()->subscribe('BACKGROUND_JOB'));

        $job = $handle->client()->bgapi('status');
        $this->waitUntil(
            fn(): bool => $job->jobUuid() === 'runner-bgapi-degraded-job'
                && $handle->lifecycleSnapshot()->health?->pendingBgapiJobCount === 1,
            0.2,
        );

        $this->waitUntil(
            function () use ($handle, &$markers): bool {
                $snapshot = $handle->lifecycleSnapshot();

                return $snapshot->connectionState() === ConnectionState::Authenticated
                    && $snapshot->sessionState() === SessionState::Active
                    && $snapshot->isLive() === false
                    && $snapshot->health?->pendingBgapiJobCount === 1
                    && array_filter(
                        $markers,
                        static fn(array $marker): bool => $marker['connection'] === 'authenticated'
                            && $marker['session'] === 'active'
                            && $marker['live'] === false
                            && $marker['reconnecting'] === false
                            && $marker['draining'] === false
                    ) !== [];
            },
            0.2,
        );

        $degraded = $handle->lifecycleSnapshot();
        self::assertSame(RuntimeRunnerState::Running, $degraded->runnerState);
        self::assertSame(ConnectionState::Authenticated, $degraded->connectionState());
        self::assertSame(SessionState::Active, $degraded->sessionState());
        self::assertFalse($degraded->isLive());
        self::assertFalse($degraded->isReconnecting());
        self::assertFalse($degraded->isDraining());
        self::assertFalse($degraded->isStopped());
        self::assertSame(1, $degraded->health?->pendingBgapiJobCount);

        $this->waitUntil(
            fn(): bool => $handle->lifecycleSnapshot()->connectionState() === ConnectionState::Authenticated
                && $handle->lifecycleSnapshot()->sessionState() === SessionState::Active
                && $handle->lifecycleSnapshot()->isLive()
                && $handle->lifecycleSnapshot()->health?->pendingBgapiJobCount === 1,
            0.3,
        );

        $server->emitBackgroundJobEvent('runner-bgapi-degraded-job', "+OK bgapi completed after liveness recovery\n", 'status');

        $completion = $this->await($job->promise(), 0.2);
        self::assertInstanceOf(BackgroundJobEvent::class, $completion);
        self::assertSame('runner-bgapi-degraded-job', $completion->jobUuid());
        self::assertSame("+OK bgapi completed after liveness recovery\n", $completion->result());

        $recovered = $handle->lifecycleSnapshot();
        self::assertSame(ConnectionState::Authenticated, $recovered->connectionState());
        self::assertSame(SessionState::Active, $recovered->sessionState());
        self::assertTrue($recovered->isLive());
        self::assertFalse($recovered->isReconnecting());
        self::assertFalse($recovered->isDraining());
        self::assertFalse($recovered->isStopped());
        self::assertSame(0, $recovered->health?->pendingBgapiJobCount);

        self::assertSame([], array_filter(
            $markers,
            static fn(array $marker): bool => $marker['reconnecting'] === true
                || $marker['connection'] === 'reconnecting'
                || $marker['draining'] === true
                || $marker['connection'] === 'draining'
        ));

        $this->await($handle->client()->disconnect());
        $server->close();
    }

    public function testRunnerLifecycleObservationReportsHeartbeatDegradationAndRecoveryWithoutFalseReconnect(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $this->loop->addTimer(0.03, function () use ($connection, $server): void {
                $server->writeApiResponse($connection, "+OK liveness-recovered\n");
            });
        });

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeInput(
                endpoint: sprintf('tcp://127.0.0.1:%d', $server->port()),
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                    retryPolicy: RetryPolicy::disabled(),
                    heartbeat: HeartbeatConfig::withInterval(0.05, 0.01),
                ),
            ),
            $this->loop,
        );

        $markers = [];
        $handle->onLifecycleChange(function (RuntimeLifecycleSnapshot $snapshot) use (&$markers): void {
            $markers[] = $this->lifecycleMarker($snapshot);
        });

        $this->await($handle->startupPromise());

        $this->waitUntil(
            function () use ($handle, &$markers): bool {
                return $handle->lifecycleSnapshot()->connectionState() === ConnectionState::Authenticated
                    && $handle->lifecycleSnapshot()->sessionState() === SessionState::Active
                    && $handle->lifecycleSnapshot()->isLive() === false
                    && array_filter(
                        $markers,
                        static fn(array $marker): bool => $marker['connection'] === 'authenticated'
                            && $marker['session'] === 'active'
                            && $marker['live'] === false
                            && $marker['reconnecting'] === false
                            && $marker['draining'] === false
                            && $marker['stopped'] === false
                    ) !== [];
            },
            0.2,
        );

        $degraded = $handle->lifecycleSnapshot();
        self::assertSame(RuntimeRunnerState::Running, $degraded->runnerState);
        self::assertSame(ConnectionState::Authenticated, $degraded->connectionState());
        self::assertSame(SessionState::Active, $degraded->sessionState());
        self::assertFalse($degraded->isLive());
        self::assertFalse($degraded->isReconnecting());
        self::assertFalse($degraded->isDraining());
        self::assertFalse($degraded->isStopped());
        self::assertNotNull($degraded->lastHeartbeatAtMicros());

        $this->waitUntil(
            function () use ($handle, &$markers): bool {
                return $handle->lifecycleSnapshot()->connectionState() === ConnectionState::Authenticated
                    && $handle->lifecycleSnapshot()->sessionState() === SessionState::Active
                    && $handle->lifecycleSnapshot()->isLive()
                    && count(array_filter(
                        $markers,
                        static fn(array $marker): bool => $marker['connection'] === 'authenticated'
                            && $marker['session'] === 'active'
                            && $marker['live'] === true
                            && $marker['reconnecting'] === false
                            && $marker['draining'] === false
                            && $marker['stopped'] === false
                    )) >= 2;
            },
            0.3,
        );

        $recovered = $handle->lifecycleSnapshot();
        self::assertSame(RuntimeRunnerState::Running, $recovered->runnerState);
        self::assertSame(ConnectionState::Authenticated, $recovered->connectionState());
        self::assertSame(SessionState::Active, $recovered->sessionState());
        self::assertTrue($recovered->isLive());
        self::assertFalse($recovered->isReconnecting());
        self::assertFalse($recovered->isDraining());
        self::assertFalse($recovered->isStopped());

        self::assertSame([], array_filter(
            $markers,
            static fn(array $marker): bool => $marker['reconnecting'] === true
                || $marker['connection'] === 'reconnecting'
                || $marker['draining'] === true
                || $marker['connection'] === 'draining'
        ));

        $this->await($handle->client()->disconnect());
        $server->close();
    }

    public function testRunnerLifecycleObservationReportsHeartbeatDeadPathReconnectAndRecoveryWithoutFalseDrain(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command): void {
            self::assertSame('api status', $command);
            // Leave the first probe unanswered so the second miss forces a recoverable close.
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $server->writeApiResponse($connection, "+OK after-heartbeat-dead-recover\n");
        });

        $handle = AsyncEslRuntime::runner()->run(
            new PreparedRuntimeInput(
                endpoint: sprintf('tcp://127.0.0.1:%d', $server->port()),
                runtimeConfig: RuntimeConfig::create(
                    host: '127.0.0.1',
                    port: $server->port(),
                    password: 'ClueCon',
                    retryPolicy: RetryPolicy::withMaxAttempts(2, 0.01),
                    heartbeat: HeartbeatConfig::withInterval(0.05, 0.01),
                ),
            ),
            $this->loop,
        );

        $markers = [];
        $handle->onLifecycleChange(function (RuntimeLifecycleSnapshot $snapshot) use (&$markers): void {
            $markers[] = $this->lifecycleMarker($snapshot);
        });

        $this->await($handle->startupPromise());

        $this->waitUntil(
            function () use ($handle, &$markers): bool {
                return $handle->lifecycleSnapshot()->connectionState() === ConnectionState::Authenticated
                    && $handle->lifecycleSnapshot()->sessionState() === SessionState::Active
                    && $handle->lifecycleSnapshot()->isLive() === false
                    && array_filter(
                        $markers,
                        static fn(array $marker): bool => $marker['connection'] === 'authenticated'
                            && $marker['session'] === 'active'
                            && $marker['live'] === false
                            && $marker['reconnecting'] === false
                            && $marker['draining'] === false
                            && $marker['stopped'] === false
                    ) !== [];
            },
            0.2,
        );

        $this->waitUntil(
            function () use ($handle, &$markers): bool {
                return array_filter(
                    $markers,
                    static fn(array $marker): bool => $marker['connection'] === 'reconnecting'
                        && $marker['session'] === 'disconnected'
                        && $marker['live'] === false
                        && $marker['reconnecting'] === true
                        && $marker['draining'] === false
                        && $marker['stopped'] === false
                ) !== [];
            },
            0.2,
        );

        $recovering = $handle->lifecycleSnapshot();
        self::assertSame(RuntimeRunnerState::Running, $recovering->runnerState);
        self::assertSame(ConnectionState::Reconnecting, $recovering->connectionState());
        self::assertSame(SessionState::Disconnected, $recovering->sessionState());
        self::assertFalse($recovering->isLive());
        self::assertTrue($recovering->isReconnecting());
        self::assertFalse($recovering->isDraining());
        self::assertFalse($recovering->isStopped());

        $this->waitUntil(
            function () use ($handle, &$markers): bool {
                return $handle->lifecycleSnapshot()->connectionState() === ConnectionState::Authenticated
                    && $handle->lifecycleSnapshot()->sessionState() === SessionState::Active
                    && $handle->lifecycleSnapshot()->isLive()
                    && count(array_filter(
                        $markers,
                        static fn(array $marker): bool => $marker['connection'] === 'authenticated'
                            && $marker['session'] === 'active'
                            && $marker['live'] === true
                            && $marker['reconnecting'] === false
                            && $marker['draining'] === false
                            && $marker['stopped'] === false
                    )) >= 2;
            },
            0.4,
        );

        $recovered = $handle->lifecycleSnapshot();
        self::assertSame(RuntimeRunnerState::Running, $recovered->runnerState);
        self::assertSame(ConnectionState::Authenticated, $recovered->connectionState());
        self::assertSame(SessionState::Active, $recovered->sessionState());
        self::assertTrue($recovered->isLive());
        self::assertFalse($recovered->isReconnecting());
        self::assertFalse($recovered->isDraining());
        self::assertFalse($recovered->isStopped());

        self::assertSame([], array_filter(
            $markers,
            static fn(array $marker): bool => $marker['draining'] === true
                || $marker['connection'] === 'draining'
        ));

        $this->await($handle->client()->disconnect());
        $server->close();
    }
}
