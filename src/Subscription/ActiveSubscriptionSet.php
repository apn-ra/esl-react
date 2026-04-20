<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Subscription;

final class ActiveSubscriptionSet
{
    /** @var array<string, true> keyed by event name */
    private array $names = [];

    private bool $allEvents = false;

    public function subscribeAll(): void
    {
        $this->allEvents = true;
        $this->names = [];
    }

    public function subscribe(string ...$eventNames): void
    {
        if ($this->allEvents) {
            return;
        }

        foreach ($eventNames as $name) {
            $this->names[$name] = true;
        }
    }

    public function unsubscribe(string ...$eventNames): void
    {
        if ($this->allEvents) {
            return;
        }

        foreach ($eventNames as $name) {
            unset($this->names[$name]);
        }
    }

    public function hasEventName(string $eventName): bool
    {
        return $this->allEvents || isset($this->names[$eventName]);
    }

    /**
     * @param list<string> $eventNames
     */
    public function replace(array $eventNames): void
    {
        $this->allEvents = false;
        $this->names = [];
        foreach ($eventNames as $name) {
            $this->names[$name] = true;
        }
    }

    public function isSubscribedAll(): bool
    {
        return $this->allEvents;
    }

    /** @return list<string> */
    public function eventNames(): array
    {
        return array_keys($this->names);
    }

    public function isEmpty(): bool
    {
        return !$this->allEvents && empty($this->names);
    }

    public function reset(): void
    {
        $this->names = [];
        $this->allEvents = false;
    }
}
