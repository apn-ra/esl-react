<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

use Apntalk\EslCore\Vocabulary\ReconstructionPosture;
use Apntalk\EslCore\Vocabulary\RecoveryGenerationId;
use Apntalk\EslCore\Vocabulary\ReplayContinuity;
use InvalidArgumentException;

final class PreparedRuntimeRecoveryContext
{
    /**
     * @param array<string, bool|float|int|string|null> $metadata
     * @param list<string> $acceptedOperationIds
     */
    public function __construct(
        private readonly RecoveryGenerationId $generationId,
        private readonly ReconstructionPosture $reconstructionPosture,
        private readonly ReplayContinuity $replayContinuity,
        private readonly bool $recoverableOnlyWithPreparedContext = false,
        private readonly array $acceptedOperationIds = [],
        private readonly array $metadata = [],
        private readonly ?float $preparedAtMicros = null,
    ) {
        if ($this->recoverableOnlyWithPreparedContext && $this->replayContinuity === ReplayContinuity::Continuous) {
            throw new InvalidArgumentException(
                'Prepared recovery context cannot require prepared context while also claiming continuous replay continuity.',
            );
        }

        if (
            $this->reconstructionPosture === ReconstructionPosture::Unsupported
            && in_array($this->replayContinuity, [ReplayContinuity::Continuous, ReplayContinuity::Reconstructed], true)
        ) {
            throw new InvalidArgumentException(
                'Unsupported reconstruction posture cannot claim continuous or reconstructed replay continuity.',
            );
        }

        if (
            $this->replayContinuity === ReplayContinuity::Reconstructed
            && $this->reconstructionPosture === ReconstructionPosture::Native
        ) {
            throw new InvalidArgumentException(
                'Reconstructed replay continuity requires a non-native reconstruction posture.',
            );
        }

        foreach ($this->acceptedOperationIds as $operationId) {
            if (trim($operationId) === '') {
                throw new InvalidArgumentException('Accepted operation IDs must be non-empty.');
            }
        }

        foreach ($this->metadata as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Recovery metadata keys must be strings.');
            }

            if (!is_bool($value) && !is_float($value) && !is_int($value) && !is_string($value) && $value !== null) {
                throw new InvalidArgumentException('Recovery metadata values must be scalar or null.');
            }
        }

        if ($this->preparedAtMicros !== null && $this->preparedAtMicros <= 0) {
            throw new InvalidArgumentException('preparedAtMicros must be a positive microsecond timestamp when provided.');
        }
    }

    public function generationId(): RecoveryGenerationId
    {
        return $this->generationId;
    }

    public function reconstructionPosture(): ReconstructionPosture
    {
        return $this->reconstructionPosture;
    }

    public function replayContinuity(): ReplayContinuity
    {
        return $this->replayContinuity;
    }

    public function recoverableOnlyWithPreparedContext(): bool
    {
        return $this->recoverableOnlyWithPreparedContext;
    }

    /**
     * @return list<string>
     */
    public function acceptedOperationIds(): array
    {
        return $this->acceptedOperationIds;
    }

    /**
     * @return array<string, bool|float|int|string|null>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function preparedAtMicros(): ?float
    {
        return $this->preparedAtMicros;
    }

    public function isExplicitRecoveryBootstrap(): bool
    {
        return $this->reconstructionPosture !== ReconstructionPosture::Native
            || $this->replayContinuity !== ReplayContinuity::Continuous
            || $this->recoverableOnlyWithPreparedContext
            || $this->acceptedOperationIds !== []
            || $this->metadata !== []
            || $this->preparedAtMicros !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generation_id' => $this->generationId->toString(),
            'reconstruction_posture' => $this->reconstructionPosture->value,
            'replay_continuity' => $this->replayContinuity->value,
            'recoverable_only_with_prepared_context' => $this->recoverableOnlyWithPreparedContext,
            'accepted_operation_ids' => $this->acceptedOperationIds,
            'metadata' => $this->metadata,
            'prepared_at_micros' => $this->preparedAtMicros,
        ];
    }
}
