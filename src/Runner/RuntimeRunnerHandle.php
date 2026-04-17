<?php declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

use Apntalk\EslReact\Contracts\AsyncEslClientInterface;
use Apntalk\EslReact\Contracts\RuntimeFeedbackProviderInterface;
use Apntalk\EslReact\Runtime\RuntimeClient;
use React\Promise\PromiseInterface;

final class RuntimeRunnerHandle implements RuntimeFeedbackProviderInterface
{
    /** @var list<callable(RuntimeLifecycleSnapshot): void> */
    private array $lifecycleListeners = [];
    private string $lastLifecycleSignature;
    private RuntimeRunnerState $state = RuntimeRunnerState::Starting;
    private ?\Throwable $startupError = null;

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
            function (\Throwable $e): void {
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

    public function startupError(): ?\Throwable
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

    private function callLifecycleListener(callable $listener, RuntimeLifecycleSnapshot $snapshot): void
    {
        try {
            $listener($snapshot);
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf(
                "[esl-react] Runtime lifecycle listener exception: %s\n",
                $e->getMessage(),
            ));
        }
    }
}
