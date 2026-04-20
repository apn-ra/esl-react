<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Heartbeat;

final class IdleTimer
{
    private float $lastActivityAtMicros;

    public function __construct()
    {
        $this->lastActivityAtMicros = microtime(true) * 1_000_000;
    }

    public function touch(): void
    {
        $this->lastActivityAtMicros = microtime(true) * 1_000_000;
    }

    public function elapsedSeconds(): float
    {
        return (microtime(true) * 1_000_000 - $this->lastActivityAtMicros) / 1_000_000;
    }

    public function lastActivityAtMicros(): float
    {
        return $this->lastActivityAtMicros;
    }

    public function reset(): void
    {
        $this->lastActivityAtMicros = microtime(true) * 1_000_000;
    }
}
