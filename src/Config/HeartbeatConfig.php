<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Config;

use InvalidArgumentException;

final class HeartbeatConfig
{
    private function __construct(
        public readonly bool $enabled,
        public readonly float $intervalSeconds,
        public readonly float $timeoutSeconds,
    ) {
        if ($this->enabled) {
            if ($this->intervalSeconds <= 0) {
                throw new InvalidArgumentException('intervalSeconds must be positive');
            }
            if ($this->timeoutSeconds <= 0) {
                throw new InvalidArgumentException('timeoutSeconds must be positive');
            }
            if ($this->timeoutSeconds >= $this->intervalSeconds) {
                throw new InvalidArgumentException('timeoutSeconds must be less than intervalSeconds');
            }
        }
    }

    public static function default(): self
    {
        return new self(enabled: true, intervalSeconds: 30.0, timeoutSeconds: 10.0);
    }

    public static function disabled(): self
    {
        return new self(enabled: false, intervalSeconds: 30.0, timeoutSeconds: 10.0);
    }

    public static function withInterval(float $intervalSeconds, float $timeoutSeconds = 10.0): self
    {
        return new self(enabled: true, intervalSeconds: $intervalSeconds, timeoutSeconds: $timeoutSeconds);
    }
}
