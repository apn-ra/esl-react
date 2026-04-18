<?php declare(strict_types=1);

namespace Apntalk\EslReact\Runtime;

use Apntalk\EslCore\Commands\ApiCommand;
use Apntalk\EslCore\Commands\AuthCommand;
use Apntalk\EslCore\Commands\ExitCommand;
use Apntalk\EslCore\Events\BackgroundJobEvent;
use Apntalk\EslCore\Replies\AuthAcceptedReply;
use Apntalk\EslCore\Replies\ErrorReply;
use Apntalk\EslReact\Bgapi\BgapiDispatcher;
use Apntalk\EslReact\Bgapi\BgapiJobHandle;
use Apntalk\EslReact\Bgapi\BgapiJobTracker;
use Apntalk\EslReact\CommandBus\AsyncCommandBus;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Contracts\AsyncEslClientInterface;
use Apntalk\EslReact\Contracts\EventStreamInterface;
use Apntalk\EslReact\Contracts\HealthReporterInterface;
use Apntalk\EslReact\Contracts\SubscriptionManagerInterface;
use Apntalk\EslReact\Events\EventStream;
use Apntalk\EslReact\Exceptions\AuthenticationException;
use Apntalk\EslReact\Exceptions\BackpressureException;
use Apntalk\EslReact\Exceptions\CommandTimeoutException;
use Apntalk\EslReact\Exceptions\ConnectionException;
use Apntalk\EslReact\Exceptions\ConnectionLostException;
use Apntalk\EslReact\Exceptions\DrainException;
use Apntalk\EslReact\Health\RuntimeHealthReporter;
use Apntalk\EslReact\Heartbeat\HeartbeatMonitor;
use Apntalk\EslReact\Heartbeat\LivenessState;
use Apntalk\EslReact\Protocol\EnvelopePump;
use Apntalk\EslReact\Protocol\InboundMessageRouter;
use Apntalk\EslReact\Protocol\OutboundMessageDispatcher;
use Apntalk\EslReact\Replay\RuntimeReplayCapture;
use Apntalk\EslReact\Runner\RuntimeReconnectPhase;
use Apntalk\EslReact\Runner\RuntimeReconnectStopReason;
use Apntalk\EslReact\Session\SessionState;
use Apntalk\EslReact\Subscription\SubscriptionManager;
use Apntalk\EslReact\Supervisor\ReconnectScheduler;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface;
use function React\Promise\reject;
use function React\Promise\resolve;

final class RuntimeClient implements AsyncEslClientInterface
{
    /** @var list<callable(): void> */
    private array $lifecycleListeners = [];
    private ConnectionState $connectionState = ConnectionState::Disconnected;
    private SessionState $sessionState = SessionState::NotStarted;
    private LivenessState $livenessState = LivenessState::Dead;
    private ?ConnectionInterface $connection = null;
    /** @var Deferred<void>|null */
    private ?Deferred $connectDeferred = null;
    /** @var Deferred<void>|null */
    private ?Deferred $disconnectDeferred = null;
    private ?RuntimeHealthReporter $health = null;
    private ?TimerInterface $connectTimeoutTimer = null;
    private ?TimerInterface $drainDeadlineTimer = null;
    private ?TimerInterface $drainPollTimer = null;
    private bool $draining = false;
    private bool $supervisionEnabled = false;
    private bool $suppressReconnectOnNextClose = false;
    private bool $reconnectScheduled = false;
    private RuntimeReconnectPhase $reconnectPhase = RuntimeReconnectPhase::Idle;
    private ?RuntimeReconnectStopReason $reconnectStopReason = null;
    private ?float $reconnectTerminalStoppedAtMicros = null;
    private ?\Throwable $pendingCloseReason = null;
    private int $connectionGeneration = 0;
    private ?float $lastSuccessfulConnectAtMicros = null;
    private ?float $lastDisconnectAtMicros = null;
    private ?string $lastDisconnectReasonClass = null;
    private ?string $lastDisconnectReasonMessage = null;
    private ?float $lastFailureAtMicros = null;

    public function __construct(
        private readonly RuntimeConfig $config,
        private readonly LoopInterface $loop,
        private readonly ConnectorInterface $connector,
        private readonly string $connectionUri,
        private readonly EnvelopePump $envelopePump,
        private readonly InboundMessageRouter $router,
        private readonly OutboundMessageDispatcher $outbound,
        private readonly AsyncCommandBus $commands,
        private readonly BgapiDispatcher $bgapi,
        private readonly BgapiJobTracker $bgapiTracker,
        private readonly EventStream $events,
        private readonly SubscriptionManager $subscriptions,
        private readonly ReconnectScheduler $reconnects,
        private readonly HeartbeatMonitor $heartbeat,
        private readonly RuntimeReplayCapture $replay,
    ) {
        $this->wireProtocol();
        $this->wireHeartbeat();
    }

    public function attachHealthReporter(RuntimeHealthReporter $health): void
    {
        $this->health = $health;
    }

    public function onLifecycleChange(callable $listener): void
    {
        $this->lifecycleListeners[] = $listener;
    }

    public function connect(): PromiseInterface
    {
        if ($this->connectionState === ConnectionState::Authenticated) {
            return $this->resolvedVoid();
        }

        if ($this->connectionState === ConnectionState::Closed) {
            return reject(new ConnectionException('Runtime is closed'));
        }

        if ($this->connectDeferred !== null) {
            return $this->connectDeferred->promise();
        }

        if ($this->connectionState === ConnectionState::Reconnecting) {
            $this->connectDeferred = new Deferred();

            return $this->connectDeferred->promise();
        }

        $this->draining = false;
        $this->cancelDrainTimers();
        $this->supervisionEnabled = true;
        $this->suppressReconnectOnNextClose = false;
        $this->pendingCloseReason = null;
        $this->commands->exitDrainMode();
        $this->connectDeferred = new Deferred();
        $this->reconnects->reset();
        $this->reconnectPhase = RuntimeReconnectPhase::Idle;
        $this->reconnectStopReason = null;
        $this->reconnectTerminalStoppedAtMicros = null;
        $this->startConnectionAttempt();

        $connect = $this->connectDeferred;
        \assert($connect instanceof Deferred);

        return $connect->promise();
    }

    public function api(string $command, string $args = ''): PromiseInterface
    {
        try {
            $this->assertCanAcceptNewWork();
        } catch (\Throwable $e) {
            return reject($e);
        }

        $this->replay->captureApiDispatch($command, $args);

        return $this->commands->dispatch(
            new ApiCommand($command, $args),
            trim("api {$command} {$args}"),
            $this->config->commandTimeout->apiTimeoutSeconds,
        )->then(function ($reply) use ($command) {
            if (!$reply instanceof \Apntalk\EslCore\Replies\ApiReply) {
                throw new ConnectionException(sprintf('Expected ApiReply for api %s, got %s', $command, get_debug_type($reply)));
            }

            return $reply;
        }, function (\Throwable $e) {
            $this->recordError($e);
            throw $e;
        });
    }

    public function bgapi(string $command, string $args = ''): BgapiJobHandle
    {
        $this->assertCanAcceptNewWork();

        $handle = $this->bgapi->dispatch($command, $args);
        $handle->promise()->then(
            function (): void {
                $this->maybeFinalizeDrain();
            },
            function (): void {
                $this->maybeFinalizeDrain();
            },
        );

        return $handle;
    }

    public function events(): EventStreamInterface
    {
        return $this->events;
    }

    public function health(): HealthReporterInterface
    {
        if ($this->health === null) {
            throw new \LogicException('Health reporter not attached');
        }

        return $this->health;
    }

    public function subscriptions(): SubscriptionManagerInterface
    {
        return $this->subscriptions;
    }

    public function disconnect(): PromiseInterface
    {
        if ($this->disconnectDeferred !== null) {
            return $this->disconnectDeferred->promise();
        }

        if ($this->connectDeferred !== null && $this->connection === null) {
            $disconnectReason = new ConnectionLostException('Disconnect requested before auth completed');
            $this->draining = true;
            $this->supervisionEnabled = false;
            $this->reconnects->cancel();
            $this->reconnectScheduled = false;
            $this->reconnectPhase = RuntimeReconnectPhase::Idle;
            $this->reconnectStopReason = RuntimeReconnectStopReason::ExplicitShutdown;
            $this->markReconnectTerminallyStopped();
            $this->heartbeat->stop();
            $this->commands->enterDrainMode();
            $this->cancelConnectTimeout();
            $this->cancelDrainTimers();
            $this->connectionState = ConnectionState::Closed;
            $this->sessionState = SessionState::Disconnected;
            $this->livenessState = LivenessState::Dead;
            $this->recordDisconnectObservation($disconnectReason);
            $this->notifyLifecycleChange();
            $this->settleConnectFailure($disconnectReason);

            return $this->resolvedVoid();
        }

        if ($this->connection === null) {
            $this->supervisionEnabled = false;
            $this->reconnects->cancel();
            $this->reconnectScheduled = false;
            $this->reconnectPhase = RuntimeReconnectPhase::Idle;
            $this->reconnectStopReason = RuntimeReconnectStopReason::ExplicitShutdown;
            $this->markReconnectTerminallyStopped();
            $this->heartbeat->stop();
            $this->cancelDrainTimers();
            $this->connectionState = ConnectionState::Closed;
            $this->sessionState = SessionState::Disconnected;
            $this->livenessState = LivenessState::Dead;
            $this->recordDisconnectObservation();
            $this->notifyLifecycleChange();
            return $this->resolvedVoid();
        }

        $this->draining = true;
        $this->supervisionEnabled = false;
        $this->reconnects->cancel();
        $this->reconnectScheduled = false;
        $this->reconnectPhase = RuntimeReconnectPhase::Idle;
        $this->reconnectStopReason = RuntimeReconnectStopReason::ExplicitShutdown;
        $this->markReconnectTerminallyStopped();
        $this->heartbeat->stop();
        $this->commands->enterDrainMode();
        $this->connectionState = ConnectionState::Draining;
        $this->notifyLifecycleChange();
        $this->disconnectDeferred = new Deferred();
        $this->startDrainTimers();
        $this->maybeFinalizeDrain();

        $disconnect = $this->disconnectDeferred;
        \assert($disconnect instanceof Deferred);

        return $disconnect->promise();
    }

    public function connectionState(): ConnectionState
    {
        return $this->connectionState;
    }

    public function sessionState(): SessionState
    {
        return $this->sessionState;
    }

    public function livenessState(): LivenessState
    {
        return $this->livenessState;
    }

    public function inflightCommandCount(): int
    {
        return $this->commands->totalPendingCount();
    }

    public function activeApiCommandCount(): int
    {
        return $this->commands->inflightCount();
    }

    public function queuedApiCommandCount(): int
    {
        return $this->commands->queuedCount();
    }

    public function pendingBgapiCount(): int
    {
        return $this->bgapi->pendingCount();
    }

    public function totalInflightWorkCount(): int
    {
        return $this->inflightCommandCount() + $this->pendingBgapiCount();
    }

    public function isOverloaded(): bool
    {
        return $this->config->backpressure->rejectOnOverload
            && $this->totalInflightWorkCount() >= $this->config->backpressure->maxInflightCommands;
    }

    public function isDraining(): bool
    {
        return $this->draining;
    }

    public function reconnectAttempts(): int
    {
        return $this->reconnects->attempts();
    }

    public function isReconnectRetryScheduled(): bool
    {
        return $this->reconnectScheduled;
    }

    /**
     * @return array{
     *   phase: string,
     *   attempt_number: ?int,
     *   is_retry_scheduled: bool,
     *   backoff_delay_seconds: ?float,
     *   next_retry_due_at_micros: ?float,
     *   remaining_delay_seconds: ?float,
     *   is_terminally_stopped: bool,
     *   is_retry_exhausted: bool,
     *   requires_external_intervention: bool,
     *   is_fail_closed_terminal_state: bool,
     *   terminal_stop_reason: ?string,
     *   terminal_stopped_at_micros: ?float,
     *   last_retry_attempt_started_at_micros: ?float,
     *   last_scheduled_retry_due_at_micros: ?float,
     *   last_scheduled_backoff_delay_seconds: ?float,
     *   terminal_stopped_duration_seconds: ?float
     * }
     */
    public function reconnectState(): array
    {
        $attemptNumber = null;
        $backoffDelaySeconds = null;
        $nextRetryDueAtMicros = null;
        $remainingDelaySeconds = null;

        if ($this->reconnectPhase === RuntimeReconnectPhase::WaitingToRetry) {
            $attemptNumber = $this->reconnects->scheduledAttemptNumber();
            $backoffDelaySeconds = $this->reconnects->scheduledDelaySeconds();
            $nextRetryDueAtMicros = $this->reconnects->nextRetryDueAtMicros();
            $remainingDelaySeconds = $this->reconnects->remainingDelaySeconds();
        } elseif (
            $this->reconnectPhase === RuntimeReconnectPhase::AttemptingReconnect
            || $this->reconnectPhase === RuntimeReconnectPhase::RestoringSession
        ) {
            $attemptNumber = $this->reconnects->attempts() > 0 ? $this->reconnects->attempts() : null;
            $backoffDelaySeconds = $attemptNumber !== null
                ? $this->config->retryPolicy->delayForAttempt($attemptNumber)
                : null;
        }

        $terminalStoppedDurationSeconds = null;
        if ($this->reconnectTerminalStoppedAtMicros !== null) {
            $terminalStoppedDurationSeconds = max(
                0.0,
                ((microtime(true) * 1_000_000.0) - $this->reconnectTerminalStoppedAtMicros) / 1_000_000.0,
            );
        }

        return [
            'phase' => $this->reconnectPhase->value,
            'attempt_number' => $attemptNumber,
            'is_retry_scheduled' => $this->reconnectScheduled,
            'backoff_delay_seconds' => $backoffDelaySeconds,
            'next_retry_due_at_micros' => $nextRetryDueAtMicros,
            'remaining_delay_seconds' => $remainingDelaySeconds,
            'is_terminally_stopped' => $this->reconnectStopReason !== null,
            'is_retry_exhausted' => $this->reconnectStopReason === RuntimeReconnectStopReason::RetryExhausted,
            'requires_external_intervention' => $this->reconnectStopReason !== null,
            'is_fail_closed_terminal_state' => $this->reconnectStopReason?->isFailClosed() ?? false,
            'terminal_stop_reason' => $this->reconnectStopReason?->value,
            'terminal_stopped_at_micros' => $this->reconnectTerminalStoppedAtMicros,
            'last_retry_attempt_started_at_micros' => $this->reconnects->lastRetryAttemptStartedAtMicros(),
            'last_scheduled_retry_due_at_micros' => $this->reconnects->lastScheduledDueAtMicros(),
            'last_scheduled_backoff_delay_seconds' => $this->reconnects->lastScheduledDelaySeconds(),
            'terminal_stopped_duration_seconds' => $terminalStoppedDurationSeconds,
        ];
    }

    /**
     * @return array{
     *   subscribe_all: bool,
     *   event_names: list<string>,
     *   filters: list<array{headerName: string, headerValue: string}>
     * }
     */
    public function desiredSubscriptionState(): array
    {
        return $this->subscriptions->desiredState();
    }

    /**
     * @return array{
     *   subscribe_all: bool,
     *   event_names: list<string>,
     *   filters: list<array{headerName: string, headerValue: string}>,
     *   is_current_for_active_session: bool
     * }
     */
    public function observedSubscriptionState(): array
    {
        return $this->subscriptions->observedState();
    }

    public function connectionGeneration(): int
    {
        return $this->connectionGeneration;
    }

    /**
     * @return array{
     *   last_successful_connect_at_micros: ?float,
     *   last_disconnect_at_micros: ?float,
     *   last_disconnect_reason_class: ?string,
     *   last_disconnect_reason_message: ?string,
     *   last_failure_at_micros: ?float
     * }
     */
    public function statusObservation(): array
    {
        return [
            'last_successful_connect_at_micros' => $this->lastSuccessfulConnectAtMicros,
            'last_disconnect_at_micros' => $this->lastDisconnectAtMicros,
            'last_disconnect_reason_class' => $this->lastDisconnectReasonClass,
            'last_disconnect_reason_message' => $this->lastDisconnectReasonMessage,
            'last_failure_at_micros' => $this->lastFailureAtMicros,
        ];
    }

    public function assertCanAcceptSessionMutation(): void
    {
        $this->assertCanAcceptNewWork();
    }

    private function wireProtocol(): void
    {
        $this->router->onAuthRequest(function (): void {
            if ($this->connection === null) {
                return;
            }

            $this->connectionState = ConnectionState::Authenticating;
            $this->sessionState = SessionState::Authenticating;
            $this->notifyLifecycleChange();
            $this->outbound->dispatch(new AuthCommand($this->config->password));
        });

        $this->router->onReply(function ($reply): void {
            $this->replay->captureReply($reply);

            if ($this->sessionState === SessionState::Authenticating) {
                if ($reply instanceof AuthAcceptedReply) {
                    $this->cancelConnectTimeout();
                    $this->restoreDesiredStateAfterAuthentication()->then(
                        function (): void {
                            $this->markRuntimeLive();
                            $connect = $this->connectDeferred;
                            $this->connectDeferred = null;
                            $connect?->resolve(null);
                        },
                        function (\Throwable $e): void {
                            $this->recordError($e);
                            $this->pendingCloseReason = $e;

                            if ($this->connection !== null) {
                                $this->connection->close();
                            }
                        },
                    );

                    return;
                }

                if ($reply instanceof ErrorReply) {
                    $this->cancelConnectTimeout();
                    $this->reconnectPhase = RuntimeReconnectPhase::Idle;
                    $this->reconnectStopReason = RuntimeReconnectStopReason::AuthenticationRejected;
                    $this->markReconnectTerminallyStopped();
                    $this->connectionState = ConnectionState::Disconnected;
                    $this->sessionState = SessionState::Failed;
                    $this->livenessState = LivenessState::Dead;
                    $this->notifyLifecycleChange();
                    $error = new AuthenticationException($reply->reason());
                    $this->recordError($error);
                    $this->settleConnectFailure($error);
                    $this->supervisionEnabled = false;
                    $this->suppressReconnectOnNextClose = true;
                    $this->connection?->close();

                    return;
                }
            }

            $this->commands->onReply($reply);
        });

        $this->router->onEvent(function ($frame): void {
            $this->events->handleFrame($frame);
        });

        $this->events->onAnyEvent(function ($event): void {
            if ($event instanceof BackgroundJobEvent) {
                $this->bgapi->onBackgroundJobEvent($event);
            }
        });

        $this->router->onDisconnectNotice(function (): void {
            $this->handleConnectionClosed(new ConnectionLostException('FreeSWITCH sent a disconnect notice'));
        });

        $this->router->onUnroutable(function ($frame, ?\Throwable $e = null): void {
            if ($this->connectDeferred !== null) {
                $error = new ConnectionException(
                    'Unexpected inbound frame during connect/auth handshake',
                    0,
                    $e,
                );
                $this->connectionState = ConnectionState::Disconnected;
                $this->sessionState = SessionState::Failed;
                $this->livenessState = LivenessState::Dead;
                $this->reconnectStopReason = RuntimeReconnectStopReason::HandshakeProtocolFailure;
                $this->markReconnectTerminallyStopped();
                $this->notifyLifecycleChange();
                $this->recordError($error);
                $this->cancelConnectTimeout();
                $this->settleConnectFailure($error);
                $this->supervisionEnabled = false;
                $this->suppressReconnectOnNextClose = true;
                $this->connection?->close();
                return;
            }

            if ($e !== null) {
                $this->recordError($e);
            }
        });

        $this->envelopePump->onFrame(function ($frame): void {
            $this->heartbeat->recordActivity();
            $this->router->route($frame);
        });

        $this->envelopePump->onParseError(function (\Throwable $e): void {
            if ($this->connectDeferred !== null) {
                $error = new ConnectionException('Malformed inbound frame during connect/auth handshake', 0, $e);
                $this->connectionState = ConnectionState::Disconnected;
                $this->sessionState = SessionState::Failed;
                $this->livenessState = LivenessState::Dead;
                $this->reconnectStopReason = RuntimeReconnectStopReason::HandshakeProtocolFailure;
                $this->markReconnectTerminallyStopped();
                $this->notifyLifecycleChange();
                $this->recordError($error);
                $this->cancelConnectTimeout();
                $this->settleConnectFailure($error);
                $this->supervisionEnabled = false;
                $this->suppressReconnectOnNextClose = true;
            } else {
                $this->recordError($e);
            }

            if ($this->connection !== null) {
                $this->connection->close();
            }
        });
    }

    private function wireHeartbeat(): void
    {
        $this->heartbeat->onStateChange(function (LivenessState $newState): void {
            $this->livenessState = $newState;
            $this->notifyLifecycleChange();

            if ($newState === LivenessState::Dead && $this->connection !== null && !$this->draining) {
                $error = new ConnectionLostException('Heartbeat liveness window expired');
                $this->recordError($error);
                $this->pendingCloseReason = $error;
                $connection = $this->connection;
                \assert($connection instanceof ConnectionInterface);
                $connection->close();
            }
        });

        $this->heartbeat->setProbeCallback(function (): void {
            if (
                !$this->connectionState->canAcceptCommands()
                || $this->draining
                || $this->connection === null
                || $this->commands->totalPendingCount() > 0
            ) {
                return;
            }

            $this->commands->dispatch(
                new ApiCommand('status'),
                'api status',
                $this->config->commandTimeout->apiTimeoutSeconds,
            )->then(
                static fn (): null => null,
                function (\Throwable $e): void {
                    $this->recordError($e);
                },
            );
        });
    }

    private function attachConnection(ConnectionInterface $connection): void
    {
        $this->connection = $connection;
        $this->connectionGeneration++;
        $this->connectionState = ConnectionState::Connected;
        $this->sessionState = SessionState::NotStarted;
        $this->livenessState = LivenessState::Degraded;
        $this->notifyLifecycleChange();
        $this->outbound->attach($connection);
        $this->envelopePump->attach($connection);

        $connection->on('close', function (): void {
            $this->handleConnectionClosed();
        });
        $connection->on('error', function (\Throwable $e): void {
            $this->recordError($e);
            $this->pendingCloseReason = $e;
        });
    }

    private function handleConnectionClosed(?\Throwable $reason = null): void
    {
        $reason ??= $this->pendingCloseReason;
        $this->pendingCloseReason = null;
        $disconnectReason = $reason ?? new ConnectionLostException();
        $willScheduleReconnect = $this->shouldScheduleReconnect($disconnectReason);
        $this->cancelConnectTimeout();
        $this->cancelDrainTimers();
        $this->heartbeat->stop();
        $this->envelopePump->detach();
        $this->outbound->detach();
        $this->connection = null;
        $this->subscriptions->invalidateObservedState();
        $this->connectionState = $this->draining ? ConnectionState::Closed : ConnectionState::Disconnected;
        if (!$this->draining && !$willScheduleReconnect) {
            $this->reconnectPhase = RuntimeReconnectPhase::Idle;
        }
        if ($this->sessionState !== SessionState::Failed) {
            $this->sessionState = SessionState::Disconnected;
        }
        $this->livenessState = LivenessState::Dead;
        $this->recordDisconnectObservation($reason);
        if (!$willScheduleReconnect) {
            $this->notifyLifecycleChange();
        }
        if ($this->shouldKeepBgapiPendingAcrossDisconnect($disconnectReason)) {
            $this->commands->onConnectionLost();
            $this->bgapiTracker->retainPendingAcrossReconnect();
        } else {
            $this->commands->abortAll($disconnectReason);
            $this->bgapi->terminateAll($disconnectReason);
        }

        if ($this->connectDeferred !== null) {
            if ($willScheduleReconnect) {
                $this->scheduleReconnect($disconnectReason);
            } else {
                $this->reconnectPhase = RuntimeReconnectPhase::Idle;
                $this->settleConnectFailure($disconnectReason);
            }
        } elseif ($willScheduleReconnect) {
            $this->scheduleReconnect($disconnectReason);
        } else {
            $this->reconnectPhase = RuntimeReconnectPhase::Idle;
        }

        if ($this->disconnectDeferred !== null) {
            $disconnect = $this->disconnectDeferred;
            $this->disconnectDeferred = null;
            $disconnect->resolve(null);
        }

        if ($this->connectionState === ConnectionState::Closed && $this->draining) {
            $this->draining = false;
            $this->notifyLifecycleChange();
        }

        $this->suppressReconnectOnNextClose = false;
    }

    private function settleConnectFailure(\Throwable $e): void
    {
        $connect = $this->connectDeferred;
        $this->connectDeferred = null;
        $connect?->reject($e);
        $this->notifyLifecycleChange();
    }

    private function recordError(\Throwable $e): void
    {
        $this->lastFailureAtMicros = microtime(true) * 1_000_000.0;
        $this->health?->recordError($e);
        $this->notifyLifecycleChange();
    }

    private function startConnectTimeout(): void
    {
        $this->cancelConnectTimeout();
        $timeoutSeconds = $this->config->commandTimeout->apiTimeoutSeconds;
        $this->connectTimeoutTimer = $this->loop->addTimer($timeoutSeconds, function () use ($timeoutSeconds): void {
            if (
                $this->connectDeferred === null
                && !in_array(
                    $this->connectionState,
                    [
                        ConnectionState::Connecting,
                        ConnectionState::Connected,
                        ConnectionState::Authenticating,
                        ConnectionState::Reconnecting,
                    ],
                    true,
                )
            ) {
                return;
            }

            $error = new CommandTimeoutException('connect/auth handshake', $timeoutSeconds);
            $this->connectionState = ConnectionState::Disconnected;
            $this->sessionState = SessionState::Failed;
            $this->livenessState = LivenessState::Dead;
            $this->reconnectStopReason = RuntimeReconnectStopReason::HandshakeTimeout;
            $this->markReconnectTerminallyStopped();
            $this->notifyLifecycleChange();
            $this->recordError($error);
            $this->supervisionEnabled = false;
            $this->suppressReconnectOnNextClose = true;
            $this->settleConnectFailure($error);

            if ($this->connection !== null) {
                $this->connection->close();
            }
        });
    }

    private function cancelConnectTimeout(): void
    {
        if ($this->connectTimeoutTimer === null) {
            return;
        }

        $this->loop->cancelTimer($this->connectTimeoutTimer);
        $this->connectTimeoutTimer = null;
    }

    /**
     * @return PromiseInterface<void>
     */
    private function resolvedVoid(): PromiseInterface
    {
        /** @var PromiseInterface<void> $promise */
        $promise = resolve(null);

        return $promise;
    }

    private function startConnectionAttempt(): void
    {
        $this->draining = false;
        $this->commands->exitDrainMode();
        $this->subscriptions->invalidateObservedState();
        $this->reconnectPhase = $this->reconnects->attempts() > 0
            ? RuntimeReconnectPhase::AttemptingReconnect
            : RuntimeReconnectPhase::Idle;
        $this->connectionState = ConnectionState::Connecting;
        $this->sessionState = SessionState::NotStarted;
        $this->livenessState = LivenessState::Dead;
        $this->reconnectStopReason = null;
        $this->reconnectTerminalStoppedAtMicros = null;
        $this->notifyLifecycleChange();
        $this->cancelConnectTimeout();
        $this->startConnectTimeout();
        $this->connector->connect($this->connectionUri)->then(
            function (ConnectionInterface $connection): void {
                if ($this->connectionState === ConnectionState::Closed || !$this->supervisionEnabled) {
                    $connection->close();
                    return;
                }

                $this->attachConnection($connection);
            },
            function (\Throwable $e): void {
                $this->cancelConnectTimeout();
                $error = new ConnectionException(
                    sprintf('Failed to connect to %s: %s', $this->connectionUri, $e->getMessage()),
                    0,
                    $e,
                );
                $this->recordError($error);
                $this->livenessState = LivenessState::Dead;
                $this->reconnectStopReason = null;
                $this->notifyLifecycleChange();

                if ($this->shouldScheduleReconnect($error)) {
                    $this->scheduleReconnect($error);
                    return;
                }

                $this->reconnectPhase = RuntimeReconnectPhase::Idle;
                $this->reconnectStopReason = $this->classifyTerminalStopReasonAfterConnectFailure();
                $this->markReconnectTerminallyStopped();
                $this->connectionState = ConnectionState::Disconnected;
                $this->sessionState = SessionState::Failed;
                $this->notifyLifecycleChange();
                $this->settleConnectFailure($error);
            },
        );
    }

    private function shouldScheduleReconnect(\Throwable $reason): bool
    {
        if ($this->draining || !$this->supervisionEnabled || $this->suppressReconnectOnNextClose) {
            return false;
        }

        return !$reason instanceof AuthenticationException;
    }

    private function scheduleReconnect(\Throwable $reason): void
    {
        if ($this->reconnectScheduled) {
            return;
        }

        if ($this->config->retryPolicy->hasExhausted($this->reconnects->attempts())) {
            $this->reconnectPhase = RuntimeReconnectPhase::Exhausted;
            $this->reconnectStopReason = $this->config->retryPolicy->enabled
                ? RuntimeReconnectStopReason::RetryExhausted
                : RuntimeReconnectStopReason::RetryDisabled;
            $this->markReconnectTerminallyStopped();
            $this->connectionState = ConnectionState::Disconnected;
            if ($this->sessionState !== SessionState::Failed) {
                $this->sessionState = SessionState::Disconnected;
            }
            $this->livenessState = LivenessState::Dead;
            $this->notifyLifecycleChange();
            $this->settleConnectFailure($reason);
            return;
        }

        $this->connectionState = ConnectionState::Reconnecting;
        $this->reconnectPhase = RuntimeReconnectPhase::WaitingToRetry;
        $this->reconnectStopReason = null;
        $this->reconnectTerminalStoppedAtMicros = null;
        if ($this->sessionState !== SessionState::Failed) {
            $this->sessionState = SessionState::Disconnected;
        }
        $this->livenessState = LivenessState::Dead;
        $this->notifyLifecycleChange();
        $this->reconnectScheduled = true;
        $this->reconnects->scheduleNext(function (): void {
            $this->reconnectScheduled = false;

            if (!$this->supervisionEnabled || $this->draining || $this->connectionState === ConnectionState::Closed) {
                $this->reconnectPhase = RuntimeReconnectPhase::Idle;
                return;
            }

            $this->startConnectionAttempt();
        });

        if (!$this->reconnectScheduled && $this->config->retryPolicy->hasExhausted($this->reconnects->attempts())) {
            $this->reconnectPhase = RuntimeReconnectPhase::Exhausted;
            $this->reconnectStopReason = $this->config->retryPolicy->enabled
                ? RuntimeReconnectStopReason::RetryExhausted
                : RuntimeReconnectStopReason::RetryDisabled;
            $this->markReconnectTerminallyStopped();
            $this->connectionState = ConnectionState::Disconnected;
            $this->notifyLifecycleChange();
            $this->settleConnectFailure($reason);
        }
    }

    /**
     * @return PromiseInterface<void>
     */
    private function restoreDesiredStateAfterAuthentication(): PromiseInterface
    {
        if ($this->reconnects->attempts() > 0) {
            $this->reconnectPhase = RuntimeReconnectPhase::RestoringSession;
            $this->reconnectStopReason = null;
            $this->reconnectTerminalStoppedAtMicros = null;
            $this->notifyLifecycleChange();
        }

        return $this->subscriptions->restoreDesiredState();
    }

    private function shouldKeepBgapiPendingAcrossDisconnect(\Throwable $reason): bool
    {
        if ($this->draining || !$this->supervisionEnabled || $this->suppressReconnectOnNextClose) {
            return false;
        }

        return !$reason instanceof AuthenticationException;
    }

    private function markRuntimeLive(): void
    {
        $this->reconnects->recordSuccess();
        $this->reconnectScheduled = false;
        $this->reconnectPhase = RuntimeReconnectPhase::Idle;
        $this->reconnectStopReason = null;
        $this->reconnectTerminalStoppedAtMicros = null;
        $this->connectionState = ConnectionState::Authenticated;
        $this->sessionState = SessionState::Active;
        $this->heartbeat->reset();
        $this->heartbeat->recordActivity();
        $this->heartbeat->start();
        $this->livenessState = $this->heartbeat->state();
        $this->lastSuccessfulConnectAtMicros = microtime(true) * 1_000_000.0;
        $this->notifyLifecycleChange();
    }

    private function assertCanAcceptNewWork(): void
    {
        if ($this->draining) {
            throw new DrainException();
        }

        if (!$this->connectionState->canAcceptCommands()) {
            throw new ConnectionException('Runtime is not authenticated');
        }

        if ($this->isOverloaded()) {
            throw new BackpressureException(sprintf(
                'Runtime overloaded (%d inflight, limit %d)',
                $this->totalInflightWorkCount(),
                $this->config->backpressure->maxInflightCommands,
            ));
        }
    }

    private function classifyTerminalStopReasonAfterConnectFailure(): RuntimeReconnectStopReason
    {
        return $this->config->retryPolicy->enabled
            ? RuntimeReconnectStopReason::RetryExhausted
            : RuntimeReconnectStopReason::RetryDisabled;
    }

    private function markReconnectTerminallyStopped(): void
    {
        if ($this->reconnectTerminalStoppedAtMicros === null) {
            $this->reconnectTerminalStoppedAtMicros = microtime(true) * 1_000_000.0;
        }
    }

    private function recordDisconnectObservation(?\Throwable $reason = null): void
    {
        $this->lastDisconnectAtMicros = microtime(true) * 1_000_000.0;
        $this->lastDisconnectReasonClass = $reason !== null ? get_class($reason) : null;
        $this->lastDisconnectReasonMessage = $reason?->getMessage();
    }

    private function startDrainTimers(): void
    {
        $this->cancelDrainTimers();
        $this->drainPollTimer = $this->loop->addPeriodicTimer(0.01, function (): void {
            $this->maybeFinalizeDrain();
        });

        $timeoutSeconds = $this->config->backpressure->drainTimeoutSeconds;
        $this->drainDeadlineTimer = $this->loop->addTimer($timeoutSeconds, function () use ($timeoutSeconds): void {
            if (!$this->draining) {
                return;
            }

            $reason = new DrainException(sprintf(
                'Drain deadline expired after %.2f seconds with %d inflight work items remaining',
                $timeoutSeconds,
                $this->totalInflightWorkCount(),
            ));
            $this->recordError($reason);
            $this->commands->abortAll($reason);
            $this->bgapi->terminateAll($reason);
            $this->closeAfterDrain();
        });
    }

    private function maybeFinalizeDrain(): void
    {
        if (!$this->draining || $this->connection === null) {
            return;
        }

        if ($this->totalInflightWorkCount() > 0) {
            return;
        }

        $this->closeAfterDrain();
    }

    private function closeAfterDrain(): void
    {
        if ($this->connection === null) {
            return;
        }

        $this->cancelDrainTimers();

        try {
            $this->outbound->dispatch(new ExitCommand());
        } catch (\Throwable $e) {
            $this->recordError($e);
        }

        $connection = $this->connection;
        \assert($connection instanceof ConnectionInterface);
        $connection->end();
    }

    private function cancelDrainTimers(): void
    {
        if ($this->drainDeadlineTimer !== null) {
            $this->loop->cancelTimer($this->drainDeadlineTimer);
            $this->drainDeadlineTimer = null;
        }

        if ($this->drainPollTimer !== null) {
            $this->loop->cancelTimer($this->drainPollTimer);
            $this->drainPollTimer = null;
        }
    }

    private function notifyLifecycleChange(): void
    {
        foreach ($this->lifecycleListeners as $listener) {
            try {
                $listener();
            } catch (\Throwable $e) {
                fwrite(STDERR, sprintf(
                    "[esl-react] Runtime lifecycle listener exception: %s\n",
                    $e->getMessage(),
                ));
            }
        }
    }
}
