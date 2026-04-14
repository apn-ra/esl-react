<?php declare(strict_types=1);

namespace Apntalk\EslReact\Bgapi;

use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

final class PendingBgapiJob
{
    private bool $settled = false;
    private ?TimerInterface $timeoutTimer = null;

    /**
     * @param Deferred<\Apntalk\EslCore\Events\BackgroundJobEvent> $deferred
     */
    public function __construct(
        private ?string $jobUuid,
        private readonly string $eslCommand,
        private readonly string $eslArgs,
        private readonly Deferred $deferred,
        private readonly float $dispatchedAtMicros,
    ) {}

    public function jobUuid(): ?string
    {
        return $this->jobUuid;
    }

    public function assignJobUuid(string $jobUuid): void
    {
        if ($jobUuid === '') {
            throw new \InvalidArgumentException('jobUuid must not be empty');
        }

        $this->jobUuid = $jobUuid;
    }

    public function eslCommand(): string
    {
        return $this->eslCommand;
    }

    public function eslArgs(): string
    {
        return $this->eslArgs;
    }

    public function dispatchedAtMicros(): float
    {
        return $this->dispatchedAtMicros;
    }

    /**
     * @return PromiseInterface<\Apntalk\EslCore\Events\BackgroundJobEvent>
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

    public function reject(\Throwable $reason): void
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

    public function timeoutTimer(): ?TimerInterface
    {
        return $this->timeoutTimer;
    }
}
