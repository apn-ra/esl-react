<?php declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

use Apntalk\EslReact\Health\HealthSnapshot;

/**
 * Exportable live-runtime status snapshot for downstream supervision/reporting.
 *
 * This packages runtime-owned lifecycle truth only. It does not promise
 * process-level event-loop liveness, durable persistence, or cross-process
 * coordination guarantees.
 */
final class RuntimeStatusSnapshot implements \JsonSerializable
{
    public function __construct(
        public readonly string $endpoint,
        public readonly ?RuntimeSessionContext $sessionContext,
        public readonly RuntimeRunnerState $runnerState,
        public readonly RuntimeStatusPhase $phase,
        public readonly HealthSnapshot $health,
        public readonly RuntimeReconnectStateSnapshot $reconnectState,
        public readonly bool $isRuntimeActive,
        public readonly bool $isRecoveryInProgress,
        public readonly ?float $lastSuccessfulConnectAtMicros,
        public readonly ?float $lastDisconnectAtMicros,
        public readonly ?string $lastDisconnectReasonClass,
        public readonly ?string $lastDisconnectReasonMessage,
        public readonly ?float $lastFailureAtMicros,
        public readonly ?string $lastFailureClass,
        public readonly ?string $lastFailureMessage,
        public readonly ?string $startupErrorClass,
        public readonly ?string $startupErrorMessage,
    ) {}

    public function hasHeartbeatObservation(): bool
    {
        return $this->health->lastHeartbeatAtMicros !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'session_context' => $this->sessionContext?->identityMetadata(),
            'runner_state' => $this->runnerState->value,
            'phase' => $this->phase->value,
            'connection_state' => $this->health->connectionState->value,
            'session_state' => $this->health->sessionState->value,
            'is_runtime_active' => $this->isRuntimeActive,
            'is_recovery_in_progress' => $this->isRecoveryInProgress,
            'is_connected' => $this->health->isConnected(),
            'is_authenticated' => $this->health->isAuthenticated(),
            'is_live' => $this->health->isLive,
            'is_draining' => $this->health->isDraining,
            'has_heartbeat_observation' => $this->hasHeartbeatObservation(),
            'last_heartbeat_at_micros' => $this->health->lastHeartbeatAtMicros,
            'last_successful_connect_at_micros' => $this->lastSuccessfulConnectAtMicros,
            'last_disconnect_at_micros' => $this->lastDisconnectAtMicros,
            'last_disconnect_reason_class' => $this->lastDisconnectReasonClass,
            'last_disconnect_reason_message' => $this->lastDisconnectReasonMessage,
            'last_failure_at_micros' => $this->lastFailureAtMicros,
            'last_failure_class' => $this->lastFailureClass,
            'last_failure_message' => $this->lastFailureMessage,
            'startup_error_class' => $this->startupErrorClass,
            'startup_error_message' => $this->startupErrorMessage,
            'reconnect' => [
                'phase' => $this->reconnectState->phase->value,
                'attempt_number' => $this->reconnectState->attemptNumber,
                'is_retry_scheduled' => $this->reconnectState->isRetryScheduled,
                'backoff_delay_seconds' => $this->reconnectState->backoffDelaySeconds,
                'next_retry_due_at_micros' => $this->reconnectState->nextRetryDueAtMicros,
                'remaining_delay_seconds' => $this->reconnectState->remainingDelaySeconds,
                'is_terminally_stopped' => $this->reconnectState->isTerminallyStopped,
                'is_retry_exhausted' => $this->reconnectState->isRetryExhausted,
                'requires_external_intervention' => $this->reconnectState->requiresExternalIntervention,
                'is_fail_closed_terminal_state' => $this->reconnectState->isFailClosedTerminalState,
                'terminal_stop_reason' => $this->reconnectState->terminalStopReason?->value,
                'terminal_stopped_at_micros' => $this->reconnectState->terminalStoppedAtMicros,
                'last_retry_attempt_started_at_micros' => $this->reconnectState->lastRetryAttemptStartedAtMicros,
                'last_scheduled_retry_due_at_micros' => $this->reconnectState->lastScheduledRetryDueAtMicros,
                'last_scheduled_backoff_delay_seconds' => $this->reconnectState->lastScheduledBackoffDelaySeconds,
                'terminal_stopped_duration_seconds' => $this->reconnectState->terminalStoppedDurationSeconds,
            ],
            'health_snapshot_at_micros' => $this->health->snapshotAtMicros,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
