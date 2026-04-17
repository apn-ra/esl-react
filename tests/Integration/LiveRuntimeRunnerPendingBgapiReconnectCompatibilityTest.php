<?php declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Integration;

use Apntalk\EslCore\Contracts\ReplyInterface;
use Apntalk\EslCore\Events\BackgroundJobEvent;
use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Bgapi\BgapiJobHandle;
use Apntalk\EslReact\Config\CommandTimeoutConfig;
use Apntalk\EslReact\Config\HeartbeatConfig;
use Apntalk\EslReact\Config\RetryPolicy;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Exceptions\ConnectionException;
use Apntalk\EslReact\Runner\PreparedRuntimeInput;
use Apntalk\EslReact\Runner\RuntimeRunnerHandle;
use Apntalk\EslReact\Runner\RuntimeLifecycleSnapshot;
use Apntalk\EslReact\Runner\RuntimeRunnerState;
use Apntalk\EslReact\Runtime\RuntimeClient;
use Apntalk\EslReact\Session\SessionState;
use Apntalk\EslReact\Tests\Support\AsyncTestCase;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;

final class LiveRuntimeRunnerPendingBgapiReconnectCompatibilityTest extends AsyncTestCase
{
    private ?RuntimeRunnerHandle $currentHandle = null;

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
     *   reconnectAttempts: int,
     *   pendingBgapiJobCount: int
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
            'pendingBgapiJobCount' => $snapshot->health?->pendingBgapiJobCount ?? 0,
        ];
    }

    public function testOptInLiveRunnerRetainsPendingBgapiAcrossReconnectBoundary(): void
    {
        if (getenv('ESL_REACT_LIVE_TEST') !== '1') {
            self::markTestSkipped('Set ESL_REACT_LIVE_TEST=1 and ESL_REACT_LIVE_HOST to run live FreeSWITCH compatibility tests.');
        }

        if (getenv('ESL_REACT_LIVE_RUNNER_PENDING_BGAPI_RECONNECT_TEST') !== '1') {
            self::markTestSkipped('Set ESL_REACT_LIVE_RUNNER_PENDING_BGAPI_RECONNECT_TEST=1 to run the live pending-bgapi reconnect harness.');
        }

        $host = getenv('ESL_REACT_LIVE_HOST');
        if (!is_string($host) || $host === '') {
            self::markTestSkipped('ESL_REACT_LIVE_HOST is required for the live pending-bgapi reconnect harness.');
        }

        $bgapiCommand = $this->envString('ESL_REACT_LIVE_RUNNER_PENDING_BGAPI_COMMAND', 'msleep');
        $bgapiArgs = $this->envString('ESL_REACT_LIVE_RUNNER_PENDING_BGAPI_ARGS', '15000');
        $bgapiTimeout = $this->envFloat('ESL_REACT_LIVE_RUNNER_PENDING_BGAPI_TIMEOUT', 45.0);
        $disconnectTimeout = $this->envFloat('ESL_REACT_LIVE_RUNNER_PENDING_BGAPI_DISCONNECT_TIMEOUT', 20.0);
        $reconnectTimeout = $this->envFloat('ESL_REACT_LIVE_RUNNER_PENDING_BGAPI_RECONNECT_TIMEOUT', 30.0);
        $postDisruptSettleSeconds = $this->envFloat('ESL_REACT_LIVE_RUNNER_PENDING_BGAPI_DISRUPT_SETTLE_SECONDS', 1.0);
        $postRestoreSettleSeconds = $this->envFloat('ESL_REACT_LIVE_RUNNER_PENDING_BGAPI_RESTORE_SETTLE_SECONDS', 1.0);

        $handle = AsyncEslRuntime::runner()->run(new PreparedRuntimeInput(
            endpoint: $this->envString('ESL_REACT_LIVE_RUNNER_ENDPOINT', 'live-freeswitch'),
            runtimeConfig: RuntimeConfig::create(
                host: $host,
                port: $this->envInt('ESL_REACT_LIVE_PORT', 8021),
                password: $this->envString('ESL_REACT_LIVE_PASSWORD', 'ClueCon'),
                retryPolicy: RetryPolicy::withMaxAttempts(0, 0.5),
                heartbeat: HeartbeatConfig::disabled(),
                commandTimeout: CommandTimeoutConfig::default()->withBgapiOrphanTimeout($bgapiTimeout),
            ),
        ), $this->loop);
        $this->currentHandle = $handle;

        $markers = [];
        $handle->onLifecycleChange(function (RuntimeLifecycleSnapshot $snapshot) use (&$markers): void {
            $markers[] = $this->lifecycleMarker($snapshot);
        });

        $this->await($handle->startupPromise(), 8.0);
        $this->await($handle->client()->subscriptions()->subscribe('BACKGROUND_JOB'), 6.0);

        $live = $handle->lifecycleSnapshot();
        self::assertSame(RuntimeRunnerState::Running, $live->runnerState);
        self::assertSame(ConnectionState::Authenticated, $live->connectionState());
        self::assertSame(SessionState::Active, $live->sessionState());
        self::assertTrue($live->isLive());
        self::assertFalse($live->isReconnecting());
        self::assertFalse($live->isDraining());
        self::assertSame(['BACKGROUND_JOB'], $handle->client()->subscriptions()->activeEventNames());

        $job = $handle->client()->bgapi($bgapiCommand, $bgapiArgs);
        self::assertInstanceOf(BgapiJobHandle::class, $job);
        self::assertSame($bgapiCommand, $job->eslCommand());
        self::assertSame($bgapiArgs, $job->eslArgs());

        $this->waitUntil(
            fn (): bool => $job->jobUuid() !== ''
                && $handle->lifecycleSnapshot()->health?->pendingBgapiJobCount === 1,
            6.0,
        );

        $pending = $handle->lifecycleSnapshot();
        self::assertSame(1, $pending->health?->pendingBgapiJobCount);
        self::assertTrue($pending->isLive());
        self::assertFalse($pending->isReconnecting());
        self::assertFalse($pending->isDraining());
        self::assertFalse($pending->isStopped());

        $restored = false;

        try {
            $this->announce('Triggering reconnect fault while bgapi job remains pending.');
            $this->triggerReconnectFault();
            $this->runLoopFor($postDisruptSettleSeconds);

            $this->waitUntil(function () use (&$markers): bool {
                return array_filter(
                    $markers,
                    static fn (array $marker): bool => in_array($marker['connection'], ['reconnecting', 'disconnected'], true)
                        && $marker['session'] === 'disconnected'
                        && $marker['reconnecting'] === true
                        && $marker['draining'] === false
                        && $marker['pendingBgapiJobCount'] === 1
                ) !== [];
            }, $disconnectTimeout);

            self::assertNotEmpty(array_filter(
                $markers,
                static fn (array $marker): bool => $marker['connection'] === 'reconnecting'
                    && $marker['session'] === 'disconnected'
                    && $marker['reconnecting'] === true
                    && $marker['draining'] === false
                    && $marker['pendingBgapiJobCount'] === 1
            ));
            self::assertSame([], array_filter(
                $markers,
                static fn (array $marker): bool => $marker['connection'] === 'draining'
                    || $marker['draining'] === true
            ), 'Unexpected reconnect while a pending bgapi job survives should not be reported as drain.');

            $duringRecovery = $handle->lifecycleSnapshot();
            self::assertSame(RuntimeRunnerState::Running, $duringRecovery->runnerState);
            self::assertFalse($duringRecovery->isDraining());
            self::assertFalse($duringRecovery->isStopped());
            self::assertSame(1, $duringRecovery->health?->pendingBgapiJobCount);
            self::assertContains(
                $duringRecovery->connectionState(),
                [
                    ConnectionState::Reconnecting,
                    ConnectionState::Disconnected,
                    ConnectionState::Connecting,
                    ConnectionState::Connected,
                    ConnectionState::Authenticating,
                    ConnectionState::Authenticated,
                ],
            );

            $this->waitUntil(function () use ($handle): bool {
                $snapshot = $handle->lifecycleSnapshot();

                return $snapshot->connectionState() === ConnectionState::Authenticated
                    && $snapshot->sessionState() === SessionState::Active
                    && $snapshot->isLive()
                    && $snapshot->health?->pendingBgapiJobCount === 1
                    && $handle->client()->subscriptions()->activeEventNames() === ['BACKGROUND_JOB'];
            }, $reconnectTimeout);
            $restored = true;
            $this->runLoopFor($postRestoreSettleSeconds);

            $recovered = $handle->lifecycleSnapshot();
            self::assertSame(RuntimeRunnerState::Running, $recovered->runnerState);
            self::assertSame(ConnectionState::Authenticated, $recovered->connectionState());
            self::assertSame(SessionState::Active, $recovered->sessionState());
            self::assertTrue($recovered->isLive());
            self::assertFalse($recovered->isReconnecting());
            self::assertFalse($recovered->isDraining());
            self::assertFalse($recovered->isStopped());
            self::assertSame(1, $recovered->health?->pendingBgapiJobCount);
            self::assertSame(['BACKGROUND_JOB'], $handle->client()->subscriptions()->activeEventNames());

            $completion = $this->await($job->promise(), $bgapiTimeout);
            self::assertInstanceOf(BackgroundJobEvent::class, $completion);
            self::assertSame($job->jobUuid(), $completion->jobUuid());
            self::assertNotSame('', trim($completion->result()), 'Expected a non-empty BACKGROUND_JOB completion result after reconnect recovery.');

            $afterCompletion = $handle->lifecycleSnapshot();
            self::assertSame(ConnectionState::Authenticated, $afterCompletion->connectionState());
            self::assertSame(SessionState::Active, $afterCompletion->sessionState());
            self::assertTrue($afterCompletion->isLive());
            self::assertFalse($afterCompletion->isReconnecting());
            self::assertFalse($afterCompletion->isDraining());
            self::assertFalse($afterCompletion->isStopped());
            self::assertSame(0, $afterCompletion->health?->pendingBgapiJobCount);

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
            if (!$restored) {
                $this->attemptOptionalFaultRestore();
            }
        }

        $this->await($handle->client()->disconnect(), 2.0);

        $this->waitUntil(function () use ($handle): bool {
            return $handle->lifecycleSnapshot()->connectionState() === ConnectionState::Closed;
        }, 2.0);

        $closed = $handle->lifecycleSnapshot();
        self::assertSame(ConnectionState::Closed, $closed->connectionState());
        self::assertSame(SessionState::Disconnected, $closed->sessionState());
        self::assertFalse($closed->isLive());
        self::assertFalse($closed->isReconnecting());
        self::assertFalse($closed->isDraining());
        self::assertTrue($closed->isStopped());
    }

    private function triggerReconnectFault(): void
    {
        $customDisruptCommand = getenv('ESL_REACT_LIVE_RUNNER_PENDING_BGAPI_DISRUPT_COMMAND');
        if (is_string($customDisruptCommand) && trim($customDisruptCommand) !== '') {
            $this->runAutomationCommand(
                'ESL_REACT_LIVE_RUNNER_PENDING_BGAPI_DISRUPT_COMMAND',
                trim($customDisruptCommand),
            );

            return;
        }

        $faultMode = $this->envString('ESL_REACT_LIVE_RUNNER_PENDING_BGAPI_FAULT_MODE', 'client_transport_close');
        if ($faultMode === 'client_transport_close') {
            $this->forceCloseRunnerTransport();

            return;
        }

        if ($faultMode === 'reload_mod_event_socket') {
            $this->issueExternalApiCommand(
                $this->envString('ESL_REACT_LIVE_RUNNER_PENDING_BGAPI_FAULT_API', 'reload mod_event_socket'),
                $this->envFloat('ESL_REACT_LIVE_RUNNER_PENDING_BGAPI_FAULT_API_TIMEOUT', 6.0),
            );

            return;
        }

        self::markTestSkipped(sprintf(
            'Unsupported ESL_REACT_LIVE_RUNNER_PENDING_BGAPI_FAULT_MODE "%s".',
            $faultMode,
        ));
    }

    private function attemptOptionalFaultRestore(): void
    {
        $restoreCommand = getenv('ESL_REACT_LIVE_RUNNER_PENDING_BGAPI_RESTORE_COMMAND');
        if (!is_string($restoreCommand) || trim($restoreCommand) === '') {
            return;
        }

        $this->announce('Attempting optional pending-bgapi reconnect restore command during cleanup.');
        $this->runAutomationCommand(
            'ESL_REACT_LIVE_RUNNER_PENDING_BGAPI_RESTORE_COMMAND',
            trim($restoreCommand),
        );
    }

    private function issueExternalApiCommand(string $command, float $timeoutSeconds): ReplyInterface
    {
        $host = getenv('ESL_REACT_LIVE_HOST');
        if (!is_string($host) || $host === '') {
            self::markTestSkipped('ESL_REACT_LIVE_HOST is required for the live pending-bgapi reconnect fault helper.');
        }

        $this->announce(sprintf('Issuing external ESL api fault command: %s', $command));

        $controlClient = AsyncEslRuntime::make(
            RuntimeConfig::create(
                host: $host,
                port: $this->envInt('ESL_REACT_LIVE_PORT', 8021),
                password: $this->envString('ESL_REACT_LIVE_PASSWORD', 'ClueCon'),
                retryPolicy: RetryPolicy::disabled(),
                heartbeat: HeartbeatConfig::disabled(),
                commandTimeout: CommandTimeoutConfig::withApiTimeout($timeoutSeconds),
            ),
            $this->loop,
        );

        $reply = $this->await($this->connectAndRunExternalApi($controlClient, $command), $timeoutSeconds + 2.0);

        try {
            $this->await($controlClient->disconnect(), 2.0);
        } catch (\Throwable) {
            // The listener reload may close this temporary control session.
        }

        return $reply;
    }

    private function forceCloseRunnerTransport(): void
    {
        $runtimeClient = $this->runtimeClient();
        $reflection = new \ReflectionObject($runtimeClient);
        $property = $reflection->getProperty('connection');
        $property->setAccessible(true);
        $connection = $property->getValue($runtimeClient);

        if (!$connection instanceof ConnectionInterface) {
            self::fail('Expected a live runner transport connection before fault injection.');
        }

        $this->announce('Force-closing runner transport connection to trigger unexpected reconnect.');
        $connection->close();
    }

    private function runtimeClient(): RuntimeClient
    {
        $handle = $this->currentHandle;
        if (!$handle instanceof RuntimeRunnerHandle) {
            self::fail('Runner handle is not available for transport fault injection.');
        }

        $client = $handle->client();
        if (!$client instanceof RuntimeClient) {
            self::markTestSkipped('Runner client is not the expected RuntimeClient implementation.');
        }

        return $client;
    }

    /**
     * @return PromiseInterface<ReplyInterface>
     */
    private function connectAndRunExternalApi(\Apntalk\EslReact\Contracts\AsyncEslClientInterface $client, string $command): PromiseInterface
    {
        return $client->connect()->then(
            fn (): PromiseInterface => $client->api($command),
        );
    }

    private function announce(string $message): void
    {
        fwrite(STDOUT, sprintf("[live runner pending-bgapi reconnect] %s\n", $message));
        fflush(STDOUT);
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
