<?php

declare(strict_types=1);

namespace Apntalk\EslReact\CommandBus;

use Apntalk\EslCore\Contracts\CommandInterface;
use Apntalk\EslCore\Contracts\ReplyInterface;
use Apntalk\EslReact\Exceptions\BackpressureException;
use Apntalk\EslReact\Exceptions\CommandTimeoutException;
use Apntalk\EslReact\Exceptions\ConnectionLostException;
use Apntalk\EslReact\Exceptions\DrainException;
use Closure;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Throwable;

/**
 * Serial command bus for ESL api commands.
 *
 * ESL protocol requires api commands to be dispatched one at a time.
 * Commands are queued and sent in order. Each command waits for its reply
 * before the next is sent.
 *
 * Ordering guarantee: commands are sent and resolved in FIFO order.
 * Cancellation: NOT supported in v1.x.
 */
final class AsyncCommandBus
{
    /** @var list<array{command: CommandInterface, pending: PendingCommand, timeoutSeconds: float}> */
    private array $queue = [];
    /** @var array{command: CommandInterface, pending: PendingCommand}|null */
    private ?array $inflight = null;
    private bool $draining = false;
    private bool $replyCorrelationCompromised = false;

    public function __construct(
        /** callable(CommandInterface): void */
        private readonly Closure $sendFn,
        private readonly LoopInterface $loop,
        private readonly int $maxQueued = 50,
        /** @var null|Closure(Throwable): void */
        private readonly ?Closure $onReplyCorrelationCompromised = null,
    ) {}

    /**
     * Enqueue a command for dispatch.
     *
     * @return PromiseInterface<ReplyInterface>
     */
    public function dispatch(CommandInterface $command, string $description, float $timeoutSeconds): PromiseInterface
    {
        if ($this->replyCorrelationCompromised) {
            return \React\Promise\reject(new ConnectionLostException(
                'Reply correlation is ambiguous after api timeout; connection reset required before new api commands',
            ));
        }
        if ($this->draining) {
            return \React\Promise\reject(new DrainException());
        }
        if (count($this->queue) >= $this->maxQueued) {
            return \React\Promise\reject(new BackpressureException(
                sprintf('Command bus queue full (%d pending)', $this->maxQueued),
            ));
        }

        $deferred = new Deferred();
        $pending = new PendingCommand($description, $deferred, (float) (microtime(true) * 1_000_000));
        $this->queue[] = ['command' => $command, 'pending' => $pending, 'timeoutSeconds' => $timeoutSeconds];

        $this->pump();

        return $pending->promise();
    }

    /**
     * Called by the inbound router when a reply arrives from the server.
     * Resolves the inflight command.
     */
    public function onReply(ReplyInterface $reply): void
    {
        if ($this->replyCorrelationCompromised) {
            // Late replies after an api timeout are ambiguous because api replies
            // have no request identifier. Ignore them until the connection resets.
            return;
        }
        if ($this->inflight === null) {
            // Unexpected reply — ignore
            return;
        }
        $inflight = $this->inflight;
        $this->inflight = null;
        $inflight['pending']->cancelTimer(fn($t) => $this->loop->cancelTimer($t));
        $inflight['pending']->resolve($reply);
        $this->pump();
    }

    /**
     * Called when the connection drops.
     * Rejects inflight and all queued commands with ConnectionLostException.
     */
    public function onConnectionLost(): void
    {
        $this->replyCorrelationCompromised = false;
        $this->abortAll(new ConnectionLostException());
    }

    public function enterDrainMode(): void
    {
        $this->draining = true;
    }

    public function exitDrainMode(): void
    {
        $this->draining = false;
    }

    public function hasInflight(): bool
    {
        return $this->inflight !== null;
    }

    public function inflightCount(): int
    {
        return $this->inflight !== null ? 1 : 0;
    }

    public function queuedCount(): int
    {
        return count($this->queue);
    }

    public function totalPendingCount(): int
    {
        return $this->inflightCount() + $this->queuedCount();
    }

    public function abortAll(Throwable $reason): void
    {
        if ($this->inflight !== null) {
            $inflight = $this->inflight;
            $this->inflight = null;
            $inflight['pending']->cancelTimer(fn($t) => $this->loop->cancelTimer($t));
            $inflight['pending']->reject($reason);
        }

        $queue = $this->queue;
        $this->queue = [];
        foreach ($queue as $entry) {
            $entry['pending']->reject($reason);
        }

        if ($reason instanceof ConnectionLostException) {
            $this->replyCorrelationCompromised = false;
        }
    }

    private function pump(): void
    {
        if ($this->inflight !== null || empty($this->queue)) {
            return;
        }

        $entry = array_shift($this->queue);
        $this->inflight = $entry;

        // Start timeout timer before sending
        $timeoutSeconds = $entry['timeoutSeconds'];
        $description = $entry['pending']->commandDescription();
        $timer = $this->loop->addTimer($timeoutSeconds, function () use ($description, $timeoutSeconds): void {
            if ($this->inflight === null) {
                return;
            }
            $inflight = $this->inflight;
            $this->inflight = null;
            $timeout = new CommandTimeoutException($description, $timeoutSeconds);
            $inflight['pending']->reject($timeout);
            $this->replyCorrelationCompromised = true;
            ($this->onReplyCorrelationCompromised)?->__invoke($timeout);
        });
        $entry['pending']->attachTimer($timer);

        // Send the command
        try {
            ($this->sendFn)($entry['command']);
        } catch (Throwable $e) {
            if ($this->inflight !== null) {
                $inflight = $this->inflight;
                $this->inflight = null;
                $inflight['pending']->cancelTimer(fn($t) => $this->loop->cancelTimer($t));
                $inflight['pending']->reject($e);
            }
            $this->pump();
        }
    }
}
