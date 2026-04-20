<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Supervisor;

enum CircuitState
{
    case Closed;    // Normal operation
    case Open;      // Stopped trying (max retries exhausted)
    case HalfOpen;  // Trying one attempt to see if it works

    public function isOpen(): bool
    {
        return $this === self::Open;
    }

    public function allowsAttempt(): bool
    {
        return $this !== self::Open;
    }
}
