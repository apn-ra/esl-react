<?php declare(strict_types=1);
namespace Apntalk\EslReact\Health;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Session\SessionState;

final class HealthSnapshot {
    public function __construct(
        public readonly ConnectionState $connectionState,
        public readonly SessionState $sessionState,
        public readonly bool $isLive,
        public readonly int $inflightCommandCount,
        public readonly int $pendingBgapiJobCount,
        public readonly array $activeSubscriptions,
        public readonly int $reconnectAttempts,
        public readonly bool $isDraining,
        public readonly ?string $lastErrorClass,
        public readonly ?string $lastErrorMessage,
        public readonly float $snapshotAtMicros,
        public readonly ?float $lastHeartbeatAtMicros,
    ) {}

    public static function initial(string $host, int $port): self {
        return new self(
            connectionState: ConnectionState::Disconnected,
            sessionState: SessionState::NotStarted,
            isLive: false,
            inflightCommandCount: 0,
            pendingBgapiJobCount: 0,
            activeSubscriptions: [],
            reconnectAttempts: 0,
            isDraining: false,
            lastErrorClass: null,
            lastErrorMessage: null,
            snapshotAtMicros: microtime(true) * 1_000_000,
            lastHeartbeatAtMicros: null,
        );
    }

    public function isConnected(): bool {
        return $this->connectionState->isConnectedOrAbove();
    }

    public function isAuthenticated(): bool {
        return $this->connectionState === ConnectionState::Authenticated
            || $this->connectionState === ConnectionState::Draining;
    }
}
