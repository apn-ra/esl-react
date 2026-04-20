<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Events;

use Apntalk\EslCore\Contracts\EventInterface;
use Apntalk\EslCore\Correlation\CorrelationContext;
use Apntalk\EslCore\Correlation\EventEnvelope;
use Apntalk\EslCore\Events\EventFactory;
use Apntalk\EslCore\Events\RawEvent;
use Apntalk\EslCore\Protocol\Frame;
use Apntalk\EslReact\Contracts\EventStreamInterface;
use Closure;
use Throwable;

final class EventStream implements EventStreamInterface
{
    private readonly TypedEventEmitter $typedEmitter;
    private readonly UnknownEventHandler $unknownHandler;
    /** @var list<callable(EventEnvelope): void> */
    private array $rawEnvelopeListeners = [];
    private Closure $envelopeErrorHandler;

    public function __construct(
        private readonly EventFactory $eventFactory,
        private readonly CorrelationContext $correlationContext,
        ?callable $errorHandler = null,
    ) {
        $this->typedEmitter = new TypedEventEmitter($errorHandler);
        $this->unknownHandler = new UnknownEventHandler($errorHandler);
        $this->envelopeErrorHandler = $errorHandler !== null
            ? Closure::fromCallable($errorHandler)
            : static function (Throwable $e): void {
                fwrite(STDERR, "[esl-react] Raw envelope listener exception: {$e->getMessage()}\n");
            };
    }

    /**
     * Called when a live inbound frame arrives on the runtime ingress path.
     * Produces typed event, wraps in EventEnvelope, dispatches to all listeners.
     */
    public function handleFrame(Frame $frame): void
    {
        try {
            $event = $this->eventFactory->fromFrame($frame);
        } catch (Throwable $e) {
            fwrite(STDERR, "[esl-react] EventFactory::fromFrame failed: {$e->getMessage()}\n");
            return;
        }

        $this->dispatchEvent($event);
    }

    public function handleEvent(EventInterface $event): void
    {
        $this->dispatchEvent($event);
    }

    private function dispatchEvent(EventInterface $event): void
    {
        $metadata = $this->correlationContext->nextMetadataForEvent($event);
        $envelope = new EventEnvelope($event, $metadata);

        foreach ($this->rawEnvelopeListeners as $listener) {
            try {
                $listener($envelope);
            } catch (Throwable $e) {
                try {
                    ($this->envelopeErrorHandler)($e, $envelope);
                } catch (Throwable) {
                }
            }
        }

        if ($event instanceof RawEvent) {
            $this->unknownHandler->dispatch($event);
        } else {
            $this->typedEmitter->dispatch($event);
        }
    }

    public function onEvent(string $eventName, callable $listener): static
    {
        $this->typedEmitter->on($eventName, $listener);
        return $this;
    }

    public function onAnyEvent(callable $listener): static
    {
        $this->typedEmitter->onAny($listener);
        return $this;
    }

    public function onRawEnvelope(callable $listener): static
    {
        $this->rawEnvelopeListeners[] = $listener;
        return $this;
    }

    public function onUnknown(callable $listener): static
    {
        $this->unknownHandler->add($listener);
        return $this;
    }

    public function removeListener(string $eventName, callable $listener): static
    {
        $this->typedEmitter->remove($eventName, $listener);
        return $this;
    }

    public function removeAllListeners(?string $eventName = null): static
    {
        $this->typedEmitter->removeAll($eventName);
        if ($eventName === null) {
            $this->rawEnvelopeListeners = [];
            $this->unknownHandler->removeAll();
        }
        return $this;
    }
}
