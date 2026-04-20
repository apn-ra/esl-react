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

final class LiveRuntimeRunnerLivenessCompatibilityTest extends AsyncTestCase
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

    public function testOptInLiveRunnerLivenessDegradesAndRecoversWithoutFalseReconnect(): void
    {
        if (getenv('ESL_REACT_LIVE_TEST') !== '1') {
            self::markTestSkipped('Set ESL_REACT_LIVE_TEST=1 and ESL_REACT_LIVE_HOST to run live FreeSWITCH compatibility tests.');
        }

        if (getenv('ESL_REACT_LIVE_RUNNER_LIVENESS_TEST') !== '1') {
            self::markTestSkipped('Set ESL_REACT_LIVE_RUNNER_LIVENESS_TEST=1 to run the live runner liveness harness.');
        }

        $host = getenv('ESL_REACT_LIVE_HOST');
        if (!is_string($host) || $host === '') {
            self::markTestSkipped('ESL_REACT_LIVE_HOST is required for the live runner liveness harness.');
        }

        $handle = AsyncEslRuntime::runner()->run(new PreparedRuntimeInput(
            endpoint: $this->envString('ESL_REACT_LIVE_RUNNER_ENDPOINT', 'live-freeswitch'),
            runtimeConfig: RuntimeConfig::create(
                host: $host,
                port: $this->envInt('ESL_REACT_LIVE_PORT', 8021),
                password: $this->envString('ESL_REACT_LIVE_PASSWORD', 'ClueCon'),
                retryPolicy: RetryPolicy::disabled(),
                heartbeat: HeartbeatConfig::withInterval(
                    $this->envFloat('ESL_REACT_LIVE_LIVENESS_INTERVAL_SECONDS', 0.5),
                    $this->envFloat('ESL_REACT_LIVE_LIVENESS_TIMEOUT_SECONDS', 0.1),
                ),
                commandTimeout: CommandTimeoutConfig::withApiTimeout(
                    $this->envFloat('ESL_REACT_LIVE_LIVENESS_API_TIMEOUT', 5.0),
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

        $this->waitUntil(function () use ($handle, &$markers): bool {
            $snapshot = $handle->lifecycleSnapshot();

            return $snapshot->connectionState() === ConnectionState::Authenticated
                && $snapshot->sessionState() === SessionState::Active
                && $snapshot->isLive() === false
                && array_filter(
                    $markers,
                    static fn(array $marker): bool => $marker['connection'] === 'authenticated'
                        && $marker['session'] === 'active'
                        && $marker['live'] === false
                        && $marker['reconnecting'] === false
                        && $marker['draining'] === false
                        && $marker['stopped'] === false
                ) !== [];
        }, $this->envFloat('ESL_REACT_LIVE_LIVENESS_DEGRADE_TIMEOUT', 10.0));

        $degraded = $handle->lifecycleSnapshot();
        self::assertSame(ConnectionState::Authenticated, $degraded->connectionState());
        self::assertSame(SessionState::Active, $degraded->sessionState());
        self::assertFalse($degraded->isLive());
        self::assertFalse($degraded->isReconnecting());
        self::assertFalse($degraded->isDraining());
        self::assertFalse($degraded->isStopped());

        $this->waitUntil(function () use ($handle, &$markers): bool {
            $snapshot = $handle->lifecycleSnapshot();

            return $snapshot->connectionState() === ConnectionState::Authenticated
                && $snapshot->sessionState() === SessionState::Active
                && $snapshot->isLive()
                && count(array_filter(
                    $markers,
                    static fn(array $marker): bool => $marker['connection'] === 'authenticated'
                        && $marker['session'] === 'active'
                        && $marker['live'] === true
                        && $marker['reconnecting'] === false
                        && $marker['draining'] === false
                        && $marker['stopped'] === false
                )) >= 2;
        }, $this->envFloat('ESL_REACT_LIVE_LIVENESS_RECOVERY_TIMEOUT', 10.0));

        $recovered = $handle->lifecycleSnapshot();
        self::assertSame(ConnectionState::Authenticated, $recovered->connectionState());
        self::assertSame(SessionState::Active, $recovered->sessionState());
        self::assertTrue($recovered->isLive());
        self::assertFalse($recovered->isReconnecting());
        self::assertFalse($recovered->isDraining());
        self::assertFalse($recovered->isStopped());

        self::assertSame([], array_filter(
            $markers,
            static fn(array $marker): bool => $marker['reconnecting'] === true
                || $marker['connection'] === 'reconnecting'
                || $marker['draining'] === true
                || $marker['connection'] === 'draining'
        ), 'Liveness degradation without transport loss should not report reconnect or drain.');

        $this->await($handle->client()->disconnect(), 2.0);
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
