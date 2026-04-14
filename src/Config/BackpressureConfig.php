<?php declare(strict_types=1);
namespace Apntalk\EslReact\Config;

final class BackpressureConfig {
    private function __construct(
        public readonly int $maxInflightCommands,
        public readonly bool $rejectOnOverload,
    ) {
        if ($this->maxInflightCommands < 1) {
            throw new \InvalidArgumentException('maxInflightCommands must be at least 1');
        }
    }

    public static function default(): self {
        return new self(maxInflightCommands: 10, rejectOnOverload: true);
    }

    public static function lenient(): self {
        return new self(maxInflightCommands: 50, rejectOnOverload: true);
    }

    public static function withLimit(int $maxInflight): self {
        return new self(maxInflightCommands: $maxInflight, rejectOnOverload: true);
    }
}
