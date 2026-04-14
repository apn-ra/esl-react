<?php declare(strict_types=1);
namespace Apntalk\EslReact\Session;
use Apntalk\EslCore\Correlation\ConnectionSessionId;

final class SessionMetadata {
    private function __construct(
        public readonly ConnectionSessionId $sessionId,
        public readonly string $host,
        public readonly int $port,
        public readonly ?float $connectedAtMicros,
        public readonly ?float $authenticatedAtMicros,
    ) {}

    public static function create(
        ConnectionSessionId $sessionId,
        string $host,
        int $port,
    ): self {
        return new self($sessionId, $host, $port, null, null);
    }

    public function withConnectedAt(float $microtime): self {
        return new self($this->sessionId, $this->host, $this->port, $microtime, $this->authenticatedAtMicros);
    }

    public function withAuthenticatedAt(float $microtime): self {
        return new self($this->sessionId, $this->host, $this->port, $this->connectedAtMicros, $microtime);
    }

    public function connectedElapsedSeconds(): ?float {
        if ($this->connectedAtMicros === null) {
            return null;
        }
        return (microtime(true) - ($this->connectedAtMicros / 1_000_000));
    }
}
