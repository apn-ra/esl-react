<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

use Apntalk\EslCore\Vocabulary\InFlightOperationId;
use Apntalk\EslCore\Vocabulary\QueueState;

final class RuntimeOperationSnapshot
{
    public function __construct(
        public readonly InFlightOperationId $operationId,
        public readonly string $kind,
        public readonly QueueState $queueState,
        public readonly int $connectionGeneration,
        public readonly string $recoveryGenerationId,
        public readonly float $acceptedAtMicros,
        public readonly ?float $lastProgressAtMicros = null,
        public readonly ?string $jobUuid = null,
    ) {}

    /**
     * @return array<string, int|float|string|null>
     */
    public function toArray(): array
    {
        return [
            'operation_id' => $this->operationId->toString(),
            'kind' => $this->kind,
            'queue_state' => $this->queueState->value,
            'connection_generation' => $this->connectionGeneration,
            'recovery_generation_id' => $this->recoveryGenerationId,
            'accepted_at_micros' => $this->acceptedAtMicros,
            'last_progress_at_micros' => $this->lastProgressAtMicros,
            'job_uuid' => $this->jobUuid,
        ];
    }
}
