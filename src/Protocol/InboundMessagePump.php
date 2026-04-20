<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Protocol;

use Apntalk\EslCore\Contracts\InboundPipelineInterface;
use Apntalk\EslCore\Exceptions\ParseException;
use Apntalk\EslCore\Inbound\DecodedInboundMessage;
use React\Socket\ConnectionInterface;

final class InboundMessagePump
{
    private bool $running = false;
    /** @var callable(DecodedInboundMessage): void|null */
    private $messageHandler = null;
    /** @var callable(ParseException): void|null */
    private $parseErrorHandler = null;

    public function __construct(
        private readonly InboundPipelineInterface $pipeline,
    ) {}

    public function onMessage(callable $handler): void
    {
        $this->messageHandler = $handler;
    }

    public function onParseError(callable $handler): void
    {
        $this->parseErrorHandler = $handler;
    }

    public function attach(ConnectionInterface $connection): void
    {
        $this->running = true;
        $this->pipeline->reset();

        $connection->on('data', function (string $chunk): void {
            if (!$this->running) {
                return;
            }

            try {
                $this->pipeline->push($chunk);

                foreach ($this->pipeline->drain() as $message) {
                    if ($this->messageHandler !== null) {
                        ($this->messageHandler)($message);
                    }
                }
            } catch (ParseException $e) {
                $this->pipeline->reset();

                if ($this->parseErrorHandler !== null) {
                    ($this->parseErrorHandler)($e);
                }
            }
        });
    }

    public function detach(): void
    {
        $this->running = false;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }
}
