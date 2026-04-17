<?php declare(strict_types=1);

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

final class LiveRuntimeRunnerReconnectBgapiEventCompatibilityTest extends AsyncTestCase
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
     *   failed: bool,
     *   reconnectAttempts: int
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
            'reconnectAttempts' => $snapshot->reconnectAttempts(),
        ];
    }

    public function testOptInLiveRunnerReconnectThenEventAndBgapiActivityStayTruthful(): void
    {
        if (getenv('ESL_REACT_LIVE_TEST') !== '1') {
            self::markTestSkipped('Set ESL_REACT_LIVE_TEST=1 and ESL_REACT_LIVE_HOST to run live FreeSWITCH compatibility tests.');
        }

        if (getenv('ESL_REACT_LIVE_RUNNER_RECONNECT_BGAPI_EVENT_TEST') !== '1') {
            self::markTestSkipped('Set ESL_REACT_LIVE_RUNNER_RECONNECT_BGAPI_EVENT_TEST=1 to run the live runner reconnect + bgapi/event harness.');
        }

        $host = getenv('ESL_REACT_LIVE_HOST');
        if (!is_string($host) || $host === '') {
            self::markTestSkipped('ESL_REACT_LIVE_HOST is required for the live runner reconnect + bgapi/event harness.');
        }

        $disruptCommand = $this->requiredEnv('ESL_REACT_LIVE_RECONNECT_DISRUPT_COMMAND');
        $restoreCommand = $this->requiredEnv('ESL_REACT_LIVE_RECONNECT_RESTORE_COMMAND');
        $eventName = $this->envString('ESL_REACT_LIVE_RUNNER_RECONNECT_BGAPI_EVENT_NAME', $this->envString('ESL_REACT_LIVE_EVENT_NAME', 'HEARTBEAT'));
        if ($eventName === 'BACKGROUND_JOB') {
            self::markTestSkipped('ESL_REACT_LIVE_RUNNER_RECONNECT_BGAPI_EVENT_NAME must not be BACKGROUND_JOB for this combined harness.');
        }

        $triggerApiCommand = getenv('ESL_REACT_LIVE_RUNNER_RECONNECT_BGAPI_EVENT_TRIGGER_API');
        if (!is_string($triggerApiCommand) || $triggerApiCommand === '') {
            $triggerApiCommand = getenv('ESL_REACT_LIVE_EVENT_TRIGGER_API');
            if (!is_string($triggerApiCommand) || $triggerApiCommand === '') {
                $triggerApiCommand = null;
            }
        }

        $bgapiCommand = $this->envString('ESL_REACT_LIVE_RUNNER_RECONNECT_BGAPI_COMMAND', $this->envString('ESL_REACT_LIVE_BGAPI_COMMAND', 'status'));
        $bgapiArgs = getenv('ESL_REACT_LIVE_RUNNER_RECONNECT_BGAPI_ARGS');
        if (!is_string($bgapiArgs)) {
            $bgapiArgs = getenv('ESL_REACT_LIVE_BGAPI_ARGS');
            if (!is_string($bgapiArgs)) {
                $bgapiArgs = '';
            }
        }

        $eventTimeout = $this->envFloat('ESL_REACT_LIVE_RUNNER_RECONNECT_BGAPI_EVENT_TIMEOUT', $this->envFloat('ESL_REACT_LIVE_EVENT_TIMEOUT', 25.0));
        $bgapiTimeout = $this->envFloat('ESL_REACT_LIVE_RUNNER_RECONNECT_BGAPI_TIMEOUT', $this->envFloat('ESL_REACT_LIVE_BGAPI_TIMEOUT', 20.0));
        $disconnectTimeout = $this->envFloat('ESL_REACT_LIVE_RECONNECT_DISCONNECT_TIMEOUT', 20.0);
        $reconnectTimeout = $this->envFloat('ESL_REACT_LIVE_RECONNECT_TIMEOUT', 30.0);
        $disruptSettleSeconds = $this->envFloat('ESL_REACT_LIVE_RECONNECT_DISRUPT_SETTLE_SECONDS', 1.0);
        $restoreSettleSeconds = $this->envFloat('ESL_REACT_LIVE_RECONNECT_RESTORE_SETTLE_SECONDS', 1.0);

        $handle = AsyncEslRuntime::runner()->run(new PreparedRuntimeInput(
            endpoint: $this->envString('ESL_REACT_LIVE_RUNNER_ENDPOINT', 'live-freeswitch'),
            runtimeConfig: RuntimeConfig::create(
                host: $host,
                port: $this->envInt('ESL_REACT_LIVE_PORT', 8021),
                password: $this->envString('ESL_REACT_LIVE_PASSWORD', 'ClueCon'),
                retryPolicy: RetryPolicy::withMaxAttempts(0, 0.5),
                heartbeat: HeartbeatConfig::withInterval(6.0, 1.0),
                commandTimeout: CommandTimeoutConfig::default()->withBgapiOrphanTimeout($bgapiTimeout),
            ),
        ), $this->loop);

        $markers = [];
        $handle->onLifecycleChange(function (RuntimeLifecycleSnapshot $snapshot) use (&$markers): void {
            $markers[] = $this->lifecycleMarker($snapshot);
        });

        $phase = 'startup';
        $preFaultEvent = new Deferred();
        $postReconnectEvent = new Deferred();
        $handle->client()->events()->onRawEnvelope(
            function (EventEnvelope $envelope) use ($eventName, &$phase, $preFaultEvent, $postReconnectEvent): void {
                if ($envelope->event()->eventName() !== $eventName) {
                    return;
                }

                if ($phase === 'await_pre_fault_event') {
                    $preFaultEvent->resolve($envelope);
                }

                if ($phase === 'await_post_reconnect_event') {
                    $postReconnectEvent->resolve($envelope);
                }
            },
        );

        $this->await($handle->startupPromise(), 8.0);
        $this->assertLiveLifecycle($handle->lifecycleSnapshot());

        $this->await($handle->client()->subscriptions()->subscribe($eventName, 'BACKGROUND_JOB'), 6.0);
        $this->assertDesiredSubscriptionsRestored($handle->client()->subscriptions()->activeEventNames(), $eventName);

        $phase = 'await_pre_fault_event';
        if ($triggerApiCommand !== null) {
            $this->await($handle->client()->api($triggerApiCommand), 6.0);
        }

        $preFaultEnvelope = $this->await($preFaultEvent->promise(), $eventTimeout);
        self::assertInstanceOf(EventEnvelope::class, $preFaultEnvelope);
        self::assertSame($eventName, $preFaultEnvelope->event()->eventName());
        $this->assertIdentifiedLiveEvent($preFaultEnvelope);
        $this->assertLiveLifecycle($handle->lifecycleSnapshot());

        $disrupted = false;
        $restored = false;

        try {
            $this->announce(sprintf(
                'Running configured disrupt command for live reconnect + bgapi/event validation: %s',
                $disruptCommand,
            ));
            $phase = 'await_reconnect';
            $this->runAutomationCommand('ESL_REACT_LIVE_RECONNECT_DISRUPT_COMMAND', $disruptCommand);
            $disrupted = true;
            $this->runLoopFor($disruptSettleSeconds);

            $this->waitUntil(function () use ($handle): bool {
                $snapshot = $handle->lifecycleSnapshot();

                return $snapshot->connectionState() === ConnectionState::Reconnecting
                    || $snapshot->connectionState() === ConnectionState::Disconnected
                    || $snapshot->sessionState() === SessionState::Disconnected;
            }, $disconnectTimeout);

            $reconnecting = $handle->lifecycleSnapshot();
            self::assertSame(RuntimeRunnerState::Running, $reconnecting->runnerState);
            self::assertSame(SessionState::Disconnected, $reconnecting->sessionState());
            self::assertFalse($reconnecting->isLive());
            self::assertFalse($reconnecting->isDraining());
            self::assertFalse($reconnecting->isStopped());
            self::assertContains($reconnecting->connectionState(), [ConnectionState::Reconnecting, ConnectionState::Disconnected]);

            self::assertNotEmpty(array_filter(
                $markers,
                static fn (array $marker): bool => $marker['connection'] === 'reconnecting'
                    && $marker['session'] === 'disconnected'
                    && $marker['reconnecting'] === true
                    && $marker['draining'] === false
                    && $marker['stopped'] === false
            ));
            self::assertSame([], array_filter(
                $markers,
                static fn (array $marker): bool => $marker['connection'] === 'draining'
                    || $marker['draining'] === true
            ), 'Unexpected transport-loss reconnect with event/bgapi subscriptions should not be reported as drain.');

            $this->announce(sprintf(
                'Running configured restore command for live reconnect + bgapi/event validation: %s',
                $restoreCommand,
            ));
            $this->runAutomationCommand('ESL_REACT_LIVE_RECONNECT_RESTORE_COMMAND', $restoreCommand);
            $restored = true;
            $this->runLoopFor($restoreSettleSeconds);

            $this->waitUntil(function () use ($handle, $eventName): bool {
                $snapshot = $handle->lifecycleSnapshot();

                return $snapshot->connectionState() === ConnectionState::Authenticated
                    && $snapshot->sessionState() === SessionState::Active
                    && $snapshot->isLive()
                    && $this->hasDesiredSubscriptions($handle->client()->subscriptions()->activeEventNames(), $eventName);
            }, $reconnectTimeout);

            $recovered = $handle->lifecycleSnapshot();
            $this->assertLiveLifecycle($recovered);
            $this->assertDesiredSubscriptionsRestored($handle->client()->subscriptions()->activeEventNames(), $eventName);

            $phase = 'await_post_reconnect_event';
            if ($triggerApiCommand !== null) {
                $this->await($handle->client()->api($triggerApiCommand), 6.0);
            }

            $postReconnectEnvelope = $this->await($postReconnectEvent->promise(), $eventTimeout);
            self::assertInstanceOf(EventEnvelope::class, $postReconnectEnvelope);
            self::assertSame($eventName, $postReconnectEnvelope->event()->eventName());
            $this->assertIdentifiedLiveEvent($postReconnectEnvelope);
            $this->assertLiveLifecycle($handle->lifecycleSnapshot());

            $job = $handle->client()->bgapi($bgapiCommand, $bgapiArgs);
            self::assertInstanceOf(BgapiJobHandle::class, $job);
            self::assertSame($bgapiCommand, $job->eslCommand());
            self::assertSame($bgapiArgs, $job->eslArgs());

            $this->waitUntil(fn (): bool => $job->jobUuid() !== '', 6.0);
            self::assertNotSame('', $job->jobUuid(), 'Expected a non-empty Job-UUID after post-reconnect bgapi ack.');
            $this->assertLiveLifecycle($handle->lifecycleSnapshot());

            $completion = $this->await($job->promise(), $bgapiTimeout);
            self::assertInstanceOf(BackgroundJobEvent::class, $completion);
            self::assertSame($job->jobUuid(), $completion->jobUuid());
            self::assertNotSame('', trim($completion->result()), 'Expected a non-empty post-reconnect BACKGROUND_JOB completion result.');

            $afterActivity = $handle->lifecycleSnapshot();
            $this->assertLiveLifecycle($afterActivity);
            self::assertSame(0, $afterActivity->health?->pendingBgapiJobCount);
            self::assertGreaterThanOrEqual(2, count(array_filter(
                $markers,
                static fn (array $marker): bool => $marker['runner'] === 'running'
                    && $marker['connection'] === 'authenticated'
                    && $marker['session'] === 'active'
                    && $marker['live'] === true
                    && $marker['reconnecting'] === false
                    && $marker['draining'] === false
                    && $marker['stopped'] === false
            )));
        } finally {
            if ($disrupted && !$restored) {
                $this->announce('Attempting reconnect restore command during cleanup.');
                $this->runAutomationCommand('ESL_REACT_LIVE_RECONNECT_RESTORE_COMMAND', $restoreCommand);
                $this->runLoopFor($restoreSettleSeconds);
            }
        }

        $this->await($handle->client()->disconnect(), 2.0);

        $this->waitUntil(function () use ($handle): bool {
            return $handle->lifecycleSnapshot()->connectionState() === ConnectionState::Closed;
        }, 1.0);

        $closed = $handle->lifecycleSnapshot();
        self::assertSame(ConnectionState::Closed, $closed->connectionState());
        self::assertSame(SessionState::Disconnected, $closed->sessionState());
        self::assertFalse($closed->isLive());
        self::assertFalse($closed->isReconnecting());
        self::assertFalse($closed->isDraining());
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

    private function assertIdentifiedLiveEvent(EventEnvelope $envelope): void
    {
        $metadata = $envelope->metadata();
        self::assertTrue(
            $metadata->protocolSequence() !== null
            || $envelope->event()->coreUuid() !== null
            || $envelope->event()->uniqueId() !== null,
            'Expected the live event to include at least one identifying protocol field.',
        );
    }

    /**
     * @param list<string> $activeEventNames
     */
    private function assertDesiredSubscriptionsRestored(array $activeEventNames, string $eventName): void
    {
        self::assertTrue($this->hasDesiredSubscriptions($activeEventNames, $eventName));
    }

    /**
     * @param list<string> $activeEventNames
     */
    private function hasDesiredSubscriptions(array $activeEventNames, string $eventName): bool
    {
        return in_array($eventName, $activeEventNames, true)
            && in_array('BACKGROUND_JOB', $activeEventNames, true);
    }

    private function announce(string $message): void
    {
        fwrite(STDOUT, sprintf("[live runner reconnect+bgapi/event] %s\n", $message));
        fflush(STDOUT);
    }

    private function requiredEnv(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || trim($value) === '') {
            self::markTestSkipped(sprintf('%s is required for the automated live runner reconnect + bgapi/event harness.', $name));
        }

        return trim($value);
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

    private function runAutomationCommand(string $name, string $command): void
    {
        $output = [];
        $exitCode = 0;

        exec('/bin/sh -lc ' . escapeshellarg($command) . ' 2>&1', $output, $exitCode);

        self::assertSame(
            0,
            $exitCode,
            sprintf(
                '%s failed with exit code %d. Output:%s%s',
                $name,
                $exitCode,
                PHP_EOL,
                implode(PHP_EOL, $output),
            ),
        );
    }
}
