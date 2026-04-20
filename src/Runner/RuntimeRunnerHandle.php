<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Contracts\AsyncEslClientInterface;
use Apntalk\EslReact\Contracts\RuntimeFeedbackProviderInterface;
use Apntalk\EslReact\Contracts\RuntimeStatusProviderInterface;
use Apntalk\EslReact\Runtime\RuntimeClient;
use Apntalk\EslReact\Session\SessionState;
use React\Promise\PromiseInterface;
use Throwable;

final class RuntimeRunnerHandle implements RuntimeFeedbackProviderInterface, RuntimeStatusProviderInterface
{
    /** @var list<callable(RuntimeLifecycleSnapshot): void> */
    private array $lifecycleListeners = [];
    private string $lastLifecycleSignature;
    private RuntimeRunnerState $state = RuntimeRunnerState::Starting;
    private ?Throwable $startupError = null;

    /**
     * @param PromiseInterface<void> $startupPromise
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly AsyncEslClientInterface $client,
        private readonly PromiseInterface $startupPromise,
        private readonly ?RuntimeSessionContext $sessionContext = null,
    ) {
        $this->lastLifecycleSignature = $this->lifecycleSignature($this->lifecycleSnapshot());

        if ($this->client instanceof RuntimeClient) {
            $this->client->onLifecycleChange(function (): void {
                $this->emitLifecycleChangeIfChanged();
            });
        }

        $this->startupPromise->then(
            function (): void {
                $this->state = RuntimeRunnerState::Running;
                $this->emitLifecycleChangeIfChanged();
            },
            function (Throwable $e): void {
                $this->startupError = $e;
                $this->state = RuntimeRunnerState::Failed;
                $this->emitLifecycleChangeIfChanged();
            },
        );
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function client(): AsyncEslClientInterface
    {
        return $this->client;
    }

    /**
     * @return PromiseInterface<void>
     */
    public function startupPromise(): PromiseInterface
    {
        return $this->startupPromise;
    }

    public function state(): RuntimeRunnerState
    {
        return $this->state;
    }

    public function startupError(): ?Throwable
    {
        return $this->startupError;
    }

    public function sessionContext(): ?RuntimeSessionContext
    {
        return $this->sessionContext;
    }

    public function lifecycleSnapshot(): RuntimeLifecycleSnapshot
    {
        return new RuntimeLifecycleSnapshot(
            endpoint: $this->endpoint,
            runnerState: $this->state,
            sessionContext: $this->sessionContext,
            health: $this->client->health()->snapshot(),
            startupErrorClass: $this->startupError !== null ? get_class($this->startupError) : null,
            startupErrorMessage: $this->startupError?->getMessage(),
        );
    }

    public function feedbackSnapshot(): RuntimeFeedbackSnapshot
    {
        $health = $this->client->health()->snapshot();
        $subscriptionState = new RuntimeSubscriptionStateSnapshot(
            subscribeAll: false,
            eventNames: $health->activeSubscriptions,
            filters: [],
        );
        $observedSubscriptionState = new RuntimeObservedSubscriptionStateSnapshot(
            subscribeAll: false,
            eventNames: [],
            filters: [],
            isCurrentForActiveSession: false,
        );
        $reconnectState = new RuntimeReconnectStateSnapshot();
        $activeApiCommandCount = 0;
        $queuedApiCommandCount = 0;
        $isReconnectRetryScheduled = false;

        if ($this->client instanceof RuntimeClient) {
            $desiredState = $this->client->desiredSubscriptionState();
            $subscriptionState = new RuntimeSubscriptionStateSnapshot(
                subscribeAll: $desiredState['subscribe_all'],
                eventNames: $desiredState['event_names'],
                filters: $desiredState['filters'],
            );
            $observedState = $this->client->observedSubscriptionState();
            $observedSubscriptionState = new RuntimeObservedSubscriptionStateSnapshot(
                subscribeAll: $observedState['subscribe_all'],
                eventNames: $observedState['event_names'],
                filters: $observedState['filters'],
                isCurrentForActiveSession: $observedState['is_current_for_active_session'],
            );
            $reconnectStateData = $this->client->reconnectState();
            $reconnectState = new RuntimeReconnectStateSnapshot(
                phase: RuntimeReconnectPhase::from($reconnectStateData['phase']),
                attemptNumber: $reconnectStateData['attempt_number'],
                isRetryScheduled: $reconnectStateData['is_retry_scheduled'],
                backoffDelaySeconds: $reconnectStateData['backoff_delay_seconds'],
                nextRetryDueAtMicros: $reconnectStateData['next_retry_due_at_micros'],
                remainingDelaySeconds: $reconnectStateData['remaining_delay_seconds'],
                isTerminallyStopped: $reconnectStateData['is_terminally_stopped'],
                isRetryExhausted: $reconnectStateData['is_retry_exhausted'],
                requiresExternalIntervention: $reconnectStateData['requires_external_intervention'],
                isFailClosedTerminalState: $reconnectStateData['is_fail_closed_terminal_state'],
                terminalStopReason: $reconnectStateData['terminal_stop_reason'] !== null
                    ? RuntimeReconnectStopReason::from($reconnectStateData['terminal_stop_reason'])
                    : null,
                terminalStoppedAtMicros: $reconnectStateData['terminal_stopped_at_micros'],
                lastRetryAttemptStartedAtMicros: $reconnectStateData['last_retry_attempt_started_at_micros'],
                lastScheduledRetryDueAtMicros: $reconnectStateData['last_scheduled_retry_due_at_micros'],
                lastScheduledBackoffDelaySeconds: $reconnectStateData['last_scheduled_backoff_delay_seconds'],
                terminalStoppedDurationSeconds: $reconnectStateData['terminal_stopped_duration_seconds'],
            );
            $activeApiCommandCount = $this->client->activeApiCommandCount();
            $queuedApiCommandCount = $this->client->queuedApiCommandCount();
            $isReconnectRetryScheduled = $this->client->isReconnectRetryScheduled();
        }

        return new RuntimeFeedbackSnapshot(
            endpoint: $this->endpoint,
            sessionContext: $this->sessionContext,
            health: $health,
            subscriptionState: $subscriptionState,
            observedSubscriptionState: $observedSubscriptionState,
            reconnectState: $reconnectState,
            activeApiCommandCount: $activeApiCommandCount,
            queuedApiCommandCount: $queuedApiCommandCount,
            isReconnectRetryScheduled: $isReconnectRetryScheduled,
        );
    }

    public function statusSnapshot(): RuntimeStatusSnapshot
    {
        $feedback = $this->feedbackSnapshot();
        $statusObservation = [
            'last_successful_connect_at_micros' => null,
            'last_disconnect_at_micros' => null,
            'last_disconnect_reason_class' => null,
            'last_disconnect_reason_message' => null,
            'last_failure_at_micros' => null,
        ];

        if ($this->client instanceof RuntimeClient) {
            $statusObservation = $this->client->statusObservation();
        }

        return new RuntimeStatusSnapshot(
            endpoint: $this->endpoint,
            sessionContext: $this->sessionContext,
            runnerState: $this->state,
            phase: $this->statusPhase($feedback),
            health: $feedback->health,
            reconnectState: $feedback->reconnectState,
            isRuntimeActive: $this->isRuntimeActive($feedback),
            isRecoveryInProgress: $this->isRecoveryInProgress($feedback),
            lastSuccessfulConnectAtMicros: $statusObservation['last_successful_connect_at_micros'],
            lastDisconnectAtMicros: $statusObservation['last_disconnect_at_micros'],
            lastDisconnectReasonClass: $statusObservation['last_disconnect_reason_class'],
            lastDisconnectReasonMessage: $statusObservation['last_disconnect_reason_message'],
            lastFailureAtMicros: $statusObservation['last_failure_at_micros'],
            lastFailureClass: $feedback->health->lastErrorClass,
            lastFailureMessage: $feedback->health->lastErrorMessage,
            startupErrorClass: $this->startupError !== null ? get_class($this->startupError) : null,
            startupErrorMessage: $this->startupError?->getMessage(),
        );
    }

    public function onLifecycleChange(callable $listener): void
    {
        $this->lifecycleListeners[] = $listener;
        $this->callLifecycleListener($listener, $this->lifecycleSnapshot());
    }

    private function emitLifecycleChangeIfChanged(): void
    {
        $snapshot = $this->lifecycleSnapshot();
        $signature = $this->lifecycleSignature($snapshot);

        if ($signature === $this->lastLifecycleSignature) {
            return;
        }

        $this->lastLifecycleSignature = $signature;

        foreach ($this->lifecycleListeners as $listener) {
            $this->callLifecycleListener($listener, $snapshot);
        }
    }

    private function lifecycleSignature(RuntimeLifecycleSnapshot $snapshot): string
    {
        return json_encode([
            'runnerState' => $snapshot->runnerState->value,
            'sessionId' => $snapshot->sessionContext?->sessionId(),
            'connectionState' => $snapshot->connectionState()?->value,
            'sessionState' => $snapshot->sessionState()?->value,
            'isLive' => $snapshot->isLive(),
            'isReconnecting' => $snapshot->isReconnecting(),
            'isDraining' => $snapshot->isDraining(),
            'isStopped' => $snapshot->isStopped(),
            'isFailed' => $snapshot->isFailed(),
            'reconnectAttempts' => $snapshot->reconnectAttempts(),
            'startupErrorClass' => $snapshot->startupErrorClass,
            'startupErrorMessage' => $snapshot->startupErrorMessage,
            'lastRuntimeErrorClass' => $snapshot->lastRuntimeErrorClass(),
            'lastRuntimeErrorMessage' => $snapshot->lastRuntimeErrorMessage(),
        ], JSON_THROW_ON_ERROR);
    }

    private function statusPhase(RuntimeFeedbackSnapshot $feedback): RuntimeStatusPhase
    {
        $health = $feedback->health;

        if ($this->state === RuntimeRunnerState::Failed || $health->sessionState === SessionState::Failed) {
            return RuntimeStatusPhase::Failed;
        }

        return match ($health->connectionState) {
            ConnectionState::Connecting => $this->state === RuntimeRunnerState::Starting
                ? RuntimeStatusPhase::Starting
                : RuntimeStatusPhase::Connecting,
            ConnectionState::Connected,
            ConnectionState::Authenticating => RuntimeStatusPhase::Authenticating,
            ConnectionState::Authenticated => RuntimeStatusPhase::Active,
            ConnectionState::Reconnecting => RuntimeStatusPhase::Reconnecting,
            ConnectionState::Draining => RuntimeStatusPhase::Draining,
            ConnectionState::Closed => RuntimeStatusPhase::Closed,
            ConnectionState::Disconnected => $this->state === RuntimeRunnerState::Starting
                ? RuntimeStatusPhase::Starting
                : RuntimeStatusPhase::Disconnected,
        };
    }

    private function isRuntimeActive(RuntimeFeedbackSnapshot $feedback): bool
    {
        if ($this->state === RuntimeRunnerState::Failed) {
            return false;
        }

        return $feedback->connectionState() !== ConnectionState::Closed;
    }

    private function isRecoveryInProgress(RuntimeFeedbackSnapshot $feedback): bool
    {
        return in_array(
            $feedback->reconnectState->phase,
            [
                RuntimeReconnectPhase::WaitingToRetry,
                RuntimeReconnectPhase::AttemptingReconnect,
                RuntimeReconnectPhase::RestoringSession,
            ],
            true,
        );
    }

    private function callLifecycleListener(callable $listener, RuntimeLifecycleSnapshot $snapshot): void
    {
        try {
            $listener($snapshot);
        } catch (Throwable $e) {
            fwrite(STDERR, sprintf(
                "[esl-react] Runtime lifecycle listener exception: %s\n",
                $e->getMessage(),
            ));
        }
    }
}
