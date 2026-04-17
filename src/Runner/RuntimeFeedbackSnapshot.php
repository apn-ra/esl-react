<?php declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Health\HealthSnapshot;
use Apntalk\EslReact\Session\SessionState;

/**
 * Read-only integration feedback snapshot for downstream health/reporting.
 *
 * This is a stable convenience layer over the existing HealthSnapshot truth
 * plus prepared runtime identity. It does not add a second control plane.
 */
final class RuntimeFeedbackSnapshot
{
    public function __construct(
        public readonly string $endpoint,
        public readonly ?RuntimeSessionContext $sessionContext,
        public readonly HealthSnapshot $health,
        public readonly RuntimeSubscriptionStateSnapshot $subscriptionState = new RuntimeSubscriptionStateSnapshot(false, [], []),
        public readonly RuntimeObservedSubscriptionStateSnapshot $observedSubscriptionState = new RuntimeObservedSubscriptionStateSnapshot(false, [], [], false),
        public readonly RuntimeReconnectStateSnapshot $reconnectState = new RuntimeReconnectStateSnapshot(),
        public readonly int $activeApiCommandCount = 0,
        public readonly int $queuedApiCommandCount = 0,
        public readonly bool $isReconnectRetryScheduled = false,
    ) {}

    public function identity(): ?RuntimeSessionContext
    {
        return $this->sessionContext;
    }

    public function connectionState(): ConnectionState
    {
        return $this->health->connectionState;
    }

    public function sessionState(): SessionState
    {
        return $this->health->sessionState;
    }

    public function isLive(): bool
    {
        return $this->health->isLive;
    }

    public function isDraining(): bool
    {
        return $this->health->isDraining;
    }

    public function inflightCommandCount(): int
    {
        return $this->health->inflightCommandCount;
    }

    /**
     * Exact active API command count. This is the command currently inflight on
     * the ESL socket and is either 0 or 1 in the current serial command model.
     */
    public function activeApiCommandCount(): int
    {
        return $this->activeApiCommandCount;
    }

    /**
     * Exact queued API command count waiting behind the current inflight API
     * command in the serial command bus.
     */
    public function queuedApiCommandCount(): int
    {
        return $this->queuedApiCommandCount;
    }

    public function pendingBgapiJobCount(): int
    {
        return $this->health->pendingBgapiJobCount;
    }

    public function totalInflightCount(): int
    {
        return $this->health->totalInflightCount;
    }

    public function isOverloaded(): bool
    {
        return $this->health->isOverloaded;
    }

    /**
     * Desired-state subscription feedback. This is an in-memory view, not a
     * transport receipt ledger.
     *
     * @return list<string>
     */
    public function activeSubscriptions(): array
    {
        return $this->health->activeSubscriptions;
    }

    /**
     * Exact desired-state subscription/filter feedback for the current runtime.
     */
    public function subscriptionState(): RuntimeSubscriptionStateSnapshot
    {
        return $this->subscriptionState;
    }

    /**
     * Conservative locally observed-applied subscription/filter state for the
     * current live session. This is invalidated on reconnect/session loss and
     * rebuilt only after successful command replies on the new session.
     */
    public function observedSubscriptionState(): RuntimeObservedSubscriptionStateSnapshot
    {
        return $this->observedSubscriptionState;
    }

    public function reconnectAttempts(): int
    {
        return $this->health->reconnectAttempts;
    }

    /**
     * Stable reconnect/backoff detail packaged from runtime-owned scheduler and
     * lifecycle truth.
     */
    public function reconnectState(): RuntimeReconnectStateSnapshot
    {
        return $this->reconnectState;
    }

    /**
     * Exact reconnect supervisor truth for whether a retry timer is currently
     * pending after an unexpected disconnect.
     */
    public function isReconnectRetryScheduled(): bool
    {
        return $this->isReconnectRetryScheduled;
    }

    public function lastHeartbeatAtMicros(): ?float
    {
        return $this->health->lastHeartbeatAtMicros;
    }
}
