<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

use InvalidArgumentException;

final class RuntimeSessionContext
{
    /**
     * @param array<string, bool|float|int|string|null> $metadata
     */
    public function __construct(
        private readonly string $sessionId,
        private readonly array $metadata = [],
        private readonly ?string $workerSessionId = null,
        private readonly ?string $connectionProfile = null,
        private readonly ?string $providerIdentity = null,
        private readonly ?string $connectorIdentity = null,
    ) {
        if ($this->sessionId === '') {
            throw new InvalidArgumentException('sessionId must not be empty');
        }

        foreach ([
            'workerSessionId' => $this->workerSessionId,
            'connectionProfile' => $this->connectionProfile,
            'providerIdentity' => $this->providerIdentity,
            'connectorIdentity' => $this->connectorIdentity,
        ] as $field => $value) {
            if ($value !== null && $value === '') {
                throw new InvalidArgumentException(sprintf('%s must not be empty when provided', $field));
            }
        }

        foreach ($this->metadata as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('metadata keys must be strings');
            }

            if (!is_bool($value) && !is_float($value) && !is_int($value) && !is_string($value) && $value !== null) {
                throw new InvalidArgumentException('metadata values must be scalar or null');
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

    public function workerSessionId(): ?string
    {
        return $this->workerSessionId;
    }

    public function connectionProfile(): ?string
    {
        return $this->connectionProfile;
    }

    public function providerIdentity(): ?string
    {
        return $this->providerIdentity;
    }

    public function connectorIdentity(): ?string
    {
        return $this->connectorIdentity;
    }

    /**
     * @return array<string, bool|float|int|string>
     */
    public function identityMetadata(): array
    {
        return array_filter([
            'runtime_session_id' => $this->sessionId,
            'worker_session_id' => $this->workerSessionId,
            'connection_profile' => $this->connectionProfile,
            'provider_identity' => $this->providerIdentity,
            'connector_identity' => $this->connectorIdentity,
            ...$this->metadata,
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, string>
     */
    public function replayMetadata(): array
    {
        $metadata = [];

        foreach ($this->identityMetadata() as $key => $value) {
            if (is_bool($value)) {
                $metadata[$key] = $value ? 'true' : 'false';
                continue;
            }

            $metadata[$key] = (string) $value;
        }

        return $metadata;
    }
}
