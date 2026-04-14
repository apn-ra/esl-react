<?php declare(strict_types=1);

namespace Apntalk\EslReact\Protocol;

use Apntalk\EslCore\Exceptions\ParseException;
use Apntalk\EslCore\Protocol\Frame;
use React\Socket\ConnectionInterface;

final class EnvelopePump
{
    private bool $running = false;
    /** @var callable(Frame): void|null */
    private $frameHandler = null;
    /** @var callable(ParseException): void|null */
    private $parseErrorHandler = null;

    public function __construct(
        private readonly FrameReader $reader,
    ) {}

    public function onFrame(callable $handler): void
    {
        $this->frameHandler = $handler;
    }

    public function onParseError(callable $handler): void
    {
        $this->parseErrorHandler = $handler;
    }

    public function attach(ConnectionInterface $connection): void
    {
        $this->running = true;
        $this->reader->reset();

        $connection->on('data', function (string $chunk): void {
            if (!$this->running) {
                return;
            }
            try {
                $frames = $this->reader->feed($chunk);
                foreach ($frames as $frame) {
                    if ($this->frameHandler !== null) {
                        ($this->frameHandler)($frame);
                    }
                }
            } catch (ParseException $e) {
                $this->reader->reset();
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
