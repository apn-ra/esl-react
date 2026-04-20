<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Events;

use Apntalk\EslCore\Contracts\EventInterface;
use Closure;
use Throwable;

final class TypedEventEmitter
{
    /** @var array<string, list<callable(EventInterface): void>> */
    private array $listeners = [];
    /** @var list<callable(EventInterface): void> */
    private array $anyListeners = [];
    private Closure $errorHandler;

    public function __construct(?callable $errorHandler = null)
    {
        $this->errorHandler = $errorHandler !== null
            ? Closure::fromCallable($errorHandler)
            : static function (Throwable $e, EventInterface $event): void {
                // Default: write to stderr without crashing
                fwrite(STDERR, sprintf(
                    "[esl-react] Listener exception for event %s: %s\n",
                    $event->eventName(),
                    $e->getMessage(),
                ));
            };
    }

    public function on(string $eventName, callable $listener): void
    {
        $this->listeners[$eventName][] = $listener;
    }

    public function onAny(callable $listener): void
    {
        $this->anyListeners[] = $listener;
    }

    public function remove(string $eventName, callable $listener): void
    {
        if (!isset($this->listeners[$eventName])) {
            return;
        }
        $this->listeners[$eventName] = array_values(array_filter(
            $this->listeners[$eventName],
            static fn($l) => $l !== $listener,
        ));
        if (empty($this->listeners[$eventName])) {
            unset($this->listeners[$eventName]);
        }
    }

    public function removeAll(?string $eventName = null): void
    {
        if ($eventName === null) {
            $this->listeners = [];
            $this->anyListeners = [];
        } else {
            unset($this->listeners[$eventName]);
        }
    }

    /**
     * Dispatch an event to all matching listeners.
     * Listener exceptions are caught and passed to the error handler.
     * Delivery continues even if a listener throws.
     */
    public function dispatch(EventInterface $event): void
    {
        $name = $event->eventName();

        if (isset($this->listeners[$name])) {
            foreach ($this->listeners[$name] as $listener) {
                $this->callListener($listener, $event);
            }
        }

        foreach ($this->anyListeners as $listener) {
            $this->callListener($listener, $event);
        }
    }

    private function callListener(callable $listener, EventInterface $event): void
    {
        try {
            $listener($event);
        } catch (Throwable $e) {
            try {
                ($this->errorHandler)($e, $event);
            } catch (Throwable) {
            }
        }
    }
}
