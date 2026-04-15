<?php declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Integration;

use Apntalk\EslCore\Correlation\EventEnvelope;
use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Config\CommandTimeoutConfig;
use Apntalk\EslReact\Config\HeartbeatConfig;
use Apntalk\EslReact\Config\RetryPolicy;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Session\SessionState;
use Apntalk\EslReact\Tests\Support\AsyncTestCase;
use React\Promise\Deferred;

final class LiveRuntimeEventCompatibilityTest extends AsyncTestCase
{
    public function testOptInLiveFreeswitchEventReceiptAndCleanShutdown(): void
    {
        if (getenv('ESL_REACT_LIVE_TEST') !== '1') {
            self::markTestSkipped('Set ESL_REACT_LIVE_TEST=1 and ESL_REACT_LIVE_HOST to run live FreeSWITCH compatibility tests.');
        }

        if (getenv('ESL_REACT_LIVE_EVENT_TEST') !== '1') {
            self::markTestSkipped('Set ESL_REACT_LIVE_EVENT_TEST=1 to run the direct live event receipt harness.');
        }

        $host = getenv('ESL_REACT_LIVE_HOST');
        if (!is_string($host) || $host === '') {
            self::markTestSkipped('ESL_REACT_LIVE_HOST is required for the direct live event receipt harness.');
        }

        $eventName = getenv('ESL_REACT_LIVE_EVENT_NAME');
        if (!is_string($eventName) || $eventName === '') {
            $eventName = 'HEARTBEAT';
        }

        $triggerApiCommand = getenv('ESL_REACT_LIVE_EVENT_TRIGGER_API');
        if (!is_string($triggerApiCommand) || $triggerApiCommand === '') {
            $triggerApiCommand = null;
        }

        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(
                host: $host,
                port: $this->envInt('ESL_REACT_LIVE_PORT', 8021),
                password: $this->envString('ESL_REACT_LIVE_PASSWORD', 'ClueCon'),
                retryPolicy: RetryPolicy::disabled(),
                heartbeat: HeartbeatConfig::disabled(),
                commandTimeout: CommandTimeoutConfig::withApiTimeout(5.0),
            ),
            $this->loop,
        );

        $this->await($client->connect(), 6.0);

        $eventDeferred = new Deferred();
        $client->events()->onRawEnvelope(function (EventEnvelope $envelope) use ($eventName, $eventDeferred): void {
            if ($envelope->event()->eventName() !== $eventName) {
                return;
            }

            $eventDeferred->resolve($envelope);
        });

        $this->await($client->subscriptions()->subscribe($eventName), 6.0);

        if ($triggerApiCommand !== null) {
            $this->await($client->api($triggerApiCommand), 6.0);
        }

        $envelope = $this->await($eventDeferred->promise(), $this->envFloat('ESL_REACT_LIVE_EVENT_TIMEOUT', 25.0));

        self::assertInstanceOf(EventEnvelope::class, $envelope);
        self::assertSame($eventName, $envelope->event()->eventName());

        $metadata = $envelope->metadata();
        self::assertTrue(
            $metadata->protocolSequence() !== null
            || $envelope->event()->coreUuid() !== null
            || $envelope->event()->uniqueId() !== null,
            'Expected the live event to include at least one identifying protocol field.',
        );

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
