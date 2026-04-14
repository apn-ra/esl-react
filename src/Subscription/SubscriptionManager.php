<?php declare(strict_types=1);

namespace Apntalk\EslReact\Subscription;

use Apntalk\EslCore\Commands\EventSubscriptionCommand;
use Apntalk\EslCore\Commands\FilterCommand;
use Apntalk\EslCore\Commands\NoEventsCommand;
use Apntalk\EslCore\Contracts\CommandInterface;
use Apntalk\EslReact\Contracts\SubscriptionManagerInterface;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

final class SubscriptionManager implements SubscriptionManagerInterface
{
    /**
     * @param \Closure(CommandInterface, string, float): PromiseInterface<\Apntalk\EslCore\Contracts\ReplyInterface> $dispatchCommand
     */
    public function __construct(
        private readonly ActiveSubscriptionSet $activeSubscriptions,
        private readonly FilterManager $filters,
        private readonly \Closure $dispatchCommand,
        private readonly float $timeoutSeconds,
    ) {}

    public function subscribe(string ...$eventNames): PromiseInterface
    {
        if ($eventNames === []) {
            return $this->resolvedVoid();
        }

        return ($this->dispatchCommand)(
            EventSubscriptionCommand::forNames(array_values($eventNames)),
            'event plain ' . implode(' ', $eventNames),
            $this->timeoutSeconds,
        )->then(function () use ($eventNames): void {
            $this->activeSubscriptions->subscribe(...$eventNames);
        });
    }

    public function subscribeAll(): PromiseInterface
    {
        return ($this->dispatchCommand)(
            EventSubscriptionCommand::all(),
            'event plain all',
            $this->timeoutSeconds,
        )->then(function (): void {
            $this->activeSubscriptions->subscribeAll();
        });
    }

    public function unsubscribe(string ...$eventNames): PromiseInterface
    {
        if ($eventNames === []) {
            return $this->resolvedVoid();
        }

        return ($this->dispatchCommand)(
            new NoEventsCommand(),
            'noevents',
            $this->timeoutSeconds,
        )->then(function () use ($eventNames): void {
            $this->activeSubscriptions->unsubscribe(...$eventNames);
        });
    }

    public function addFilter(string $headerName, string $headerValue): PromiseInterface
    {
        return ($this->dispatchCommand)(
            FilterCommand::add($headerName, $headerValue),
            sprintf('filter %s %s', $headerName, $headerValue),
            $this->timeoutSeconds,
        )->then(function () use ($headerName, $headerValue): void {
            $this->filters->addFilter($headerName, $headerValue);
        });
    }

    public function removeFilter(string $headerName, string $headerValue): PromiseInterface
    {
        return ($this->dispatchCommand)(
            FilterCommand::delete($headerName, $headerValue),
            sprintf('filter delete %s %s', $headerName, $headerValue),
            $this->timeoutSeconds,
        )->then(function () use ($headerName, $headerValue): void {
            $this->filters->removeFilter($headerName, $headerValue);
        });
    }

    public function activeEventNames(): array
    {
        return $this->activeSubscriptions->eventNames();
    }

    public function hasFilters(): bool
    {
        return $this->filters->hasFilters();
    }

    /**
     * @return PromiseInterface<void>
     */
    private function resolvedVoid(): PromiseInterface
    {
        /** @var PromiseInterface<void> $promise */
        $promise = resolve(null);

        return $promise;
    }
}
