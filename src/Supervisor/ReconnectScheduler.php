<?php declare(strict_types=1);

namespace Apntalk\EslReact\Supervisor;

use Apntalk\EslReact\Config\RetryPolicy;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

final class ReconnectScheduler
{
    private int $attempts = 0;
    private CircuitState $circuit = CircuitState::Closed;
    private ?TimerInterface $pendingTimer = null;

    public function __construct(
        private readonly RetryPolicy $policy,
        private readonly LoopInterface $loop,
    ) {}

    public function scheduleNext(callable $callback): void
    {
        if (!$this->policy->enabled) {
            $this->circuit = CircuitState::Open;
            return;
        }
        if ($this->policy->hasExhausted($this->attempts)) {
            $this->circuit = CircuitState::Open;
            return;
        }

        $delay = $this->policy->delayForAttempt($this->attempts + 1);
        $this->pendingTimer = $this->loop->addTimer(
            $delay,
            function () use ($callback): void {
                $this->pendingTimer = null;
                $this->attempts++;
                $callback();
            },
        );
    }

    public function recordSuccess(): void
    {
        $this->attempts = 0;
        $this->circuit = CircuitState::Closed;
    }

    public function cancel(): void
    {
        if ($this->pendingTimer !== null) {
            $this->loop->cancelTimer($this->pendingTimer);
            $this->pendingTimer = null;
        }
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function circuit(): CircuitState
    {
        return $this->circuit;
    }

    public function reset(): void
    {
        $this->cancel();
        $this->attempts = 0;
        $this->circuit = CircuitState::Closed;
    }
}
