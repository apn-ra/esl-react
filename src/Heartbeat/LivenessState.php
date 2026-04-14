<?php declare(strict_types=1);

namespace Apntalk\EslReact\Heartbeat;

enum LivenessState
{
    case Live;      // Heartbeat responses arriving normally
    case Degraded;  // Missed one or more heartbeats but not yet dead
    case Dead;      // No heartbeat response within timeout window

    public function isHealthy(): bool
    {
        return $this === self::Live;
    }
}
