<?php declare(strict_types=1);
namespace Apntalk\EslReact\Contracts;
use React\Promise\PromiseInterface;

interface SubscriptionManagerInterface {
    /**
     * Subscribe to one or more event names.
     *
     * @return PromiseInterface<void> Resolves when the subscription command is acknowledged.
     */
    public function subscribe(string ...$eventNames): PromiseInterface;

    /**
     * Subscribe to all events (equivalent to "event plain all").
     *
     * @return PromiseInterface<void>
     */
    public function subscribeAll(): PromiseInterface;

    /**
     * Remove subscriptions for the given event names.
     *
     * @return PromiseInterface<void>
     */
    public function unsubscribe(string ...$eventNames): PromiseInterface;

    /**
     * Add a header filter (only events matching this header will be delivered).
     *
     * @return PromiseInterface<void>
     */
    public function addFilter(string $headerName, string $headerValue): PromiseInterface;

    /**
     * Remove a header filter.
     *
     * @return PromiseInterface<void>
     */
    public function removeFilter(string $headerName, string $headerValue): PromiseInterface;

    /**
     * Return currently active event name subscriptions.
     *
     * @return list<string>
     */
    public function activeEventNames(): array;

    /**
     * Whether any filters are currently active.
     */
    public function hasFilters(): bool;
}
