<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

use Apntalk\EslCore\Vocabulary\LifecycleSemanticObservation;

final class RuntimeLifecycleSemanticSnapshot
{
    public function __construct(
        public readonly LifecycleSemanticObservation $observation,
        public readonly float $observedAtMicros,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'observation' => $this->observation->toArray(),
            'observed_at_micros' => $this->observedAtMicros,
        ];
    }
}
