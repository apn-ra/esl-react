<?php declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

use Apntalk\EslReact\Contracts\AsyncEslClientInterface;
use Apntalk\EslReact\Runtime\RuntimeClient;
use React\Promise\PromiseInterface;

final class RuntimeRunnerHandle
{
    /** @var list<callable(RuntimeLifecycleSnapshot): void> */
    private array $lifecycleListeners = [];
    private string $lastLifecycleSignature;
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
        $this->lastLifecycleSignature = $this->lifecycleSignature($this->lifecycleSnapshot());

        if ($this->client instanceof RuntimeClient) {
            $this->client->onLifecycleChange(function (): void {
                $this->emitLifecycleChangeIfChanged();
            });
        }

        $this->startupPromise->then(
            function (): void {
                $this->state = RuntimeRunnerState::Running;
                $this->emitLifecycleChangeIfChanged();
            },
            function (\Throwable $e): void {
                $this->startupError = $e;
                $this->state = RuntimeRunnerState::Failed;
                $this->emitLifecycleChangeIfChanged();
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

    public function onLifecycleChange(callable $listener): void
    {
        $this->lifecycleListeners[] = $listener;
        $this->callLifecycleListener($listener, $this->lifecycleSnapshot());
    }

    private function emitLifecycleChangeIfChanged(): void
    {
        $snapshot = $this->lifecycleSnapshot();
        $signature = $this->lifecycleSignature($snapshot);

        if ($signature === $this->lastLifecycleSignature) {
            return;
        }

        $this->lastLifecycleSignature = $signature;

        foreach ($this->lifecycleListeners as $listener) {
            $this->callLifecycleListener($listener, $snapshot);
        }
    }

    private function lifecycleSignature(RuntimeLifecycleSnapshot $snapshot): string
    {
        return json_encode([
            'runnerState' => $snapshot->runnerState->value,
            'sessionId' => $snapshot->sessionContext?->sessionId(),
            'connectionState' => $snapshot->connectionState()?->value,
            'sessionState' => $snapshot->sessionState()?->value,
            'isLive' => $snapshot->isLive(),
            'isReconnecting' => $snapshot->isReconnecting(),
            'isDraining' => $snapshot->isDraining(),
            'isStopped' => $snapshot->isStopped(),
            'isFailed' => $snapshot->isFailed(),
            'reconnectAttempts' => $snapshot->reconnectAttempts(),
            'startupErrorClass' => $snapshot->startupErrorClass,
            'startupErrorMessage' => $snapshot->startupErrorMessage,
            'lastRuntimeErrorClass' => $snapshot->lastRuntimeErrorClass(),
            'lastRuntimeErrorMessage' => $snapshot->lastRuntimeErrorMessage(),
        ], JSON_THROW_ON_ERROR);
    }

    private function callLifecycleListener(callable $listener, RuntimeLifecycleSnapshot $snapshot): void
    {
        try {
            $listener($snapshot);
        } catch (\Throwable $e) {
            fwrite(STDERR, sprintf(
                "[esl-react] Runtime lifecycle listener exception: %s\n",
                $e->getMessage(),
            ));
        }
    }
}
