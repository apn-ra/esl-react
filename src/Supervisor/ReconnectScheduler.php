<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Supervisor;

use Apntalk\EslReact\Config\RetryPolicy;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

final class ReconnectScheduler
{
    private int $attempts = 0;
    private CircuitState $circuit = CircuitState::Closed;
    private ?TimerInterface $pendingTimer = null;
    private ?int $scheduledAttemptNumber = null;
    private ?float $scheduledDelaySeconds = null;
    private ?float $scheduledAtMicros = null;
    private ?int $lastScheduledAttemptNumber = null;
    private ?float $lastScheduledDelaySeconds = null;
    private ?float $lastScheduledAtMicros = null;
    private ?float $lastScheduledDueAtMicros = null;
    private ?float $lastRetryAttemptStartedAtMicros = null;

    public function __construct(
        private readonly RetryPolicy $policy,
        private readonly LoopInterface $loop,
    ) {}

    public function scheduleNext(callable $callback): void
    {
        if (!$this->policy->enabled) {
            $this->clearPendingSchedule();
            $this->circuit = CircuitState::Open;
            return;
        }
        if ($this->policy->hasExhausted($this->attempts)) {
            $this->clearPendingSchedule();
            $this->circuit = CircuitState::Open;
            return;
        }

        $attemptNumber = $this->attempts + 1;
        $delay = $this->policy->delayForAttempt($attemptNumber);
        $scheduledAtMicros = microtime(true) * 1_000_000.0;
        $dueAtMicros = $scheduledAtMicros + ($delay * 1_000_000.0);
        $this->scheduledAttemptNumber = $attemptNumber;
        $this->scheduledDelaySeconds = $delay;
        $this->scheduledAtMicros = $scheduledAtMicros;
        $this->lastScheduledAttemptNumber = $attemptNumber;
        $this->lastScheduledDelaySeconds = $delay;
        $this->lastScheduledAtMicros = $scheduledAtMicros;
        $this->lastScheduledDueAtMicros = $dueAtMicros;
        $this->pendingTimer = $this->loop->addTimer(
            $delay,
            function () use ($callback): void {
                $this->pendingTimer = null;
                $this->attempts++;
                $this->lastRetryAttemptStartedAtMicros = microtime(true) * 1_000_000.0;
                $this->clearPendingSchedule();
                $callback();
            },
        );
    }

    public function recordSuccess(): void
    {
        $this->attempts = 0;
        $this->circuit = CircuitState::Closed;
        $this->clearPendingSchedule();
    }

    public function cancel(): void
    {
        if ($this->pendingTimer !== null) {
            $this->loop->cancelTimer($this->pendingTimer);
            $this->pendingTimer = null;
        }

        $this->clearPendingSchedule();
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

    public function hasPendingTimer(): bool
    {
        return $this->pendingTimer !== null;
    }

    public function scheduledAttemptNumber(): ?int
    {
        return $this->scheduledAttemptNumber;
    }

    public function scheduledDelaySeconds(): ?float
    {
        return $this->scheduledDelaySeconds;
    }

    public function nextRetryDueAtMicros(): ?float
    {
        if ($this->scheduledAtMicros === null || $this->scheduledDelaySeconds === null) {
            return null;
        }

        return $this->scheduledAtMicros + ($this->scheduledDelaySeconds * 1_000_000.0);
    }

    public function remainingDelaySeconds(): ?float
    {
        $dueAtMicros = $this->nextRetryDueAtMicros();
        if ($dueAtMicros === null) {
            return null;
        }

        return max(0.0, ($dueAtMicros - (microtime(true) * 1_000_000.0)) / 1_000_000.0);
    }

    public function lastScheduledAttemptNumber(): ?int
    {
        return $this->lastScheduledAttemptNumber;
    }

    public function lastScheduledDelaySeconds(): ?float
    {
        return $this->lastScheduledDelaySeconds;
    }

    public function lastScheduledAtMicros(): ?float
    {
        return $this->lastScheduledAtMicros;
    }

    public function lastScheduledDueAtMicros(): ?float
    {
        return $this->lastScheduledDueAtMicros;
    }

    public function lastRetryAttemptStartedAtMicros(): ?float
    {
        return $this->lastRetryAttemptStartedAtMicros;
    }

    private function clearPendingSchedule(): void
    {
        $this->scheduledAttemptNumber = null;
        $this->scheduledDelaySeconds = null;
        $this->scheduledAtMicros = null;
    }
}
