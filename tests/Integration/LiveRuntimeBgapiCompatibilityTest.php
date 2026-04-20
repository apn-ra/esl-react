<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Integration;

use Apntalk\EslCore\Events\BackgroundJobEvent;
use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Bgapi\BgapiJobHandle;
use Apntalk\EslReact\Config\CommandTimeoutConfig;
use Apntalk\EslReact\Config\HeartbeatConfig;
use Apntalk\EslReact\Config\RetryPolicy;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Session\SessionState;
use Apntalk\EslReact\Tests\Support\AsyncTestCase;

final class LiveRuntimeBgapiCompatibilityTest extends AsyncTestCase
{
    public function testOptInLiveFreeswitchBgapiHappyPathAndCleanShutdown(): void
    {
        if (getenv('ESL_REACT_LIVE_TEST') !== '1') {
            self::markTestSkipped('Set ESL_REACT_LIVE_TEST=1 and ESL_REACT_LIVE_HOST to run live FreeSWITCH compatibility tests.');
        }

        if (getenv('ESL_REACT_LIVE_BGAPI_TEST') !== '1') {
            self::markTestSkipped('Set ESL_REACT_LIVE_BGAPI_TEST=1 to run the direct live bgapi harness.');
        }

        $host = getenv('ESL_REACT_LIVE_HOST');
        if (!is_string($host) || $host === '') {
            self::markTestSkipped('ESL_REACT_LIVE_HOST is required for the direct live bgapi harness.');
        }

        $command = getenv('ESL_REACT_LIVE_BGAPI_COMMAND');
        if (!is_string($command) || $command === '') {
            $command = 'status';
        }

        $args = getenv('ESL_REACT_LIVE_BGAPI_ARGS');
        if (!is_string($args)) {
            $args = '';
        }

        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(
                host: $host,
                port: $this->envInt('ESL_REACT_LIVE_PORT', 8021),
                password: $this->envString('ESL_REACT_LIVE_PASSWORD', 'ClueCon'),
                retryPolicy: RetryPolicy::disabled(),
                heartbeat: HeartbeatConfig::disabled(),
                commandTimeout: CommandTimeoutConfig::default()->withBgapiOrphanTimeout(
                    $this->envFloat('ESL_REACT_LIVE_BGAPI_TIMEOUT', 20.0),
                ),
            ),
            $this->loop,
        );

        $this->await($client->connect(), 6.0);

        $connected = $client->health()->snapshot();
        self::assertSame(ConnectionState::Authenticated, $connected->connectionState);
        self::assertSame(SessionState::Active, $connected->sessionState);
        self::assertTrue($connected->isLive);

        $this->await($client->subscriptions()->subscribe('BACKGROUND_JOB'), 6.0);

        $handle = $client->bgapi($command, $args);

        self::assertInstanceOf(BgapiJobHandle::class, $handle);
        self::assertSame($command, $handle->eslCommand());
        self::assertSame($args, $handle->eslArgs());

        $this->waitUntil(
            fn(): bool => $handle->jobUuid() !== '',
            6.0,
        );

        $event = $this->await($handle->promise(), $this->envFloat('ESL_REACT_LIVE_BGAPI_TIMEOUT', 20.0));

        self::assertInstanceOf(BackgroundJobEvent::class, $event);
        self::assertSame($handle->jobUuid(), $event->jobUuid());
        self::assertNotSame('', trim($event->result()));

        $this->await($client->disconnect(), 2.0);

        $closed = $client->health()->snapshot();
        self::assertSame(ConnectionState::Closed, $closed->connectionState);
        self::assertSame(SessionState::Disconnected, $closed->sessionState);
        self::assertFalse($closed->isLive);
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
