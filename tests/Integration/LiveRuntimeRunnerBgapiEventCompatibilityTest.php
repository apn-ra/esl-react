<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Integration;

use Apntalk\EslCore\Correlation\EventEnvelope;
use Apntalk\EslCore\Events\BackgroundJobEvent;
use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Bgapi\BgapiJobHandle;
use Apntalk\EslReact\Config\CommandTimeoutConfig;
use Apntalk\EslReact\Config\HeartbeatConfig;
use Apntalk\EslReact\Config\RetryPolicy;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Runner\PreparedRuntimeInput;
use Apntalk\EslReact\Runner\RuntimeLifecycleSnapshot;
use Apntalk\EslReact\Runner\RuntimeRunnerState;
use Apntalk\EslReact\Session\SessionState;
use Apntalk\EslReact\Tests\Support\AsyncTestCase;
use React\Promise\Deferred;

final class LiveRuntimeRunnerBgapiEventCompatibilityTest extends AsyncTestCase
{
    /**
     * @return array{
     *   runner: string,
     *   connection: ?string,
     *   session: ?string,
     *   live: bool,
     *   reconnecting: bool,
     *   draining: bool,
     *   stopped: bool,
     *   failed: bool
     * }
     */
    private function lifecycleMarker(RuntimeLifecycleSnapshot $snapshot): array
    {
        return [
            'runner' => $snapshot->runnerState->value,
            'connection' => $snapshot->connectionState()?->value,
            'session' => $snapshot->sessionState()?->value,
            'live' => $snapshot->isLive(),
            'reconnecting' => $snapshot->isReconnecting(),
            'draining' => $snapshot->isDraining(),
            'stopped' => $snapshot->isStopped(),
            'failed' => $snapshot->isFailed(),
        ];
    }

    public function testOptInLiveRunnerEventAndBgapiActivityKeepLifecycleTruthStable(): void
    {
        if (getenv('ESL_REACT_LIVE_TEST') !== '1') {
            self::markTestSkipped('Set ESL_REACT_LIVE_TEST=1 and ESL_REACT_LIVE_HOST to run live FreeSWITCH compatibility tests.');
        }

        if (getenv('ESL_REACT_LIVE_RUNNER_BGAPI_EVENT_TEST') !== '1') {
            self::markTestSkipped('Set ESL_REACT_LIVE_RUNNER_BGAPI_EVENT_TEST=1 to run the live runner bgapi/event harness.');
        }

        $host = getenv('ESL_REACT_LIVE_HOST');
        if (!is_string($host) || $host === '') {
            self::markTestSkipped('ESL_REACT_LIVE_HOST is required for the live runner bgapi/event harness.');
        }

        $eventName = $this->envString('ESL_REACT_LIVE_RUNNER_BGAPI_EVENT_NAME', $this->envString('ESL_REACT_LIVE_EVENT_NAME', 'HEARTBEAT'));
        if ($eventName === 'BACKGROUND_JOB') {
            self::markTestSkipped('ESL_REACT_LIVE_RUNNER_BGAPI_EVENT_NAME must not be BACKGROUND_JOB for this combined harness.');
        }

        $triggerApiCommand = getenv('ESL_REACT_LIVE_RUNNER_BGAPI_EVENT_TRIGGER_API');
        if (!is_string($triggerApiCommand) || $triggerApiCommand === '') {
            $triggerApiCommand = getenv('ESL_REACT_LIVE_EVENT_TRIGGER_API');
            if (!is_string($triggerApiCommand) || $triggerApiCommand === '') {
                $triggerApiCommand = null;
            }
        }

        $bgapiCommand = $this->envString('ESL_REACT_LIVE_RUNNER_BGAPI_COMMAND', $this->envString('ESL_REACT_LIVE_BGAPI_COMMAND', 'msleep'));
        $bgapiArgs = getenv('ESL_REACT_LIVE_RUNNER_BGAPI_ARGS');
        if (!is_string($bgapiArgs)) {
            $bgapiArgs = getenv('ESL_REACT_LIVE_BGAPI_ARGS');
            if (!is_string($bgapiArgs)) {
                $bgapiArgs = '1000';
            }
        }

        $handle = AsyncEslRuntime::runner()->run(new PreparedRuntimeInput(
            endpoint: $this->envString('ESL_REACT_LIVE_RUNNER_ENDPOINT', 'live-freeswitch'),
            runtimeConfig: RuntimeConfig::create(
                host: $host,
                port: $this->envInt('ESL_REACT_LIVE_PORT', 8021),
                password: $this->envString('ESL_REACT_LIVE_PASSWORD', 'ClueCon'),
                retryPolicy: RetryPolicy::disabled(),
                heartbeat: HeartbeatConfig::disabled(),
                commandTimeout: CommandTimeoutConfig::default()->withBgapiOrphanTimeout(
                    $this->envFloat('ESL_REACT_LIVE_RUNNER_BGAPI_TIMEOUT', $this->envFloat('ESL_REACT_LIVE_BGAPI_TIMEOUT', 20.0)),
                ),
            ),
        ), $this->loop);

        $markers = [];
        $handle->onLifecycleChange(function (RuntimeLifecycleSnapshot $snapshot) use (&$markers): void {
            $markers[] = $this->lifecycleMarker($snapshot);
        });

        $this->await($handle->startupPromise(), 8.0);
        $this->assertLiveLifecycle($handle->lifecycleSnapshot());

        $eventDeferred = new Deferred();
        $handle->client()->events()->onRawEnvelope(function (EventEnvelope $envelope) use ($eventName, $eventDeferred): void {
            if ($envelope->event()->eventName() !== $eventName) {
                return;
            }

            $eventDeferred->resolve($envelope);
        });

        $this->await($handle->client()->subscriptions()->subscribe($eventName, 'BACKGROUND_JOB'), 6.0);
        $this->assertLiveLifecycle($handle->lifecycleSnapshot());

        if ($triggerApiCommand !== null) {
            $this->await($handle->client()->api($triggerApiCommand), 6.0);
        }

        $envelope = $this->await(
            $eventDeferred->promise(),
            $this->envFloat('ESL_REACT_LIVE_RUNNER_BGAPI_EVENT_TIMEOUT', $this->envFloat('ESL_REACT_LIVE_EVENT_TIMEOUT', 25.0)),
        );
        self::assertInstanceOf(EventEnvelope::class, $envelope);
        self::assertSame($eventName, $envelope->event()->eventName());

        $metadata = $envelope->metadata();
        self::assertTrue(
            $metadata->protocolSequence() !== null
            || $envelope->event()->coreUuid() !== null
            || $envelope->event()->uniqueId() !== null,
            'Expected the live event to include at least one identifying protocol field.',
        );

        $this->assertLiveLifecycle($handle->lifecycleSnapshot());

        $job = $handle->client()->bgapi($bgapiCommand, $bgapiArgs);
        self::assertInstanceOf(BgapiJobHandle::class, $job);
        self::assertSame($bgapiCommand, $job->eslCommand());
        self::assertSame($bgapiArgs, $job->eslArgs());

        $this->waitUntil(
            fn(): bool => $job->jobUuid() !== '' && $handle->feedbackSnapshot()->activeOperations !== [],
            6.0,
        );

        $pendingFeedback = $handle->feedbackSnapshot();
        self::assertSame('in-flight', $pendingFeedback->queueState()->value);
        self::assertSame(1, $pendingFeedback->pendingBgapiJobCount());
        self::assertCount(1, $pendingFeedback->activeOperations);
        self::assertSame('bgapi', $pendingFeedback->activeOperations[0]->kind);
        self::assertSame('in-flight', $pendingFeedback->activeOperations[0]->queueState->value);
        self::assertSame($job->jobUuid(), $pendingFeedback->activeOperations[0]->jobUuid);
        self::assertSame(
            $pendingFeedback->recovery->generationId->toString(),
            $pendingFeedback->activeOperations[0]->recoveryGenerationId,
        );

        $pendingStatus = $handle->statusSnapshot();
        self::assertSame('active', $pendingStatus->phase->value);
        self::assertCount(1, $pendingStatus->activeOperations);
        self::assertSame($job->jobUuid(), $pendingStatus->activeOperations[0]->jobUuid);
        self::assertSame('in-flight', $pendingStatus->toArray()['active_operations'][0]['queue_state']);

        $activeOperationId = $pendingFeedback->activeOperations[0]->operationId->toString();

        $this->assertLiveLifecycle($handle->lifecycleSnapshot());

        $completion = $this->await(
            $job->promise(),
            $this->envFloat('ESL_REACT_LIVE_RUNNER_BGAPI_TIMEOUT', $this->envFloat('ESL_REACT_LIVE_BGAPI_TIMEOUT', 20.0)),
        );
        self::assertInstanceOf(BackgroundJobEvent::class, $completion);
        self::assertSame($job->jobUuid(), $completion->jobUuid());
        self::assertNotSame('', trim($completion->result()), 'Expected a non-empty BACKGROUND_JOB completion result.');

        $afterActivity = $handle->lifecycleSnapshot();
        $this->assertLiveLifecycle($afterActivity);
        self::assertSame(0, $afterActivity->health?->pendingBgapiJobCount);

        $completedFeedback = $handle->feedbackSnapshot();
        self::assertSame('not-queued', $completedFeedback->queueState()->value);
        self::assertSame([], $completedFeedback->activeOperations);
        self::assertNotSame([], $completedFeedback->recentTerminalPublications);
        self::assertSame(
            $activeOperationId,
            $completedFeedback->recentTerminalPublications[0]->operationId,
        );
        self::assertSame(
            'completed',
            $completedFeedback->recentTerminalPublications[0]->publication->terminalCause()->value,
        );
        self::assertSame(
            'final',
            $completedFeedback->recentTerminalPublications[0]->publication->finality()->value,
        );

        $completedStatus = $handle->statusSnapshot();
        self::assertSame('active', $completedStatus->phase->value);
        self::assertSame([], $completedStatus->activeOperations);
        self::assertNotSame([], $completedStatus->recentTerminalPublications);
        self::assertSame(
            'completed',
            $completedStatus->toArray()['recent_terminal_publications'][0]['publication']['terminalCause'],
        );

        self::assertSame([], array_filter(
            $markers,
            static fn(array $marker): bool => $marker['connection'] === 'reconnecting'
                || $marker['reconnecting'] === true
                || $marker['connection'] === 'draining'
                || $marker['draining'] === true
                || $marker['connection'] === 'closed'
                || $marker['stopped'] === true
                || $marker['failed'] === true
        ), 'Live runner bgapi/event activity should not report reconnect, drain, closed, or failed lifecycle markers.');

        $this->await($handle->client()->disconnect(), 2.0);

        $closed = $handle->lifecycleSnapshot();
        self::assertSame(ConnectionState::Closed, $closed->connectionState());
        self::assertSame(SessionState::Disconnected, $closed->sessionState());
        self::assertFalse($closed->isLive());
        self::assertTrue($closed->isStopped());
    }

    private function assertLiveLifecycle(RuntimeLifecycleSnapshot $snapshot): void
    {
        self::assertSame(RuntimeRunnerState::Running, $snapshot->runnerState);
        self::assertSame(ConnectionState::Authenticated, $snapshot->connectionState());
        self::assertSame(SessionState::Active, $snapshot->sessionState());
        self::assertTrue($snapshot->isLive());
        self::assertFalse($snapshot->isReconnecting());
        self::assertFalse($snapshot->isDraining());
        self::assertFalse($snapshot->isStopped());
        self::assertFalse($snapshot->isFailed());
    }

    private function envString(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function envInt(string $name, int $default): int
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            return $default;
        }

        if (!ctype_digit($value)) {
            self::markTestSkipped(sprintf('%s must be a positive integer when set.', $name));
        }

        return (int) $value;
    }

    private function envFloat(string $name, float $default): float
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            return $default;
        }

        if (!is_numeric($value) || (float) $value <= 0) {
            self::markTestSkipped(sprintf('%s must be a positive number when set.', $name));
        }

        return (float) $value;
    }
}
