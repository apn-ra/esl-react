<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Health\HealthSnapshot;
use Apntalk\EslReact\Session\SessionState;

/**
 * Read-only runner lifecycle observation for higher-layer integrations.
 *
 * This snapshot intentionally reuses the runtime health truth exposed by the
 * client. It does not control reconnects, heartbeats, drain, or session state.
 */
final class RuntimeLifecycleSnapshot
{
    public function __construct(
        public readonly string $endpoint,
        public readonly RuntimeRunnerState $runnerState,
        public readonly ?RuntimeSessionContext $sessionContext,
        public readonly ?HealthSnapshot $health,
        public readonly ?string $startupErrorClass,
        public readonly ?string $startupErrorMessage,
    ) {}

    public function isConnected(): bool
    {
        return $this->health?->isConnected() ?? false;
    }

    public function isStarting(): bool
    {
        return $this->runnerState === RuntimeRunnerState::Starting;
    }

    public function isAuthenticated(): bool
    {
        return $this->health?->isAuthenticated() ?? false;
    }

    public function isLive(): bool
    {
        return $this->health?->isLive ?? false;
    }

    public function isReconnecting(): bool
    {
        return $this->health?->connectionState === ConnectionState::Reconnecting;
    }

    public function isDraining(): bool
    {
        return $this->health?->isDraining ?? false;
    }

    public function isStopped(): bool
    {
        return $this->health?->connectionState === ConnectionState::Closed;
    }

    public function isFailed(): bool
    {
        return $this->runnerState === RuntimeRunnerState::Failed
            || $this->health?->sessionState === SessionState::Failed;
    }

    public function connectionState(): ?ConnectionState
    {
        return $this->health?->connectionState;
    }

    public function sessionState(): ?SessionState
    {
        return $this->health?->sessionState;
    }

    public function reconnectAttempts(): int
    {
        return $this->health?->reconnectAttempts ?? 0;
    }

    public function lastHeartbeatAtMicros(): ?float
    {
        return $this->health?->lastHeartbeatAtMicros;
    }

    public function lastRuntimeErrorClass(): ?string
    {
        return $this->health?->lastErrorClass;
    }

    public function lastRuntimeErrorMessage(): ?string
    {
        return $this->health?->lastErrorMessage;
    }
}
