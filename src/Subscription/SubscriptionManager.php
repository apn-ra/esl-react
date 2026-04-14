<?php declare(strict_types=1);

namespace Apntalk\EslReact\Subscription;

use Apntalk\EslCore\Commands\EventSubscriptionCommand;
use Apntalk\EslCore\Commands\FilterCommand;
use Apntalk\EslCore\Commands\NoEventsCommand;
use Apntalk\EslCore\Contracts\CommandInterface;
use Apntalk\EslReact\Contracts\SubscriptionManagerInterface;
use Apntalk\EslReact\Exceptions\ConnectionException;
use Apntalk\EslReact\Replay\RuntimeReplayCapture;
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
        /** @var \Closure(): void */
        private readonly \Closure $assertCanMutateLiveSession,
        private readonly ?RuntimeReplayCapture $replayCapture = null,
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

        $before = $this->subscriptionState();
        $after = [
            'subscribe_all' => false,
            'event_names' => $desired,
        ];
        $this->replayCapture?->captureSubscriptionMutation([
            'mutation_kind' => 'subscribe',
            'input' => ['event_names' => $normalized],
            'desired_state_before' => $before,
            'desired_state_after' => $after,
            'noop' => false,
        ]);

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

        $before = $this->subscriptionState();
        $after = [
            'subscribe_all' => true,
            'event_names' => [],
        ];
        $this->replayCapture?->captureSubscriptionMutation([
            'mutation_kind' => 'subscribe_all',
            'input' => ['event_names' => ['all']],
            'desired_state_before' => $before,
            'desired_state_after' => $after,
            'noop' => false,
        ]);

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

        $before = $this->subscriptionState();
        $after = [
            'subscribe_all' => false,
            'event_names' => $desired,
        ];
        $this->replayCapture?->captureSubscriptionMutation([
            'mutation_kind' => 'unsubscribe',
            'input' => ['event_names' => $normalized],
            'desired_state_before' => $before,
            'desired_state_after' => $after,
            'noop' => false,
        ]);

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

        $before = $this->filterState();
        $after = [...$before, ['headerName' => $headerName, 'headerValue' => $headerValue]];
        $this->replayCapture?->captureFilterMutation([
            'mutation_kind' => 'add',
            'header_name' => $headerName,
            'header_value' => $headerValue,
            'desired_state_before' => $before,
            'desired_state_after' => $after,
            'noop' => false,
        ]);

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

        $before = $this->filterState();
        $after = array_values(array_filter(
            $before,
            static fn (array $filter): bool => !(
                $filter['headerName'] === $headerName
                && $filter['headerValue'] === $headerValue
            ),
        ));
        $this->replayCapture?->captureFilterMutation([
            'mutation_kind' => 'remove',
            'header_name' => $headerName,
            'header_value' => $headerValue,
            'desired_state_before' => $before,
            'desired_state_after' => $after,
            'noop' => false,
        ]);

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
     * Replay the currently desired subscription and filter state onto a newly
     * authenticated live session.
     *
     * @return PromiseInterface<void>
     */
    public function restoreDesiredState(): PromiseInterface
    {
        /** @var PromiseInterface<void> $promise */
        $promise = $this->resolvedVoid();

        if ($this->activeSubscriptions->isSubscribedAll()) {
            $promise = $promise->then(function (): PromiseInterface {
                return ($this->dispatchCommand)(
                    EventSubscriptionCommand::all(),
                    'event plain all',
                    $this->timeoutSeconds,
                )->then(static fn (): null => null);
            });
        } elseif ($this->activeSubscriptions->eventNames() !== []) {
            $desired = $this->activeSubscriptions->eventNames();
            $promise = $promise->then(function () use ($desired): PromiseInterface {
                return ($this->dispatchCommand)(
                    EventSubscriptionCommand::forNames($desired),
                    'event plain ' . implode(' ', $desired),
                    $this->timeoutSeconds,
                )->then(static fn (): null => null);
            });
        }

        foreach ($this->filters->all() as $filter) {
            $promise = $promise->then(function () use ($filter): PromiseInterface {
                return ($this->dispatchCommand)(
                    FilterCommand::add($filter['headerName'], $filter['headerValue']),
                    sprintf('filter %s %s', $filter['headerName'], $filter['headerValue']),
                    $this->timeoutSeconds,
                )->then(static fn (): null => null);
            });
        }

        /** @var PromiseInterface<void> $restored */
        $restored = $promise->then(static fn (): null => null);

        return $restored;
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
        ($this->assertCanMutateLiveSession)();
    }

    /**
     * @return array{subscribe_all: bool, event_names: list<string>}
     */
    private function subscriptionState(): array
    {
        return [
            'subscribe_all' => $this->activeSubscriptions->isSubscribedAll(),
            'event_names' => $this->activeSubscriptions->eventNames(),
        ];
    }

    /**
     * @return list<array{headerName: string, headerValue: string}>
     */
    private function filterState(): array
    {
        return $this->filters->all();
    }
}
