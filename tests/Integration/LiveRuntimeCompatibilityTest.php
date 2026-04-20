<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Integration;

use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Config\CommandTimeoutConfig;
use Apntalk\EslReact\Config\HeartbeatConfig;
use Apntalk\EslReact\Config\RetryPolicy;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Session\SessionState;
use Apntalk\EslReact\Tests\Support\AsyncTestCase;

final class LiveRuntimeCompatibilityTest extends AsyncTestCase
{
    public function testOptInLiveFreeswitchConnectApiAndCleanShutdown(): void
    {
        if (getenv('ESL_REACT_LIVE_TEST') !== '1') {
            self::markTestSkipped('Set ESL_REACT_LIVE_TEST=1 and ESL_REACT_LIVE_HOST to run the direct live FreeSWITCH harness.');
        }

        $host = getenv('ESL_REACT_LIVE_HOST');
        if (!is_string($host) || $host === '') {
            self::markTestSkipped('ESL_REACT_LIVE_HOST is required for the direct live FreeSWITCH harness.');
        }

        $port = $this->envInt('ESL_REACT_LIVE_PORT', 8021);
        $password = getenv('ESL_REACT_LIVE_PASSWORD');
        if (!is_string($password) || $password === '') {
            $password = 'ClueCon';
        }

        $command = getenv('ESL_REACT_LIVE_API_COMMAND');
        if (!is_string($command) || $command === '') {
            $command = 'status';
        }

        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(
                host: $host,
                port: $port,
                password: $password,
                retryPolicy: RetryPolicy::disabled(),
                heartbeat: HeartbeatConfig::disabled(),
                commandTimeout: CommandTimeoutConfig::withApiTimeout(5.0),
            ),
            $this->loop,
        );

        $this->await($client->connect(), 6.0);

        $connected = $client->health()->snapshot();
        self::assertSame(ConnectionState::Authenticated, $connected->connectionState);
        self::assertSame(SessionState::Active, $connected->sessionState);
        self::assertTrue($connected->isLive);

        $reply = $this->await($client->api($command), 6.0);
        self::assertNotSame('', trim($reply->body()));

        $this->await($client->disconnect(), 2.0);

        $closed = $client->health()->snapshot();
        self::assertSame(ConnectionState::Closed, $closed->connectionState);
        self::assertSame(SessionState::Disconnected, $closed->sessionState);
        self::assertFalse($closed->isLive);
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
