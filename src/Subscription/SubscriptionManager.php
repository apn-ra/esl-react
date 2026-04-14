<?php declare(strict_types=1);

namespace Apntalk\EslReact\Subscription;

use Apntalk\EslCore\Commands\EventSubscriptionCommand;
use Apntalk\EslCore\Commands\FilterCommand;
use Apntalk\EslCore\Commands\NoEventsCommand;
use Apntalk\EslCore\Contracts\CommandInterface;
use Apntalk\EslReact\Contracts\SubscriptionManagerInterface;
use Apntalk\EslReact\Exceptions\ConnectionException;
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
        /** @var \Closure(): bool */
        private readonly \Closure $canMutateLiveSession,
    ) {}

    public function subscribe(string ...$eventNames): PromiseInterface
    {
        $normalized = $this->normalizeEventNames($eventNames);
        if ($normalized === []) {
            return $this->resolvedVoid();
        }

        $this->assertCanMutateLiveSession();

        if ($this->activeSubscriptions->isSubscribedAll()) {
            return $this->resolvedVoid();
        }

        $desired = $this->activeSubscriptions->eventNames();
        foreach ($normalized as $name) {
            if (!in_array($name, $desired, true)) {
                $desired[] = $name;
            }
        }

        if ($desired === $this->activeSubscriptions->eventNames()) {
            return $this->resolvedVoid();
        }

        return ($this->dispatchCommand)(
            EventSubscriptionCommand::forNames($desired),
            'event plain ' . implode(' ', $desired),
            $this->timeoutSeconds,
        )->then(function () use ($desired): void {
            $this->activeSubscriptions->replace($desired);
        });
    }

    public function subscribeAll(): PromiseInterface
    {
        $this->assertCanMutateLiveSession();

        if ($this->activeSubscriptions->isSubscribedAll()) {
            return $this->resolvedVoid();
        }

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
        $normalized = $this->normalizeEventNames($eventNames);
        if ($normalized === []) {
            return $this->resolvedVoid();
        }

        $this->assertCanMutateLiveSession();

        if ($this->activeSubscriptions->isSubscribedAll()) {
            throw new ConnectionException(
                'Cannot unsubscribe specific events while subscribed to all events in the current implementation',
            );
        }

        $desired = array_values(array_filter(
            $this->activeSubscriptions->eventNames(),
            static fn (string $name): bool => !in_array($name, $normalized, true),
        ));

        if ($desired === $this->activeSubscriptions->eventNames()) {
            return $this->resolvedVoid();
        }

        $command = $desired === []
            ? new NoEventsCommand()
            : EventSubscriptionCommand::forNames($desired);
        $description = $desired === []
            ? 'noevents'
            : 'event plain ' . implode(' ', $desired);

        return ($this->dispatchCommand)(
            $command,
            $description,
            $this->timeoutSeconds,
        )->then(function () use ($desired): void {
            if ($desired === []) {
                $this->activeSubscriptions->reset();
                return;
            }

            $this->activeSubscriptions->replace($desired);
        });
    }

    public function addFilter(string $headerName, string $headerValue): PromiseInterface
    {
        $this->assertCanMutateLiveSession();

        if ($this->filters->hasFilter($headerName, $headerValue)) {
            return $this->resolvedVoid();
        }

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
        $this->assertCanMutateLiveSession();

        if (!$this->filters->hasFilter($headerName, $headerValue)) {
            return $this->resolvedVoid();
        }

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

    /**
     * @param array<int|string, string> $eventNames
     * @return list<string>
     */
    private function normalizeEventNames(array $eventNames): array
    {
        $normalized = [];
        foreach ($eventNames as $name) {
            if ($name === '') {
                continue;
            }

            if (!in_array($name, $normalized, true)) {
                $normalized[] = $name;
            }
        }

        return $normalized;
    }

    private function assertCanMutateLiveSession(): void
    {
        if (!(($this->canMutateLiveSession)())) {
            throw new ConnectionException('Runtime is not authenticated');
        }
    }
}
