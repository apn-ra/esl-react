<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Health;

use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Contracts\HealthReporterInterface;
use Apntalk\EslReact\Heartbeat\LivenessState;
use Closure;
use Throwable;

final class RuntimeHealthReporter implements HealthReporterInterface
{
    private Closure $connectionStateProvider;

    private Closure $sessionStateProvider;

    private Closure $livenessProvider;

    private Closure $inflightCountProvider;

    private Closure $bgapiPendingCountProvider;

    private Closure $totalInflightCountProvider;

    private Closure $overloadedProvider;

    private Closure $subscriptionsProvider;

    private Closure $reconnectAttemptsProvider;

    private Closure $drainingProvider;

    private Closure $lastHeartbeatProvider;

    private ?string $lastErrorClass = null;
    private ?string $lastErrorMessage = null;

    public function __construct(
        Closure $connectionStateProvider,
        Closure $sessionStateProvider,
        Closure $livenessProvider,
        Closure $inflightCountProvider,
        Closure $bgapiPendingCountProvider,
        Closure $totalInflightCountProvider,
        Closure $overloadedProvider,
        Closure $subscriptionsProvider,
        Closure $reconnectAttemptsProvider,
        Closure $drainingProvider,
        Closure $lastHeartbeatProvider,
    ) {
        $this->connectionStateProvider = $connectionStateProvider;
        $this->sessionStateProvider = $sessionStateProvider;
        $this->livenessProvider = $livenessProvider;
        $this->inflightCountProvider = $inflightCountProvider;
        $this->bgapiPendingCountProvider = $bgapiPendingCountProvider;
        $this->totalInflightCountProvider = $totalInflightCountProvider;
        $this->overloadedProvider = $overloadedProvider;
        $this->subscriptionsProvider = $subscriptionsProvider;
        $this->reconnectAttemptsProvider = $reconnectAttemptsProvider;
        $this->drainingProvider = $drainingProvider;
        $this->lastHeartbeatProvider = $lastHeartbeatProvider;
    }

    public function recordError(Throwable $e): void
    {
        $this->lastErrorClass = get_class($e);
        $this->lastErrorMessage = $e->getMessage();
    }

    public function snapshot(): HealthSnapshot
    {
        return new HealthSnapshot(
            connectionState: ($this->connectionStateProvider)(),
            sessionState: ($this->sessionStateProvider)(),
            isLive: ($this->livenessProvider)() === LivenessState::Live,
            inflightCommandCount: ($this->inflightCountProvider)(),
            pendingBgapiJobCount: ($this->bgapiPendingCountProvider)(),
            totalInflightCount: ($this->totalInflightCountProvider)(),
            isOverloaded: ($this->overloadedProvider)(),
            activeSubscriptions: ($this->subscriptionsProvider)(),
            reconnectAttempts: ($this->reconnectAttemptsProvider)(),
            isDraining: ($this->drainingProvider)(),
            lastErrorClass: $this->lastErrorClass,
            lastErrorMessage: $this->lastErrorMessage,
            snapshotAtMicros: microtime(true) * 1_000_000,
            lastHeartbeatAtMicros: ($this->lastHeartbeatProvider)(),
        );
    }

    public function isConnected(): bool
    {
        return ($this->connectionStateProvider)()->isConnectedOrAbove();
    }

    public function isAuthenticated(): bool
    {
        $state = ($this->connectionStateProvider)();
        return $state === ConnectionState::Authenticated || $state === ConnectionState::Draining;
    }

    public function isLive(): bool
    {
        return ($this->livenessProvider)() === LivenessState::Live;
    }
}
