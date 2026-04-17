<?php declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

/**
 * Conservative locally observed-applied subscription/filter state for the
 * current runtime session.
 *
 * This reflects what the runtime believes it has successfully applied on the
 * current live ESL session after command replies completed. It is not a deeper
 * transport receipt ledger than that.
 */
final class RuntimeObservedSubscriptionStateSnapshot
{
    /**
     * @param list<string> $eventNames
     * @param list<array{headerName: string, headerValue: string}> $filters
     */
    public function __construct(
        public readonly bool $subscribeAll,
        public readonly array $eventNames,
        public readonly array $filters,
        public readonly bool $isCurrentForActiveSession,
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
