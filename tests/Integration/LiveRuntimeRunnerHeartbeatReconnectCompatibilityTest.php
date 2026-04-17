<?php declare(strict_types=1);

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

final class LiveRuntimeRunnerHeartbeatReconnectCompatibilityTest extends AsyncTestCase
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
     *   reconnect_attempts: int
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
            'reconnect_attempts' => $snapshot->reconnectAttempts(),
        ];
    }

    public function testOptInLiveRunnerHeartbeatSecondMissTriggersReconnectAndRecovery(): void
    {
        if (getenv('ESL_REACT_LIVE_TEST') !== '1') {
            self::markTestSkipped('Set ESL_REACT_LIVE_TEST=1 and ESL_REACT_LIVE_HOST to run live FreeSWITCH compatibility tests.');
        }

        if (getenv('ESL_REACT_LIVE_RUNNER_HEARTBEAT_RECONNECT_TEST') !== '1') {
            self::markTestSkipped('Set ESL_REACT_LIVE_RUNNER_HEARTBEAT_RECONNECT_TEST=1 to run the live heartbeat dead/reconnect harness.');
        }

        $host = getenv('ESL_REACT_LIVE_HOST');
        if (!is_string($host) || $host === '') {
            self::markTestSkipped('ESL_REACT_LIVE_HOST is required for the live heartbeat dead/reconnect harness.');
        }

        $disruptCommand = $this->requiredEnv('ESL_REACT_LIVE_HEARTBEAT_DEADPATH_DISRUPT_COMMAND');
        $restoreCommand = $this->requiredEnv('ESL_REACT_LIVE_HEARTBEAT_DEADPATH_RESTORE_COMMAND');

        $handle = AsyncEslRuntime::runner()->run(new PreparedRuntimeInput(
            endpoint: $this->envString('ESL_REACT_LIVE_RUNNER_ENDPOINT', 'live-freeswitch'),
            runtimeConfig: RuntimeConfig::create(
                host: $host,
                port: $this->envInt('ESL_REACT_LIVE_PORT', 8021),
                password: $this->envString('ESL_REACT_LIVE_PASSWORD', 'ClueCon'),
                retryPolicy: RetryPolicy::withMaxAttempts(0, 0.5),
                heartbeat: HeartbeatConfig::withInterval(
                    $this->envFloat('ESL_REACT_LIVE_HEARTBEAT_DEADPATH_INTERVAL_SECONDS', 0.5),
                    $this->envFloat('ESL_REACT_LIVE_HEARTBEAT_DEADPATH_TIMEOUT_SECONDS', 0.1),
                ),
                commandTimeout: CommandTimeoutConfig::withApiTimeout(
                    $this->envFloat('ESL_REACT_LIVE_HEARTBEAT_DEADPATH_API_TIMEOUT', 5.0),
                ),
            ),
        ), $this->loop);

        $markers = [];
        $handle->onLifecycleChange(function (RuntimeLifecycleSnapshot $snapshot) use (&$markers): void {
            $markers[] = $this->lifecycleMarker($snapshot);
        });

        $this->await($handle->startupPromise(), 8.0);

        $live = $handle->lifecycleSnapshot();
        self::assertSame(RuntimeRunnerState::Running, $live->runnerState);
        self::assertSame(ConnectionState::Authenticated, $live->connectionState());
        self::assertSame(SessionState::Active, $live->sessionState());
        self::assertTrue($live->isLive());
        self::assertFalse($live->isReconnecting());
        self::assertFalse($live->isDraining());
        self::assertFalse($live->isStopped());

        $disrupted = false;
        $restored = false;

        try {
            $this->announce(sprintf(
                'Running configured heartbeat dead-path disrupt command: %s',
                $disruptCommand,
            ));
            $this->runAutomationCommand('ESL_REACT_LIVE_HEARTBEAT_DEADPATH_DISRUPT_COMMAND', $disruptCommand);
            $disrupted = true;

            $this->waitUntil(function () use ($handle, &$markers): bool {
                return $handle->lifecycleSnapshot()->connectionState() === ConnectionState::Authenticated
                    && $handle->lifecycleSnapshot()->sessionState() === SessionState::Active
                    && $handle->lifecycleSnapshot()->isLive() === false
                    && array_filter(
                        $markers,
                        static fn (array $marker): bool => $marker['connection'] === 'authenticated'
                            && $marker['session'] === 'active'
                            && $marker['live'] === false
                            && $marker['reconnecting'] === false
                            && $marker['draining'] === false
                    ) !== [];
            }, $this->envFloat('ESL_REACT_LIVE_HEARTBEAT_DEADPATH_DEGRADE_TIMEOUT', 10.0));

            $degraded = $handle->lifecycleSnapshot();
            self::assertSame(ConnectionState::Authenticated, $degraded->connectionState());
            self::assertSame(SessionState::Active, $degraded->sessionState());
            self::assertFalse($degraded->isLive());
            self::assertFalse($degraded->isReconnecting());
            self::assertFalse($degraded->isDraining());
            self::assertFalse($degraded->isStopped());

            $this->waitUntil(function () use ($handle, &$markers): bool {
                return array_filter(
                    $markers,
                    static fn (array $marker): bool => $marker['connection'] === 'reconnecting'
                        && $marker['session'] === 'disconnected'
                        && $marker['live'] === false
                        && $marker['reconnecting'] === true
                        && $marker['draining'] === false
                        && $marker['stopped'] === false
                ) !== [];
            }, $this->envFloat('ESL_REACT_LIVE_HEARTBEAT_DEADPATH_RECONNECT_OBSERVE_TIMEOUT', 15.0));

            $recovering = $handle->lifecycleSnapshot();
            self::assertSame(RuntimeRunnerState::Running, $recovering->runnerState);
            self::assertSame(SessionState::Disconnected, $recovering->sessionState());
            self::assertFalse($recovering->isLive());
            self::assertFalse($recovering->isDraining());
            self::assertFalse($recovering->isStopped());
            self::assertContains($recovering->connectionState(), [ConnectionState::Reconnecting, ConnectionState::Disconnected]);

            self::assertSame([], array_filter(
                $markers,
                static fn (array $marker): bool => $marker['draining'] === true
                    || $marker['connection'] === 'draining'
            ), 'Heartbeat-failure reconnect should not be reported as drain.');

            $this->announce(sprintf(
                'Running configured heartbeat dead-path restore command: %s',
                $restoreCommand,
            ));
            $this->runAutomationCommand('ESL_REACT_LIVE_HEARTBEAT_DEADPATH_RESTORE_COMMAND', $restoreCommand);
            $restored = true;

            $this->waitUntil(function () use ($handle, &$markers): bool {
                return $handle->lifecycleSnapshot()->connectionState() === ConnectionState::Authenticated
                    && $handle->lifecycleSnapshot()->sessionState() === SessionState::Active
                    && $handle->lifecycleSnapshot()->isLive()
                    && count(array_filter(
                        $markers,
                        static fn (array $marker): bool => $marker['connection'] === 'authenticated'
                            && $marker['session'] === 'active'
                            && $marker['live'] === true
                            && $marker['reconnecting'] === false
                            && $marker['draining'] === false
                            && $marker['stopped'] === false
                    )) >= 2;
            }, $this->envFloat('ESL_REACT_LIVE_HEARTBEAT_DEADPATH_RECOVERY_TIMEOUT', 20.0));

            $recovered = $handle->lifecycleSnapshot();
            self::assertSame(RuntimeRunnerState::Running, $recovered->runnerState);
            self::assertSame(ConnectionState::Authenticated, $recovered->connectionState());
            self::assertSame(SessionState::Active, $recovered->sessionState());
            self::assertTrue($recovered->isLive());
            self::assertFalse($recovered->isReconnecting());
            self::assertFalse($recovered->isDraining());
            self::assertFalse($recovered->isStopped());
        } finally {
            if ($disrupted && !$restored) {
                $this->announce('Attempting heartbeat dead-path restore command during cleanup.');
                $this->runAutomationCommand('ESL_REACT_LIVE_HEARTBEAT_DEADPATH_RESTORE_COMMAND', $restoreCommand);
            }
        }

        $this->await($handle->client()->disconnect(), 2.0);
    }

    private function announce(string $message): void
    {
        fwrite(STDOUT, sprintf("[live runner heartbeat deadpath] %s\n", $message));
        fflush(STDOUT);
    }

    private function requiredEnv(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || trim($value) === '') {
            self::markTestSkipped(sprintf('%s is required for the live heartbeat dead/reconnect harness.', $name));
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
