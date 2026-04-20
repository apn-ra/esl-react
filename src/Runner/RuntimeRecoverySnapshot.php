<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

use Apntalk\EslCore\Vocabulary\DrainPosture;
use Apntalk\EslCore\Vocabulary\ReconstructionPosture;
use Apntalk\EslCore\Vocabulary\RecoveryGenerationId;
use Apntalk\EslCore\Vocabulary\ReplayContinuity;
use Apntalk\EslCore\Vocabulary\RetryPosture;

final class RuntimeRecoverySnapshot
{
    public function __construct(
        public readonly RecoveryGenerationId $generationId,
        public readonly int $connectionGeneration,
        public readonly RetryPosture $retryPosture,
        public readonly DrainPosture $drainPosture,
        public readonly ReconstructionPosture $reconstructionPosture,
        public readonly ReplayContinuity $replayContinuity,
        public readonly bool $preparedContextApplied = false,
        public readonly bool $isRecoverableAfterReconnect = false,
        public readonly bool $isRecoverableOnlyWithPreparedContext = false,
        public readonly bool $isTerminallyNonRecoverable = false,
        public readonly ?string $lastRecoveryCause = null,
        public readonly ?string $lastRecoveryOutcome = null,
        public readonly ?string $lastDrainCause = null,
        public readonly ?string $lastDrainOutcome = null,
        public readonly ?float $generationStartedAtMicros = null,
        public readonly ?PreparedRuntimeRecoveryContext $preparedContext = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generation_id' => $this->generationId->toString(),
            'connection_generation' => $this->connectionGeneration,
            'retry_posture' => $this->retryPosture->value,
            'drain_posture' => $this->drainPosture->value,
            'reconstruction_posture' => $this->reconstructionPosture->value,
            'replay_continuity' => $this->replayContinuity->value,
            'prepared_context_applied' => $this->preparedContextApplied,
            'is_recoverable_after_reconnect' => $this->isRecoverableAfterReconnect,
            'is_recoverable_only_with_prepared_context' => $this->isRecoverableOnlyWithPreparedContext,
            'is_terminally_non_recoverable' => $this->isTerminallyNonRecoverable,
            'last_recovery_cause' => $this->lastRecoveryCause,
            'last_recovery_outcome' => $this->lastRecoveryOutcome,
            'last_drain_cause' => $this->lastDrainCause,
            'last_drain_outcome' => $this->lastDrainOutcome,
            'generation_started_at_micros' => $this->generationStartedAtMicros,
            'prepared_context' => $this->preparedContext?->toArray(),
        ];
    }
}
