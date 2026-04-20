<?php

declare(strict_types=1);

namespace Apntalk\EslReact\CommandBus;

use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Throwable;

final class PendingCommand
{
    private bool $settled = false;
    private ?TimerInterface $timeoutTimer = null;

    /**
     * @param Deferred<\Apntalk\EslCore\Contracts\ReplyInterface> $deferred
     */
    public function __construct(
        private readonly string $commandDescription,
        private readonly Deferred $deferred,
        private readonly float $enqueuedAtMicros,
    ) {}

    public function commandDescription(): string
    {
        return $this->commandDescription;
    }

    public function enqueuedAtMicros(): float
    {
        return $this->enqueuedAtMicros;
    }

    /**
     * @return PromiseInterface<\Apntalk\EslCore\Contracts\ReplyInterface>
     */
    public function promise(): PromiseInterface
    {
        return $this->deferred->promise();
    }

    public function isSettled(): bool
    {
        return $this->settled;
    }

    public function resolve(mixed $value): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;
        $this->deferred->resolve($value);
    }

    public function reject(Throwable $reason): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;
        $this->deferred->reject($reason);
    }

    public function attachTimer(TimerInterface $timer): void
    {
        $this->timeoutTimer = $timer;
    }

    public function cancelTimer(callable $cancelFn): void
    {
        if ($this->timeoutTimer !== null) {
            $cancelFn($this->timeoutTimer);
            $this->timeoutTimer = null;
        }
    }
}
