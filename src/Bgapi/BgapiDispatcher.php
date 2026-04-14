<?php declare(strict_types=1);

namespace Apntalk\EslReact\Bgapi;

use Apntalk\EslCore\Commands\BgapiCommand;
use Apntalk\EslCore\Events\BackgroundJobEvent;
use Apntalk\EslCore\Replies\BgapiAcceptedReply;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

final class BgapiDispatcher
{
    public function __construct(
        private readonly BgapiJobTracker $tracker,
        /** callable(CommandInterface, string, float): PromiseInterface */
        private readonly \Closure $sendCommandReply,
        private readonly float $ackTimeoutSeconds,
    ) {}

    /**
     * Dispatch a bgapi command. Returns a handle immediately.
     *
     * The handle's promise() resolves with BackgroundJobEvent on completion.
     * The jobUuid() on the handle will be empty until the command/reply ack
     * arrives asynchronously — consumers should rely on promise() for correlation.
     */
    public function dispatch(string $command, string $args): BgapiJobHandle
    {
        $bgapiCommand = new BgapiCommand($command, $args);
        $jobDeferred = new Deferred();
        $dispatchedAt = (float) (microtime(true) * 1_000_000);

        $ackPromise = ($this->sendCommandReply)($bgapiCommand, "bgapi {$command}", $this->ackTimeoutSeconds);

        $ackPromise->then(
            function (mixed $reply) use ($command, $args, $jobDeferred, $dispatchedAt): void {
                if (!($reply instanceof BgapiAcceptedReply)) {
                    $jobDeferred->reject(new \RuntimeException(
                        "bgapi {$command}: ack was not BgapiAcceptedReply, got " . get_class($reply),
                    ));
                    return;
                }
                $pending = new PendingBgapiJob(
                    $reply->jobUuid(),
                    $command,
                    $args,
                    $jobDeferred,
                    $dispatchedAt,
                );
                $this->tracker->register($pending);
            },
            static function (\Throwable $e) use ($jobDeferred): void {
                $jobDeferred->reject($e);
            },
        );

        return new BgapiJobHandle(
            jobUuid: '',
            eslCommand: $command,
            eslArgs: $args,
            dispatchedAtMicros: $dispatchedAt,
            promise: $jobDeferred->promise(),
        );
    }

    public function onBackgroundJobEvent(BackgroundJobEvent $event): void
    {
        $this->tracker->complete($event);
    }
}
