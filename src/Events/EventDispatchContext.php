<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Events;

use Apntalk\EslCore\Contracts\EventInterface;
use Apntalk\EslCore\Correlation\MessageMetadata;

final class EventDispatchContext
{
    public function __construct(
        public readonly EventInterface $event,
        public readonly MessageMetadata $metadata,
    ) {}

    public function eventName(): string
    {
        return $this->event->eventName();
    }
}
