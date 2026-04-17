<?php declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

final class RuntimeSessionContext
{
    /**
     * @param array<string, bool|float|int|string|null> $metadata
     */
    public function __construct(
        private readonly string $sessionId,
        private readonly array $metadata = [],
    ) {
        if ($this->sessionId === '') {
            throw new \InvalidArgumentException('sessionId must not be empty');
        }

        foreach ($this->metadata as $key => $value) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('metadata keys must be strings');
            }

            if (!is_bool($value) && !is_float($value) && !is_int($value) && !is_string($value) && $value !== null) {
                throw new \InvalidArgumentException('metadata values must be scalar or null');
            }
        }
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * @return array<string, bool|float|int|string|null>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }
}
