<?php declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

use Apntalk\EslReact\Contracts\AsyncEslClientInterface;
use React\Promise\PromiseInterface;

final class RuntimeRunnerHandle
{
    private RuntimeRunnerState $state = RuntimeRunnerState::Starting;
    private ?\Throwable $startupError = null;

    /**
     * @param PromiseInterface<void> $startupPromise
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly AsyncEslClientInterface $client,
        private readonly PromiseInterface $startupPromise,
        private readonly ?RuntimeSessionContext $sessionContext = null,
    ) {
        $this->startupPromise->then(
            function (): void {
                $this->state = RuntimeRunnerState::Running;
            },
            function (\Throwable $e): void {
                $this->startupError = $e;
                $this->state = RuntimeRunnerState::Failed;
            },
        );
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function client(): AsyncEslClientInterface
    {
        return $this->client;
    }

    /**
     * @return PromiseInterface<void>
     */
    public function startupPromise(): PromiseInterface
    {
        return $this->startupPromise;
    }

    public function state(): RuntimeRunnerState
    {
        return $this->state;
    }

    public function startupError(): ?\Throwable
    {
        return $this->startupError;
    }

    public function sessionContext(): ?RuntimeSessionContext
    {
        return $this->sessionContext;
    }

    public function lifecycleSnapshot(): RuntimeLifecycleSnapshot
    {
        return new RuntimeLifecycleSnapshot(
            endpoint: $this->endpoint,
            runnerState: $this->state,
            sessionContext: $this->sessionContext,
            health: $this->client->health()->snapshot(),
            startupErrorClass: $this->startupError !== null ? get_class($this->startupError) : null,
            startupErrorMessage: $this->startupError?->getMessage(),
        );
    }
}
