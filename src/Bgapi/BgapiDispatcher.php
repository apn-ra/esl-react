<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Bgapi;

use Apntalk\EslCore\Commands\BgapiCommand;
use Apntalk\EslCore\Contracts\CommandInterface;
use Apntalk\EslCore\Events\BackgroundJobEvent;
use Apntalk\EslCore\Replies\BgapiAcceptedReply;
use Apntalk\EslReact\Replay\RuntimeReplayCapture;
use Closure;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;

final class BgapiDispatcher
{
    /** @var array<int, PendingBgapiJob> */
    private array $pendingJobs = [];

    public function __construct(
        private readonly BgapiJobTracker $tracker,
        /** callable(CommandInterface, string, float): PromiseInterface */
        private readonly Closure $sendCommandReply,
        private readonly float $ackTimeoutSeconds,
        private readonly ?RuntimeReplayCapture $replayCapture = null,
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
        $this->replayCapture?->captureBgapiDispatch($command, $args);

        $bgapiCommand = new BgapiCommand($command, $args);
        $jobDeferred = new Deferred();
        $dispatchedAt = (float) (microtime(true) * 1_000_000);
        $jobUuid = '';
        $job = new PendingBgapiJob(
            null,
            $command,
            $args,
            $jobDeferred,
            $dispatchedAt,
        );
        $jobId = spl_object_id($job);
        $this->pendingJobs[$jobId] = $job;
        $job->promise()->then(
            function () use ($jobId): void {
                unset($this->pendingJobs[$jobId]);
            },
            function () use ($jobId): void {
                unset($this->pendingJobs[$jobId]);
            },
        );

        $ackPromise = ($this->sendCommandReply)($bgapiCommand, "bgapi {$command}", $this->ackTimeoutSeconds);

        $ackPromise->then(
            function (mixed $reply) use ($command, $job, &$jobUuid): void {
                if (!($reply instanceof BgapiAcceptedReply)) {
                    $job->reject(new RuntimeException(
                        "bgapi {$command}: ack was not BgapiAcceptedReply, got " . get_class($reply),
                    ));
                    return;
                }
                $jobUuid = $reply->jobUuid();
                $job->assignJobUuid($jobUuid);
                $this->tracker->register($job);
                $this->replayCapture?->captureBgapiAck($job, $reply);
            },
            static function (Throwable $e) use ($job): void {
                $job->reject($e);
            },
        );

        return new BgapiJobHandle(
            jobUuidProvider: static function () use ($job): string {
                return $job->jobUuid() ?? '';
            },
            eslCommand: $command,
            eslArgs: $args,
            dispatchedAtMicros: $dispatchedAt,
            promise: $job->promise(),
        );
    }

    public function onBackgroundJobEvent(BackgroundJobEvent $event): void
    {
        $job = $this->tracker->complete($event);
        if ($job instanceof PendingBgapiJob) {
            $this->replayCapture?->captureBgapiCompletion($job, $event);
        }
    }

    public function pendingCount(): int
    {
        return count($this->pendingJobs);
    }

    public function terminateAll(Throwable $reason): void
    {
        foreach ($this->pendingJobs as $job) {
            $jobUuid = $job->jobUuid();
            if ($jobUuid !== null) {
                $this->tracker->abandon($jobUuid, $reason);
                continue;
            }

            $job->reject($reason);
        }
    }
}
