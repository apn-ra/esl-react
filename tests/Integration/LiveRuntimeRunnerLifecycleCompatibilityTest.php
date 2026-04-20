<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Integration;

use Apntalk\EslReact\AsyncEslRuntime;
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

final class LiveRuntimeRunnerLifecycleCompatibilityTest extends AsyncTestCase
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

    public function testOptInLiveRunnerLifecycleObservationAndCleanShutdown(): void
    {
        if (getenv('ESL_REACT_LIVE_TEST') !== '1') {
            self::markTestSkipped('Set ESL_REACT_LIVE_TEST=1 and ESL_REACT_LIVE_HOST to run live FreeSWITCH compatibility tests.');
        }

        if (getenv('ESL_REACT_LIVE_RUNNER_TEST') !== '1') {
            self::markTestSkipped('Set ESL_REACT_LIVE_RUNNER_TEST=1 to run the live runner lifecycle harness.');
        }

        $host = getenv('ESL_REACT_LIVE_HOST');
        if (!is_string($host) || $host === '') {
            self::markTestSkipped('ESL_REACT_LIVE_HOST is required for the live runner lifecycle harness.');
        }

        $config = RuntimeConfig::create(
            host: $host,
            port: $this->envInt('ESL_REACT_LIVE_PORT', 8021),
            password: $this->envString('ESL_REACT_LIVE_PASSWORD', 'ClueCon'),
            retryPolicy: RetryPolicy::disabled(),
            heartbeat: HeartbeatConfig::disabled(),
            commandTimeout: CommandTimeoutConfig::withApiTimeout(5.0),
        );

        $handle = AsyncEslRuntime::runner()->run(new PreparedRuntimeInput(
            endpoint: $this->envString('ESL_REACT_LIVE_RUNNER_ENDPOINT', 'live-freeswitch'),
            runtimeConfig: $config,
        ), $this->loop);

        $markers = [];
        $handle->onLifecycleChange(function (RuntimeLifecycleSnapshot $snapshot) use (&$markers): void {
            $markers[] = $this->lifecycleMarker($snapshot);
        });

        self::assertSame(RuntimeRunnerState::Starting, $handle->state());
        self::assertTrue($handle->lifecycleSnapshot()->isStarting());
        self::assertContains($markers[0]['connection'], ['disconnected', 'connecting']);

        $this->await($handle->startupPromise(), 8.0);

        $live = $handle->lifecycleSnapshot();
        self::assertSame(RuntimeRunnerState::Running, $live->runnerState);
        self::assertSame(ConnectionState::Authenticated, $live->connectionState());
        self::assertSame(SessionState::Active, $live->sessionState());
        self::assertTrue($live->isLive());
        self::assertFalse($live->isReconnecting());
        self::assertFalse($live->isDraining());
        self::assertFalse($live->isStopped());
        self::assertFalse($live->isFailed());

        $liveFeedback = $handle->feedbackSnapshot();
        self::assertSame('not-queued', $liveFeedback->queueState()->value);
        self::assertSame('not-retryable', $liveFeedback->recovery->retryPosture->value);
        self::assertSame('not-draining', $liveFeedback->recovery->drainPosture->value);
        self::assertSame('native', $liveFeedback->recovery->reconstructionPosture->value);
        self::assertSame('continuous', $liveFeedback->recovery->replayContinuity->value);
        self::assertSame('1', $liveFeedback->recovery->generationId->toString());
        self::assertGreaterThanOrEqual(1, $liveFeedback->recovery->connectionGeneration);
        self::assertFalse($liveFeedback->recovery->preparedContextApplied);
        self::assertFalse($liveFeedback->recovery->isRecoverableAfterReconnect);
        self::assertFalse($liveFeedback->recovery->isRecoverableOnlyWithPreparedContext);
        self::assertFalse($liveFeedback->recovery->isTerminallyNonRecoverable);
        self::assertNull($liveFeedback->recovery->lastRecoveryCause);
        self::assertNull($liveFeedback->recovery->lastRecoveryOutcome);
        self::assertNull($liveFeedback->recovery->lastDrainCause);
        self::assertNull($liveFeedback->recovery->lastDrainOutcome);
        self::assertNotNull($liveFeedback->recovery->generationStartedAtMicros);
        self::assertSame([], $liveFeedback->activeOperations);
        self::assertSame([], $liveFeedback->recentTerminalPublications);
        self::assertSame([], $liveFeedback->recentLifecycleSemantics);

        $liveStatus = $handle->statusSnapshot();
        self::assertSame('active', $liveStatus->phase->value);
        self::assertTrue($liveStatus->isRuntimeActive);
        self::assertFalse($liveStatus->isRecoveryInProgress);
        self::assertSame('not-retryable', $liveStatus->recovery->retryPosture->value);
        self::assertSame([], $liveStatus->activeOperations);
        self::assertSame([], $liveStatus->recentTerminalPublications);
        self::assertSame([], $liveStatus->recentLifecycleSemantics);
        self::assertSame('not-retryable', $liveStatus->toArray()['recovery']['retry_posture']);

        self::assertNotEmpty(array_filter(
            $markers,
            static fn(array $marker): bool => $marker['runner'] === 'running'
                && $marker['connection'] === 'authenticated'
                && $marker['session'] === 'active'
                && $marker['live'] === true
                && $marker['reconnecting'] === false
                && $marker['draining'] === false
                && $marker['stopped'] === false
        ));

        $this->await($handle->client()->disconnect(), 2.0);

        $this->waitUntil(function () use ($handle): bool {
            return $handle->lifecycleSnapshot()->connectionState() === ConnectionState::Closed;
        }, 1.0);

        $closed = $handle->lifecycleSnapshot();
        self::assertSame(RuntimeRunnerState::Running, $closed->runnerState);
        self::assertSame(ConnectionState::Closed, $closed->connectionState());
        self::assertSame(SessionState::Disconnected, $closed->sessionState());
        self::assertFalse($closed->isLive());
        self::assertFalse($closed->isReconnecting());
        self::assertFalse($closed->isDraining());
        self::assertTrue($closed->isStopped());
        self::assertFalse($closed->isFailed());

        $closedFeedback = $handle->feedbackSnapshot();
        self::assertSame('drained', $closedFeedback->recovery->drainPosture->value);
        self::assertSame('explicit_disconnect', $closedFeedback->recovery->lastDrainCause);
        self::assertSame('completed', $closedFeedback->recovery->lastDrainOutcome);
        self::assertSame([], $closedFeedback->activeOperations);
        self::assertSame([], $closedFeedback->recentTerminalPublications);
        self::assertSame([], $closedFeedback->recentLifecycleSemantics);

        $closedStatus = $handle->statusSnapshot();
        self::assertSame('closed', $closedStatus->phase->value);
        self::assertFalse($closedStatus->isRuntimeActive);
        self::assertFalse($closedStatus->isRecoveryInProgress);
        self::assertSame('drained', $closedStatus->recovery->drainPosture->value);
        self::assertSame('explicit_disconnect', $closedStatus->recovery->lastDrainCause);
        self::assertSame('completed', $closedStatus->recovery->lastDrainOutcome);
        self::assertSame('drained', $closedStatus->toArray()['recovery']['drain_posture']);

        self::assertNotEmpty(array_filter(
            $markers,
            static fn(array $marker): bool => $marker['connection'] === 'draining'
                && $marker['draining'] === true
                && $marker['reconnecting'] === false
                && $marker['stopped'] === false
        ));
        self::assertNotEmpty(array_filter(
            $markers,
            static fn(array $marker): bool => $marker['connection'] === 'closed'
                && $marker['session'] === 'disconnected'
                && $marker['draining'] === false
                && $marker['reconnecting'] === false
                && $marker['stopped'] === true
        ));
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
}
