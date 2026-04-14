<?php declare(strict_types=1);

namespace Apntalk\EslReact\Events;

use Apntalk\EslCore\Events\RawEvent;

final class UnknownEventHandler
{
    /** @var list<callable(RawEvent): void> */
    private array $listeners = [];
    private \Closure $errorHandler;

    public function __construct(?callable $errorHandler = null)
    {
        $this->errorHandler = $errorHandler !== null
            ? \Closure::fromCallable($errorHandler)
            : static function (\Throwable $e, RawEvent $event): void {
                fwrite(STDERR, sprintf(
                    "[esl-react] Unknown-event listener exception for %s: %s\n",
                    $event->eventName(),
                    $e->getMessage(),
                ));
            };
    }

    public function add(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function removeAll(): void
    {
        $this->listeners = [];
    }

    public function dispatch(RawEvent $event): void
    {
        foreach ($this->listeners as $listener) {
            try {
                $listener($event);
            } catch (\Throwable $e) {
                try {
                    ($this->errorHandler)($e, $event);
                } catch (\Throwable) {}
            }
        }
    }
}
