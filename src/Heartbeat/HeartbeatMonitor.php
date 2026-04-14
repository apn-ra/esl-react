<?php declare(strict_types=1);

namespace Apntalk\EslReact\Heartbeat;

use Apntalk\EslReact\Config\HeartbeatConfig;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

final class HeartbeatMonitor
{
    private LivenessState $state = LivenessState::Live;
    private ?TimerInterface $timer = null;
    private ?float $lastHeartbeatAtMicros = null;
    private int $missedCount = 0;

    /** @var list<callable(LivenessState, LivenessState): void> */
    private array $stateChangeListeners = [];

    private ?\Closure $probeCallback = null;

    public function __construct(
        private readonly HeartbeatConfig $config,
        private readonly IdleTimer $idleTimer,
        private readonly LoopInterface $loop,
    ) {}

    public function setProbeCallback(callable $callback): void
    {
        $this->probeCallback = \Closure::fromCallable($callback);
    }

    public function start(): void
    {
        if (!$this->config->enabled || $this->timer !== null) {
            return;
        }
        $this->state = LivenessState::Live;
        $this->missedCount = 0;
        $this->timer = $this->loop->addPeriodicTimer(
            $this->config->intervalSeconds,
            function (): void {
                $this->check();
            },
        );
    }

    public function stop(): void
    {
        if ($this->timer !== null) {
            $this->loop->cancelTimer($this->timer);
            $this->timer = null;
        }
    }

    public function recordActivity(): void
    {
        $this->lastHeartbeatAtMicros = microtime(true) * 1_000_000;
        $this->idleTimer->touch();
        if ($this->state !== LivenessState::Live) {
            $this->transition(LivenessState::Live);
        }
        $this->missedCount = 0;
    }

    public function state(): LivenessState
    {
        return $this->state;
    }

    public function lastHeartbeatAtMicros(): ?float
    {
        return $this->lastHeartbeatAtMicros;
    }

    public function onStateChange(callable $listener): void
    {
        $this->stateChangeListeners[] = $listener;
    }

    public function reset(): void
    {
        $this->stop();
        $this->state = LivenessState::Live;
        $this->missedCount = 0;
        $this->lastHeartbeatAtMicros = null;
    }

    private function check(): void
    {
        if ($this->idleTimer->elapsedSeconds() > $this->config->timeoutSeconds) {
            $this->missedCount++;
            if ($this->missedCount >= 2) {
                $this->transition(LivenessState::Dead);
            } else {
                $this->transition(LivenessState::Degraded);
            }
            if ($this->probeCallback !== null) {
                ($this->probeCallback)();
            }
        } else {
            if ($this->state !== LivenessState::Live) {
                $this->transition(LivenessState::Live);
            }
            $this->missedCount = 0;
        }
    }

    private function transition(LivenessState $newState): void
    {
        if ($newState === $this->state) {
            return;
        }
        $old = $this->state;
        $this->state = $newState;
        foreach ($this->stateChangeListeners as $listener) {
            try {
                $listener($newState, $old);
            } catch (\Throwable) {
            }
        }
    }
}
