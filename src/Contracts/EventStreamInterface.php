<?php declare(strict_types=1);
namespace Apntalk\EslReact\Contracts;

interface EventStreamInterface {
    /**
     * Register a listener for a specific event name (e.g., 'CHANNEL_CREATE').
     * The listener receives an EventInterface instance.
     *
     * @param callable(\Apntalk\EslCore\Contracts\EventInterface): void $listener
     */
    public function onEvent(string $eventName, callable $listener): static;

    /**
     * Register a listener for all typed events (all event names).
     * The listener receives an EventInterface instance.
     *
     * @param callable(\Apntalk\EslCore\Contracts\EventInterface): void $listener
     */
    public function onAnyEvent(callable $listener): static;

    /**
     * Register a listener for raw event envelopes (before typed dispatch).
     * The listener receives an EventEnvelope with correlation metadata.
     *
     * @param callable(\Apntalk\EslCore\Correlation\EventEnvelope): void $listener
     */
    public function onRawEnvelope(callable $listener): static;

    /**
     * Register a listener for unknown/unclassified events (RawEvent).
     * The listener receives a RawEvent.
     *
     * @param callable(\Apntalk\EslCore\Events\RawEvent): void $listener
     */
    public function onUnknown(callable $listener): static;

    /**
     * Remove a previously registered event listener.
     */
    public function removeListener(string $eventName, callable $listener): static;

    /**
     * Remove all listeners for the given event name, or all listeners if null.
     */
    public function removeAllListeners(?string $eventName = null): static;
}
