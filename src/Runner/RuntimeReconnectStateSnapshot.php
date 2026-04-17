<?php declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

/**
 * Reconnect/backoff detail for runner-facing feedback.
 *
 * Exact values come from runtime-owned reconnect state and the local retry
 * scheduler. Wall-clock due times and remaining delay are local scheduler
 * estimates and may drift slightly with event-loop execution latency.
 */
final class RuntimeReconnectStateSnapshot
{
    public function __construct(
        public readonly RuntimeReconnectPhase $phase = RuntimeReconnectPhase::Idle,
        public readonly ?int $attemptNumber = null,
        public readonly bool $isRetryScheduled = false,
        public readonly ?float $backoffDelaySeconds = null,
        public readonly ?float $nextRetryDueAtMicros = null,
        public readonly ?float $remainingDelaySeconds = null,
        public readonly bool $isTerminallyStopped = false,
        public readonly bool $isRetryExhausted = false,
        public readonly bool $requiresExternalIntervention = false,
        public readonly bool $isFailClosedTerminalState = false,
        public readonly ?RuntimeReconnectStopReason $terminalStopReason = null,
        public readonly ?float $terminalStoppedAtMicros = null,
        public readonly ?float $lastRetryAttemptStartedAtMicros = null,
        public readonly ?float $lastScheduledRetryDueAtMicros = null,
        public readonly ?float $lastScheduledBackoffDelaySeconds = null,
        public readonly ?float $terminalStoppedDurationSeconds = null,
    ) {}

    public function isIdle(): bool
    {
        return $this->phase === RuntimeReconnectPhase::Idle;
    }

    public function isWaitingToRetry(): bool
    {
        return $this->phase === RuntimeReconnectPhase::WaitingToRetry;
    }

    public function isAttemptingReconnect(): bool
    {
        return $this->phase === RuntimeReconnectPhase::AttemptingReconnect;
    }

    public function isRestoringSession(): bool
    {
        return $this->phase === RuntimeReconnectPhase::RestoringSession;
    }

    public function isExhausted(): bool
    {
        return $this->phase === RuntimeReconnectPhase::Exhausted;
    }

    public function isRecoverableWithoutExternalIntervention(): bool
    {
        return !$this->requiresExternalIntervention;
    }
}
