<?php declare(strict_types=1);
namespace Apntalk\EslReact\Runtime;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Session\SessionState;

final class RuntimeState {
    public function __construct(
        public readonly ConnectionState $connectionState,
        public readonly SessionState $sessionState,
        public readonly int $inflightCount,
        public readonly bool $isDraining,
    ) {}

    public function canAcceptCommands(): bool {
        return $this->connectionState->canAcceptCommands() && !$this->isDraining;
    }
}
