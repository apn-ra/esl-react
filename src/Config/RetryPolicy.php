<?php declare(strict_types=1);
namespace Apntalk\EslReact\Config;

final class RetryPolicy {
    private function __construct(
        public readonly bool $enabled,
        public readonly int $maxAttempts,
        public readonly float $initialDelaySeconds,
        public readonly float $maxDelaySeconds,
        public readonly float $backoffMultiplier,
    ) {
        if ($this->enabled) {
            if ($this->maxAttempts < 0) {
                throw new \InvalidArgumentException('maxAttempts must be >= 0 (0 = unlimited)');
            }
            if ($this->initialDelaySeconds <= 0) {
                throw new \InvalidArgumentException('initialDelaySeconds must be positive');
            }
            if ($this->maxDelaySeconds < $this->initialDelaySeconds) {
                throw new \InvalidArgumentException('maxDelaySeconds must be >= initialDelaySeconds');
            }
            if ($this->backoffMultiplier < 1.0) {
                throw new \InvalidArgumentException('backoffMultiplier must be >= 1.0');
            }
        }
    }

    public static function default(): self {
        return new self(
            enabled: true,
            maxAttempts: 0,
            initialDelaySeconds: 1.0,
            maxDelaySeconds: 60.0,
            backoffMultiplier: 2.0,
        );
    }

    public static function disabled(): self {
        return new self(
            enabled: false,
            maxAttempts: 0,
            initialDelaySeconds: 1.0,
            maxDelaySeconds: 60.0,
            backoffMultiplier: 2.0,
        );
    }

    public static function withMaxAttempts(int $maxAttempts, float $initialDelay = 1.0): self {
        return new self(
            enabled: true,
            maxAttempts: $maxAttempts,
            initialDelaySeconds: $initialDelay,
            maxDelaySeconds: 60.0,
            backoffMultiplier: 2.0,
        );
    }

    public function delayForAttempt(int $attempt): float {
        if (!$this->enabled || $attempt <= 0) {
            return 0.0;
        }
        $delay = $this->initialDelaySeconds * (($this->backoffMultiplier) ** ($attempt - 1));
        return min($delay, $this->maxDelaySeconds);
    }

    public function hasExhausted(int $attempts): bool {
        if (!$this->enabled) {
            return true;
        }
        return $this->maxAttempts > 0 && $attempts >= $this->maxAttempts;
    }
}
