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

final class LiveRuntimeManualReconnectApiRecoveryTest extends AsyncTestCase
{
    public function testOptInManualLiveReconnectAndPostReconnectApiRecovery(): void
    {
        if (getenv('ESL_REACT_LIVE_TEST') !== '1') {
            self::markTestSkipped('Set ESL_REACT_LIVE_TEST=1 and ESL_REACT_LIVE_HOST to run live FreeSWITCH compatibility tests.');
        }

        if (getenv('ESL_REACT_LIVE_MANUAL_RECONNECT_API_TEST') !== '1') {
            self::markTestSkipped('Set ESL_REACT_LIVE_MANUAL_RECONNECT_API_TEST=1 to run the manual live reconnect + api harness.');
        }

        $host = getenv('ESL_REACT_LIVE_HOST');
        if (!is_string($host) || $host === '') {
            self::markTestSkipped('ESL_REACT_LIVE_HOST is required for the manual live reconnect + api harness.');
        }

        $eventName = $this->envString('ESL_REACT_LIVE_EVENT_NAME', 'HEARTBEAT');
        $disconnectTimeout = $this->envFloat('ESL_REACT_LIVE_MANUAL_DISCONNECT_TIMEOUT', 45.0);
        $reconnectTimeout = $this->envFloat('ESL_REACT_LIVE_MANUAL_RECONNECT_TIMEOUT', 60.0);
        $postReconnectEventTimeout = $this->envFloat(
            'ESL_REACT_LIVE_POST_RECONNECT_EVENT_TIMEOUT',
            $this->envFloat('ESL_REACT_LIVE_EVENT_TIMEOUT', 25.0),
        );
        $postReconnectApiCommand = $this->envString('ESL_REACT_LIVE_POST_RECONNECT_API_COMMAND', 'status');
        $postReconnectApiTimeout = $this->envFloat('ESL_REACT_LIVE_POST_RECONNECT_API_TIMEOUT', 6.0);
        $triggerApiCommand = getenv('ESL_REACT_LIVE_EVENT_TRIGGER_API');
        if (!is_string($triggerApiCommand) || $triggerApiCommand === '') {
            $triggerApiCommand = null;
        }

        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(
                host: $host,
                port: $this->envInt('ESL_REACT_LIVE_PORT', 8021),
                password: $this->envString('ESL_REACT_LIVE_PASSWORD', 'ClueCon'),
                retryPolicy: RetryPolicy::withMaxAttempts(0, 0.5),
                heartbeat: HeartbeatConfig::withInterval(6.0, 1.0),
                commandTimeout: CommandTimeoutConfig::withApiTimeout(5.0),
            ),
            $this->loop,
        );

        $phase = 'pre_disconnect';
        $postReconnectEvent = new Deferred();
        $client->events()->onRawEnvelope(function (EventEnvelope $envelope) use (&$phase, $eventName, $postReconnectEvent): void {
            if ($phase !== 'await_post_reconnect_event') {
                return;
            }

            if ($envelope->event()->eventName() !== $eventName) {
                return;
            }

            $postReconnectEvent->resolve($envelope);
        });

        $this->await($client->connect(), 8.0);
        $this->await($client->subscriptions()->subscribe($eventName), 6.0);

        $live = $client->health()->snapshot();
        self::assertSame(ConnectionState::Authenticated, $live->connectionState);
        self::assertSame(SessionState::Active, $live->sessionState);
        self::assertTrue($live->isLive);
        self::assertSame([$eventName], $client->subscriptions()->activeEventNames());

        $this->announce(sprintf(
            'Connected and subscribed to %s. Manually disrupt the ESL network/connectivity path now. Waiting up to %.1f seconds for disconnect to be observed.',
            $eventName,
            $disconnectTimeout,
        ));

        $this->waitUntil(function () use ($client): bool {
            $snapshot = $client->health()->snapshot();

            return $snapshot->connectionState === ConnectionState::Reconnecting
                || $snapshot->connectionState === ConnectionState::Disconnected
                || $snapshot->sessionState === SessionState::Disconnected;
        }, $disconnectTimeout);

        $afterDisconnect = $client->health()->snapshot();
        self::assertFalse($afterDisconnect->isLive);
        self::assertSame(SessionState::Disconnected, $afterDisconnect->sessionState);
        self::assertContains($afterDisconnect->connectionState, [ConnectionState::Reconnecting, ConnectionState::Disconnected]);

        $phase = 'await_reconnect';
        $this->announce(sprintf(
            'Disconnect observed. Restore connectivity now if not already restored. Waiting up to %.1f seconds for reconnect and desired-state recovery.',
            $reconnectTimeout,
        ));

        $this->waitUntil(function () use ($client, $eventName): bool {
            $snapshot = $client->health()->snapshot();

            return $snapshot->connectionState === ConnectionState::Authenticated
                && $snapshot->sessionState === SessionState::Active
                && $snapshot->isLive
                && $client->subscriptions()->activeEventNames() === [$eventName];
        }, $reconnectTimeout);

        $recovered = $client->health()->snapshot();
        self::assertSame(ConnectionState::Authenticated, $recovered->connectionState);
        self::assertSame(SessionState::Active, $recovered->sessionState);
        self::assertTrue($recovered->isLive);
        self::assertSame([$eventName], $client->subscriptions()->activeEventNames());

        $phase = 'await_post_reconnect_event';
        $this->announce(sprintf(
            'Reconnect observed. Waiting up to %.1f seconds for a post-reconnect %s event.',
            $postReconnectEventTimeout,
            $eventName,
        ));

        if ($triggerApiCommand !== null) {
            $this->announce(sprintf('Issuing configured low-risk post-reconnect trigger command: %s', $triggerApiCommand));
            $this->await($client->api($triggerApiCommand), 6.0);
        }

        $envelope = $this->await($postReconnectEvent->promise(), $postReconnectEventTimeout);

        self::assertInstanceOf(EventEnvelope::class, $envelope);
        self::assertSame($eventName, $envelope->event()->eventName());

        $metadata = $envelope->metadata();
        self::assertTrue(
            $metadata->protocolSequence() !== null
            || $envelope->event()->coreUuid() !== null
            || $envelope->event()->uniqueId() !== null,
            'Expected the post-reconnect live event to include at least one identifying protocol field.',
        );

        $this->announce(sprintf(
            "Post-reconnect event observed. Running api('%s') now.",
            $postReconnectApiCommand,
        ));

        $reply = $this->await($client->api($postReconnectApiCommand), $postReconnectApiTimeout);
        $body = trim($reply->body());

        self::assertNotSame('', $body, 'Expected a non-empty post-reconnect api() reply.');
        if (strtolower($postReconnectApiCommand) === 'status') {
            self::assertTrue(
                str_contains($body, 'FreeSWITCH') || str_contains(strtolower($body), 'ready'),
                "Expected status reply to include a stable FreeSWITCH readiness marker after reconnect.",
            );
        }

        $this->await($client->disconnect(), 2.0);

        $closed = $client->health()->snapshot();
        self::assertSame(ConnectionState::Closed, $closed->connectionState);
        self::assertSame(SessionState::Disconnected, $closed->sessionState);
        self::assertFalse($closed->isLive);
    }

    private function announce(string $message): void
    {
        fwrite(STDOUT, sprintf("[manual reconnect+api] %s\n", $message));
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
}
