<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Backpressure;

use Throwable;

final class PauseResumeGate
{
    private bool $paused = false;

    /** @var list<callable(): void> */
    private array $resumeListeners = [];

    public function pause(): void
    {
        $this->paused = true;
    }

    public function resume(): void
    {
        if (!$this->paused) {
            return;
        }
        $this->paused = false;
        foreach ($this->resumeListeners as $listener) {
            try {
                $listener();
            } catch (Throwable) {
            }
        }
    }

    public function isPaused(): bool
    {
        return $this->paused;
    }

    public function onResume(callable $listener): void
    {
        $this->resumeListeners[] = $listener;
    }
}
