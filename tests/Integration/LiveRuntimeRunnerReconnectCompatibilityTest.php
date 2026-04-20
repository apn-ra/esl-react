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

final class LiveRuntimeRunnerReconnectCompatibilityTest extends AsyncTestCase
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

    public function testOptInLiveRunnerReconnectObservationAndRecovery(): void
    {
        if (getenv('ESL_REACT_LIVE_TEST') !== '1') {
            self::markTestSkipped('Set ESL_REACT_LIVE_TEST=1 and ESL_REACT_LIVE_HOST to run live FreeSWITCH compatibility tests.');
        }

        if (getenv('ESL_REACT_LIVE_RUNNER_RECONNECT_TEST') !== '1') {
            self::markTestSkipped('Set ESL_REACT_LIVE_RUNNER_RECONNECT_TEST=1 to run the automated live runner reconnect harness.');
        }

        $host = getenv('ESL_REACT_LIVE_HOST');
        if (!is_string($host) || $host === '') {
            self::markTestSkipped('ESL_REACT_LIVE_HOST is required for the live runner reconnect harness.');
        }

        $disruptCommand = $this->requiredEnv('ESL_REACT_LIVE_RECONNECT_DISRUPT_COMMAND');
        $restoreCommand = $this->requiredEnv('ESL_REACT_LIVE_RECONNECT_RESTORE_COMMAND');
        $eventName = $this->envString('ESL_REACT_LIVE_EVENT_NAME', 'HEARTBEAT');
        $disconnectTimeout = $this->envFloat('ESL_REACT_LIVE_RECONNECT_DISCONNECT_TIMEOUT', 20.0);
        $reconnectTimeout = $this->envFloat('ESL_REACT_LIVE_RECONNECT_TIMEOUT', 30.0);
        $disruptSettleSeconds = $this->envFloat('ESL_REACT_LIVE_RECONNECT_DISRUPT_SETTLE_SECONDS', 1.0);
        $restoreSettleSeconds = $this->envFloat('ESL_REACT_LIVE_RECONNECT_RESTORE_SETTLE_SECONDS', 1.0);

        $config = RuntimeConfig::create(
            host: $host,
            port: $this->envInt('ESL_REACT_LIVE_PORT', 8021),
            password: $this->envString('ESL_REACT_LIVE_PASSWORD', 'ClueCon'),
            retryPolicy: RetryPolicy::withMaxAttempts(0, 0.5),
            heartbeat: HeartbeatConfig::withInterval(6.0, 1.0),
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

        $this->await($handle->startupPromise(), 8.0);
        $this->await($handle->client()->subscriptions()->subscribe($eventName), 6.0);

        $live = $handle->lifecycleSnapshot();
        self::assertSame(RuntimeRunnerState::Running, $live->runnerState);
        self::assertSame(ConnectionState::Authenticated, $live->connectionState());
        self::assertSame(SessionState::Active, $live->sessionState());
        self::assertTrue($live->isLive());
        self::assertFalse($live->isReconnecting());
        self::assertFalse($live->isDraining());
        self::assertSame([$eventName], $handle->client()->subscriptions()->activeEventNames());

        $disrupted = false;
        $restored = false;

        try {
            $this->announce(sprintf(
                'Running configured disrupt command for runner reconnect validation: %s',
                $disruptCommand,
            ));
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
                static fn(array $marker): bool => $marker['connection'] === 'reconnecting'
                    && $marker['session'] === 'disconnected'
                    && $marker['reconnecting'] === true
                    && $marker['draining'] === false
                    && $marker['stopped'] === false
            ));
            self::assertSame([], array_filter(
                $markers,
                static fn(array $marker): bool => $marker['connection'] === 'draining'
                    || $marker['draining'] === true
            ), 'Unexpected transport-loss reconnect should not be reported as drain.');

            $this->announce(sprintf(
                'Running configured restore command for runner reconnect validation: %s',
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
                    && $handle->client()->subscriptions()->activeEventNames() === [$eventName];
            }, $reconnectTimeout);

            $recovered = $handle->lifecycleSnapshot();
            self::assertSame(RuntimeRunnerState::Running, $recovered->runnerState);
            self::assertSame(ConnectionState::Authenticated, $recovered->connectionState());
            self::assertSame(SessionState::Active, $recovered->sessionState());
            self::assertTrue($recovered->isLive());
            self::assertFalse($recovered->isReconnecting());
            self::assertFalse($recovered->isDraining());
            self::assertFalse($recovered->isStopped());
            self::assertSame([$eventName], $handle->client()->subscriptions()->activeEventNames());

            self::assertGreaterThanOrEqual(2, count(array_filter(
                $markers,
                static fn(array $marker): bool => $marker['runner'] === 'running'
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

    private function announce(string $message): void
    {
        fwrite(STDOUT, sprintf("[live runner reconnect] %s\n", $message));
        fflush(STDOUT);
    }

    private function requiredEnv(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || trim($value) === '') {
            self::markTestSkipped(sprintf('%s is required for the automated live runner reconnect harness.', $name));
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
