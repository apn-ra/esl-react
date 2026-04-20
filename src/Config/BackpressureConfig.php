<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Config;

use InvalidArgumentException;

final class BackpressureConfig
{
    private function __construct(
        public readonly int $maxInflightCommands,
        public readonly bool $rejectOnOverload,
        public readonly float $drainTimeoutSeconds,
    ) {
        if ($this->maxInflightCommands < 1) {
            throw new InvalidArgumentException('maxInflightCommands must be at least 1');
        }
        if ($this->drainTimeoutSeconds <= 0) {
            throw new InvalidArgumentException('drainTimeoutSeconds must be positive');
        }
    }

    public static function default(): self
    {
        return new self(maxInflightCommands: 10, rejectOnOverload: true, drainTimeoutSeconds: 0.25);
    }

    public static function lenient(): self
    {
        return new self(maxInflightCommands: 50, rejectOnOverload: true, drainTimeoutSeconds: 1.0);
    }

    public static function withLimit(int $maxInflight, float $drainTimeoutSeconds = 0.25): self
    {
        return new self(maxInflightCommands: $maxInflight, rejectOnOverload: true, drainTimeoutSeconds: $drainTimeoutSeconds);
    }
}
