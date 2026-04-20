<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

/**
 * Exact desired-state subscription/filter feedback for the current runtime.
 *
 * This reflects the runtime's in-memory desired state, not transport-level
 * confirmation that a remote FreeSWITCH session has applied the same state.
 */
final class RuntimeSubscriptionStateSnapshot
{
    /**
     * @param list<string> $eventNames
     * @param list<array{headerName: string, headerValue: string}> $filters
     */
    public function __construct(
        public readonly bool $subscribeAll,
        public readonly array $eventNames,
        public readonly array $filters,
    ) {}

    public function isEmpty(): bool
    {
        return !$this->subscribeAll && $this->eventNames === [] && $this->filters === [];
    }

    public function hasFilters(): bool
    {
        return $this->filters !== [];
    }
}
