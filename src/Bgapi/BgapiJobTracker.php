<?php declare(strict_types=1);

namespace Apntalk\EslReact\Bgapi;

use Apntalk\EslCore\Events\BackgroundJobEvent;
use Apntalk\EslReact\Exceptions\CommandTimeoutException;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

final class BgapiJobTracker
{
    /** @var array<string, PendingBgapiJob> keyed by jobUuid */
    private array $jobs = [];

    /** @var array<string, TimerInterface> */
    private array $timers = [];

    public function __construct(
        private readonly LoopInterface $loop,
        private readonly float $orphanTimeoutSeconds,
    ) {}

    public function register(PendingBgapiJob $job): void
    {
        $uuid = $job->jobUuid();
        $this->jobs[$uuid] = $job;

        $timer = $this->loop->addTimer(
            $this->orphanTimeoutSeconds,
            function () use ($uuid): void {
                $this->timeout($uuid);
            },
        );
        $this->timers[$uuid] = $timer;
        $job->attachTimer($timer);
    }

    public function complete(BackgroundJobEvent $event): bool
    {
        $uuid = $event->jobUuid();
        if ($uuid === null || !isset($this->jobs[$uuid])) {
            return false;
        }
        $job = $this->jobs[$uuid];
        $this->cancelTimer($uuid);
        unset($this->jobs[$uuid]);
        $job->resolve($event);
        return true;
    }

    public function abandon(string $jobUuid, \Throwable $reason): void
    {
        if (!isset($this->jobs[$jobUuid])) {
            return;
        }
        $job = $this->jobs[$jobUuid];
        $this->cancelTimer($jobUuid);
        unset($this->jobs[$jobUuid]);
        $job->reject($reason);
    }

    public function abandonAll(\Throwable $reason): void
    {
        foreach (array_keys($this->jobs) as $uuid) {
            $this->abandon($uuid, $reason);
        }
    }

    public function pendingCount(): int
    {
        return count($this->jobs);
    }

    /** @return list<string> */
    public function pendingJobUuids(): array
    {
        return array_keys($this->jobs);
    }

    private function timeout(string $uuid): void
    {
        if (!isset($this->jobs[$uuid])) {
            return;
        }
        $job = $this->jobs[$uuid];
        unset($this->jobs[$uuid], $this->timers[$uuid]);
        $job->reject(new CommandTimeoutException(
            "bgapi {$job->eslCommand()}",
            $this->orphanTimeoutSeconds,
        ));
    }

    private function cancelTimer(string $uuid): void
    {
        if (isset($this->timers[$uuid])) {
            $this->loop->cancelTimer($this->timers[$uuid]);
            unset($this->timers[$uuid]);
        }
    }
}
