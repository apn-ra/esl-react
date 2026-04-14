<?php declare(strict_types=1);

namespace Apntalk\EslReact\Tests\FakeServer;

use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

final class ScriptedFakeEslServer
{
    private readonly SocketServer $server;
    /** @var \Closure(ConnectionInterface): void|null */
    private ?\Closure $onConnection = null;
    private ?ConnectionInterface $activeConnection = null;

    /** @var list<callable(ConnectionInterface, string): void> */
    private array $commandHandlers = [];

    /** @var list<string> */
    private array $receivedCommands = [];

    public function __construct(
        private readonly LoopInterface $loop,
        bool $autoAuthRequest = true,
        ?callable $onConnection = null,
    ) {
        $this->onConnection = $onConnection !== null ? \Closure::fromCallable($onConnection) : null;
        $this->server = new SocketServer('127.0.0.1:0', [], $this->loop);
        $this->server->on('connection', function (ConnectionInterface $connection) use ($autoAuthRequest): void {
            $this->activeConnection = $connection;
            if ($autoAuthRequest) {
                $this->writeFrame($connection, "Content-Type: auth/request\n\n");
            }

            if ($this->onConnection !== null) {
                ($this->onConnection)($connection);
            }

            $buffer = '';
            $connection->on('data', function (string $chunk) use ($connection, &$buffer): void {
                $buffer .= $chunk;

                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $command = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);
                    $command = trim($command);

                    if ($command === '') {
                        continue;
                    }

                    $this->receivedCommands[] = $command;

                    if ($this->commandHandlers !== []) {
                        $handler = array_shift($this->commandHandlers);
                        $handler($connection, $command);
                    }
                }
            });
            $connection->on('close', function () use ($connection): void {
                if ($this->activeConnection === $connection) {
                    $this->activeConnection = null;
                }
            });
        });
    }

    public function address(): string
    {
        $address = $this->server->getAddress();
        if (!is_string($address)) {
            throw new \RuntimeException('Fake server has no listening address');
        }

        return $address;
    }

    public function port(): int
    {
        $port = parse_url($this->address(), PHP_URL_PORT);
        if (!is_int($port)) {
            throw new \RuntimeException('Unable to determine fake server port');
        }

        return $port;
    }

    public function queueCommandHandler(callable $handler): void
    {
        $this->commandHandlers[] = $handler;
    }

    /**
     * @return list<string>
     */
    public function receivedCommands(): array
    {
        return $this->receivedCommands;
    }

    public function close(): void
    {
        $this->server->close();
    }

    public function emitPlainEvent(array $headers, string $body = ''): void
    {
        $this->emitPlainEventTo($this->requireActiveConnection(), $headers, $body);
    }

    public function writeCommandReply(ConnectionInterface $connection, string $replyText): void
    {
        $this->writeFrame($connection, "Content-Type: command/reply\nReply-Text: {$replyText}\n\n");
    }

    public function writeApiResponse(ConnectionInterface $connection, string $body): void
    {
        $this->writeFrame(
            $connection,
            sprintf("Content-Type: api/response\nContent-Length: %d\n\n%s", strlen($body), $body),
        );
    }

    public function writeRawFrame(ConnectionInterface $connection, string $frame): void
    {
        $this->writeFrame($connection, $frame);
    }

    public function emitPlainEventTo(ConnectionInterface $connection, array $headers, string $body = ''): void
    {
        $eventHeaderLines = [];
        foreach ($headers as $name => $value) {
            $eventHeaderLines[] = sprintf('%s: %s', $name, rawurlencode((string) $value));
        }

        $eventPayload = implode("\n", $eventHeaderLines);
        if ($body !== '') {
            $eventPayload .= "\n\n" . $body;
        }

        $frame = sprintf(
            "Content-Type: text/event-plain\nContent-Length: %d\n\n%s",
            strlen($eventPayload),
            $eventPayload,
        );

        $this->writeFrame($connection, $frame);
    }

    private function writeFrame(ConnectionInterface $connection, string $frame): void
    {
        $connection->write($frame);
    }

    private function requireActiveConnection(): ConnectionInterface
    {
        if ($this->activeConnection === null) {
            throw new \RuntimeException('No active fake-server connection available');
        }

        return $this->activeConnection;
    }
}
