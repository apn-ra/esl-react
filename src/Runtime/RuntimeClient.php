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
use Apntalk\EslReact\Exceptions\CommandTimeoutException;
use Apntalk\EslReact\Exceptions\ConnectionException;
use Apntalk\EslReact\Exceptions\ConnectionLostException;
use Apntalk\EslReact\Health\RuntimeHealthReporter;
use Apntalk\EslReact\Heartbeat\HeartbeatMonitor;
use Apntalk\EslReact\Heartbeat\LivenessState;
use Apntalk\EslReact\Protocol\EnvelopePump;
use Apntalk\EslReact\Protocol\InboundMessageRouter;
use Apntalk\EslReact\Protocol\OutboundMessageDispatcher;
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
    private bool $draining = false;
    private bool $supervisionEnabled = false;
    private bool $suppressReconnectOnNextClose = false;
    private bool $reconnectScheduled = false;
    private ?\Throwable $pendingCloseReason = null;

    public function __construct(
        private readonly RuntimeConfig $config,
        private readonly LoopInterface $loop,
        private readonly ConnectorInterface $connector,
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
    ) {
        $this->wireProtocol();
        $this->wireHeartbeat();
    }

    public function attachHealthReporter(RuntimeHealthReporter $health): void
    {
        $this->health = $health;
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
        $this->supervisionEnabled = true;
        $this->suppressReconnectOnNextClose = false;
        $this->pendingCloseReason = null;
        $this->commands->exitDrainMode();
        $this->connectDeferred = new Deferred();
        $this->reconnects->reset();
        $this->startConnectionAttempt();

        $connect = $this->connectDeferred;
        \assert($connect instanceof Deferred);

        return $connect->promise();
    }

    public function api(string $command, string $args = ''): PromiseInterface
    {
        if (!$this->connectionState->canAcceptCommands()) {
            return reject(new ConnectionException('Runtime is not authenticated'));
        }

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
        if (!$this->connectionState->canAcceptCommands()) {
            throw new ConnectionException('Runtime is not authenticated');
        }

        return $this->bgapi->dispatch($command, $args);
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
            $this->draining = true;
            $this->supervisionEnabled = false;
            $this->reconnects->cancel();
            $this->reconnectScheduled = false;
            $this->heartbeat->stop();
            $this->commands->enterDrainMode();
            $this->cancelConnectTimeout();
            $this->connectionState = ConnectionState::Closed;
            $this->sessionState = SessionState::Disconnected;
            $this->livenessState = LivenessState::Dead;
            $this->settleConnectFailure(new ConnectionLostException('Disconnect requested before auth completed'));

            return $this->resolvedVoid();
        }

        if ($this->connection === null) {
            $this->supervisionEnabled = false;
            $this->reconnects->cancel();
            $this->reconnectScheduled = false;
            $this->heartbeat->stop();
            $this->connectionState = ConnectionState::Closed;
            $this->sessionState = SessionState::Disconnected;
            $this->livenessState = LivenessState::Dead;
            return $this->resolvedVoid();
        }

        $this->draining = true;
        $this->supervisionEnabled = false;
        $this->reconnects->cancel();
        $this->reconnectScheduled = false;
        $this->heartbeat->stop();
        $this->commands->enterDrainMode();
        $this->connectionState = ConnectionState::Draining;
        $this->disconnectDeferred = new Deferred();

        try {
            $this->outbound->dispatch(new ExitCommand());
        } catch (\Throwable $e) {
            $this->recordError($e);
        }

        $connection = $this->connection;
        \assert($connection instanceof ConnectionInterface);
        $connection->end();

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

    public function isDraining(): bool
    {
        return $this->draining;
    }

    public function reconnectAttempts(): int
    {
        return $this->reconnects->attempts();
    }

    private function wireProtocol(): void
    {
        $this->router->onAuthRequest(function (): void {
            if ($this->connection === null) {
                return;
            }

            $this->connectionState = ConnectionState::Authenticating;
            $this->sessionState = SessionState::Authenticating;
            $this->outbound->dispatch(new AuthCommand($this->config->password));
        });

        $this->router->onReply(function ($reply): void {
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
                    $this->connectionState = ConnectionState::Disconnected;
                    $this->sessionState = SessionState::Failed;
                    $this->livenessState = LivenessState::Dead;
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
        $this->connectionState = ConnectionState::Connected;
        $this->sessionState = SessionState::NotStarted;
        $this->livenessState = LivenessState::Degraded;
        $this->outbound->attach($connection);
        $this->envelopePump->attach($connection);

        $connection->on('close', function (): void {
            $this->handleConnectionClosed();
        });
        $connection->on('error', function (\Throwable $e): void {
            $this->recordError($e);
        });
    }

    private function handleConnectionClosed(?\Throwable $reason = null): void
    {
        $reason ??= $this->pendingCloseReason;
        $this->pendingCloseReason = null;
        $this->cancelConnectTimeout();
        $this->heartbeat->stop();
        $this->envelopePump->detach();
        $this->outbound->detach();
        $this->connection = null;
        $this->connectionState = $this->draining ? ConnectionState::Closed : ConnectionState::Disconnected;
        if ($this->sessionState !== SessionState::Failed) {
            $this->sessionState = SessionState::Disconnected;
        }
        $this->livenessState = LivenessState::Dead;
        $this->commands->onConnectionLost();
        $disconnectReason = $reason ?? new ConnectionLostException();
        $this->bgapiTracker->abandonAll($disconnectReason);

        if ($this->connectDeferred !== null) {
            if ($this->shouldScheduleReconnect($disconnectReason)) {
                $this->scheduleReconnect($disconnectReason);
            } else {
                $this->settleConnectFailure($disconnectReason);
            }
        } elseif ($this->shouldScheduleReconnect($disconnectReason)) {
            $this->scheduleReconnect($disconnectReason);
        }

        if ($this->disconnectDeferred !== null) {
            $disconnect = $this->disconnectDeferred;
            $this->disconnectDeferred = null;
            $disconnect->resolve(null);
        }

        $this->suppressReconnectOnNextClose = false;
    }

    private function settleConnectFailure(\Throwable $e): void
    {
        $connect = $this->connectDeferred;
        $this->connectDeferred = null;
        $connect?->reject($e);
    }

    private function recordError(\Throwable $e): void
    {
        $this->health?->recordError($e);
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
        $this->connectionState = ConnectionState::Connecting;
        $this->sessionState = SessionState::NotStarted;
        $this->livenessState = LivenessState::Dead;
        $this->cancelConnectTimeout();
        $this->startConnectTimeout();
        $this->connector->connect($this->config->connectionUri())->then(
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
                    sprintf('Failed to connect to %s: %s', $this->config->connectionUri(), $e->getMessage()),
                    0,
                    $e,
                );
                $this->recordError($error);
                $this->livenessState = LivenessState::Dead;

                if ($this->shouldScheduleReconnect($error)) {
                    $this->scheduleReconnect($error);
                    return;
                }

                $this->connectionState = ConnectionState::Disconnected;
                $this->sessionState = SessionState::Failed;
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
            $this->connectionState = ConnectionState::Disconnected;
            if ($this->sessionState !== SessionState::Failed) {
                $this->sessionState = SessionState::Disconnected;
            }
            $this->livenessState = LivenessState::Dead;
            $this->settleConnectFailure($reason);
            return;
        }

        $this->connectionState = ConnectionState::Reconnecting;
        if ($this->sessionState !== SessionState::Failed) {
            $this->sessionState = SessionState::Disconnected;
        }
        $this->livenessState = LivenessState::Dead;
        $this->reconnectScheduled = true;
        $this->reconnects->scheduleNext(function (): void {
            $this->reconnectScheduled = false;

            if (!$this->supervisionEnabled || $this->draining || $this->connectionState === ConnectionState::Closed) {
                return;
            }

            $this->startConnectionAttempt();
        });

        if (!$this->reconnectScheduled && $this->config->retryPolicy->hasExhausted($this->reconnects->attempts())) {
            $this->connectionState = ConnectionState::Disconnected;
            $this->settleConnectFailure($reason);
        }
    }

    /**
     * @return PromiseInterface<void>
     */
    private function restoreDesiredStateAfterAuthentication(): PromiseInterface
    {
        return $this->subscriptions->restoreDesiredState();
    }

    private function markRuntimeLive(): void
    {
        $this->reconnects->recordSuccess();
        $this->reconnectScheduled = false;
        $this->connectionState = ConnectionState::Authenticated;
        $this->sessionState = SessionState::Active;
        $this->heartbeat->reset();
        $this->heartbeat->recordActivity();
        $this->heartbeat->start();
        $this->livenessState = $this->heartbeat->state();
    }
}
