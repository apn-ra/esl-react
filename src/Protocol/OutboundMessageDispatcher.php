<?php declare(strict_types=1);

namespace Apntalk\EslReact\Protocol;

use Apntalk\EslCore\Contracts\CommandInterface;
use React\Socket\ConnectionInterface;

final class OutboundMessageDispatcher
{
    private ?ConnectionInterface $connection = null;

    public function __construct(private readonly FrameWriter $writer) {}

    public function attach(ConnectionInterface $connection): void
    {
        $this->connection = $connection;
    }

    public function detach(): void
    {
        $this->connection = null;
    }

    /**
     * Serialize and write a command to the connected socket.
     * Returns false if the socket buffer is full (caller may apply backpressure).
     *
     * @throws \RuntimeException if no connection is attached
     */
    public function dispatch(CommandInterface $command): bool
    {
        if ($this->connection === null) {
            throw new \RuntimeException('OutboundMessageDispatcher: no connection attached');
        }
        $bytes = $this->writer->serialize($command);
        return $this->connection->write($bytes);
    }

    public function isAttached(): bool
    {
        return $this->connection !== null;
    }
}
