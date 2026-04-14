<?php declare(strict_types=1);
namespace Apntalk\EslReact\Bgapi;
use React\Promise\PromiseInterface;

final class BgapiJobHandle {
    /**
     * @param PromiseInterface<\Apntalk\EslCore\Events\BackgroundJobEvent> $promise
     */
    public function __construct(
        private readonly string $jobUuid,
        private readonly string $eslCommand,
        private readonly string $eslArgs,
        private readonly float $dispatchedAtMicros,
        private readonly PromiseInterface $promise,
    ) {}

    public function jobUuid(): string {
        return $this->jobUuid;
    }

    public function eslCommand(): string {
        return $this->eslCommand;
    }

    public function eslArgs(): string {
        return $this->eslArgs;
    }

    public function dispatchedAtMicros(): float {
        return $this->dispatchedAtMicros;
    }

    /**
     * Resolves with BackgroundJobEvent when the job completes.
     * Rejects with CommandTimeoutException if the job orphan timeout expires.
     *
     * @return PromiseInterface<\Apntalk\EslCore\Events\BackgroundJobEvent>
     */
    public function promise(): PromiseInterface {
        return $this->promise;
    }
}
