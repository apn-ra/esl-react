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
    /** @var list<ConnectionInterface> */
    private array $connections = [];

    /** @var list<callable(ConnectionInterface, string): void> */
    private array $commandHandlers = [];

    /** @var list<string> */
    private array $receivedCommands = [];
    /** @var list<list<string>> */
    private array $receivedCommandsByConnection = [];

    public function __construct(
        private readonly LoopInterface $loop,
        bool $autoAuthRequest = true,
        ?callable $onConnection = null,
    ) {
        $this->onConnection = $onConnection !== null ? \Closure::fromCallable($onConnection) : null;
        $this->server = new SocketServer('127.0.0.1:0', [], $this->loop);
        $this->server->on('connection', function (ConnectionInterface $connection) use ($autoAuthRequest): void {
            $this->activeConnection = $connection;
            $this->connections[] = $connection;
            $connectionIndex = array_key_last($this->connections);
            \assert(is_int($connectionIndex));
            $this->receivedCommandsByConnection[$connectionIndex] = [];
            if ($autoAuthRequest) {
                $this->writeFrame($connection, "Content-Type: auth/request\n\n");
            }

            if ($this->onConnection !== null) {
                ($this->onConnection)($connection);
            }

            $buffer = '';
            $connection->on('data', function (string $chunk) use ($connection, $connectionIndex, &$buffer): void {
                $buffer .= $chunk;

                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $command = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);
                    $command = trim($command);

                    if ($command === '') {
                        continue;
                    }

                    $this->receivedCommands[] = $command;
                    $this->receivedCommandsByConnection[$connectionIndex][] = $command;

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

    /**
     * @return list<list<string>>
     */
    public function receivedCommandsByConnection(): array
    {
        return $this->receivedCommandsByConnection;
    }

    public function connectionCount(): int
    {
        return count($this->connections);
    }

    public function closeActiveConnection(): void
    {
        $this->requireActiveConnection()->close();
    }

    public function activeConnection(): ConnectionInterface
    {
        return $this->requireActiveConnection();
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

    public function writeBgapiAcceptedReply(ConnectionInterface $connection, string $jobUuid): void
    {
        $this->writeFrame(
            $connection,
            sprintf("Content-Type: command/reply\nReply-Text: +OK Job-UUID: %s\n\n", $jobUuid),
        );
    }

    public function writeRawFrame(ConnectionInterface $connection, string $frame): void
    {
        $this->writeFrame($connection, $frame);
    }

    /**
     * @param list<string> $fragments
     */
    public function writeRawFrameFragments(ConnectionInterface $connection, array $fragments, float $delaySeconds = 0.0): void
    {
        foreach (array_values($fragments) as $index => $fragment) {
            $this->loop->addTimer($delaySeconds * $index, function () use ($connection, $fragment): void {
                $this->writeFrame($connection, $fragment);
            });
        }
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

    public function emitBackgroundJobEvent(string $jobUuid, string $result, ?string $jobCommand = null): void
    {
        $headers = [
            'Event-Name' => 'BACKGROUND_JOB',
            'Job-UUID' => $jobUuid,
        ];

        if ($jobCommand !== null) {
            $headers['Job-Command'] = $jobCommand;
        }

        $this->emitPlainEvent($headers, $result);
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
