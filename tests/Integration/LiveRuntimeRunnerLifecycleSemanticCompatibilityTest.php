<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Integration;

use Apntalk\EslCore\Correlation\EventEnvelope;
use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Config\CommandTimeoutConfig;
use Apntalk\EslReact\Config\HeartbeatConfig;
use Apntalk\EslReact\Config\RetryPolicy;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Runner\PreparedRuntimeInput;
use Apntalk\EslReact\Runner\RuntimeFeedbackSnapshot;
use Apntalk\EslReact\Runner\RuntimeLifecycleSnapshot;
use Apntalk\EslReact\Runner\RuntimeRunnerState;
use Apntalk\EslReact\Session\SessionState;
use Apntalk\EslReact\Tests\Support\AsyncTestCase;
use React\Promise\Deferred;

final class LiveRuntimeRunnerLifecycleSemanticCompatibilityTest extends AsyncTestCase
{
    public function testOptInLiveRunnerExportsSupportedLifecycleSemanticObservations(): void
    {
        if (getenv('ESL_REACT_LIVE_TEST') !== '1') {
            self::markTestSkipped('Set ESL_REACT_LIVE_TEST=1 and ESL_REACT_LIVE_HOST to run live FreeSWITCH compatibility tests.');
        }

        if (getenv('ESL_REACT_LIVE_RUNNER_SEMANTIC_TEST') !== '1') {
            self::markTestSkipped('Set ESL_REACT_LIVE_RUNNER_SEMANTIC_TEST=1 to run the live runner lifecycle-semantic harness.');
        }

        $host = getenv('ESL_REACT_LIVE_HOST');
        if (!is_string($host) || $host === '') {
            self::markTestSkipped('ESL_REACT_LIVE_HOST is required for the live runner lifecycle-semantic harness.');
        }

        $eventName = $this->requiredEnv('ESL_REACT_LIVE_RUNNER_SEMANTIC_EVENT_NAME');
        $expectation = $this->semanticExpectation($eventName);
        $triggerApiCommand = getenv('ESL_REACT_LIVE_RUNNER_SEMANTIC_TRIGGER_API');
        if (!is_string($triggerApiCommand) || trim($triggerApiCommand) === '') {
            $triggerApiCommand = null;
        }

        $handle = AsyncEslRuntime::runner()->run(new PreparedRuntimeInput(
            endpoint: $this->envString('ESL_REACT_LIVE_RUNNER_ENDPOINT', 'live-freeswitch'),
            runtimeConfig: RuntimeConfig::create(
                host: $host,
                port: $this->envInt('ESL_REACT_LIVE_PORT', 8021),
                password: $this->envString('ESL_REACT_LIVE_PASSWORD', 'ClueCon'),
                retryPolicy: RetryPolicy::disabled(),
                heartbeat: HeartbeatConfig::disabled(),
                commandTimeout: CommandTimeoutConfig::withApiTimeout(6.0),
            ),
        ), $this->loop);

        $this->await($handle->startupPromise(), 8.0);
        $this->assertLiveLifecycle($handle->lifecycleSnapshot());
        self::assertSame([], $handle->feedbackSnapshot()->recentLifecycleSemantics);
        self::assertSame([], $handle->feedbackSnapshot()->recentTerminalPublications);

        $eventDeferred = new Deferred();
        $handle->client()->events()->onRawEnvelope(function (EventEnvelope $envelope) use ($eventName, $eventDeferred): void {
            if ($envelope->event()->eventName() !== $eventName) {
                return;
            }

            $eventDeferred->resolve($envelope);
        });

        $this->await($handle->client()->subscriptions()->subscribe($eventName), 6.0);
        $this->assertLiveLifecycle($handle->lifecycleSnapshot());

        if ($triggerApiCommand !== null) {
            $this->await($handle->client()->api($triggerApiCommand), 6.0);
        }

        $event = $this->await(
            $eventDeferred->promise(),
            $this->envFloat('ESL_REACT_LIVE_RUNNER_SEMANTIC_TIMEOUT', 30.0),
        );
        self::assertInstanceOf(EventEnvelope::class, $event);
        self::assertSame($eventName, $event->event()->eventName());

        $expectedTransition = $expectation['transition'];
        $expectedSubjectId = $event->event()->uniqueId();

        $this->waitUntil(function () use ($handle, $expectedTransition): bool {
            foreach ($handle->feedbackSnapshot()->recentLifecycleSemantics as $snapshot) {
                if ($snapshot->observation->transition()->value === $expectedTransition) {
                    return true;
                }
            }

            return false;
        }, 3.0);

        $feedback = $handle->feedbackSnapshot();
        $semantic = $this->findSemanticObservation($feedback, $expectedTransition);
        self::assertNotNull($semantic);
        self::assertSame($expectedTransition, $semantic->observation->transition()->value);
        self::assertNotNull($semantic->observation->toArray()['ordering_identity']);
        if (is_string($expectedSubjectId) && $expectedSubjectId !== '') {
            self::assertSame($expectedSubjectId, $semantic->observation->subjectId());
        }

        $status = $handle->statusSnapshot();
        self::assertSame('active', $status->phase->value);
        self::assertNotSame([], $status->recentLifecycleSemantics);
        self::assertSame(
            $expectedTransition,
            $status->toArray()['recent_lifecycle_semantics'][0]['observation']['transition'],
        );

        $expectedTerminalCause = $expectation['terminal_cause'];
        if ($expectedTerminalCause !== null) {
            $this->waitUntil(function () use ($handle, $expectedTerminalCause): bool {
                foreach ($handle->feedbackSnapshot()->recentTerminalPublications as $snapshot) {
                    if ($snapshot->publication->terminalCause()->value === $expectedTerminalCause) {
                        return true;
                    }
                }

                return false;
            }, 3.0);

            $terminal = $this->findTerminalPublication($handle->feedbackSnapshot(), $expectedTerminalCause);
            self::assertNotNull($terminal);
            self::assertSame($expectedTerminalCause, $terminal->publication->terminalCause()->value);
            self::assertSame($expectation['finality'], $terminal->publication->finality()->value);
        }

        $this->await($handle->client()->disconnect(), 2.0);
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

    /**
     * @return array{transition: string, terminal_cause: ?string, finality: ?string}
     */
    private function semanticExpectation(string $eventName): array
    {
        return match ($eventName) {
            'CHANNEL_BRIDGE' => ['transition' => 'bridge', 'terminal_cause' => null, 'finality' => null],
            'CHANNEL_TRANSFER' => ['transition' => 'transfer', 'terminal_cause' => null, 'finality' => null],
            'CHANNEL_HOLD' => ['transition' => 'hold', 'terminal_cause' => null, 'finality' => null],
            'CHANNEL_UNHOLD',
            'CHANNEL_RESUME' => ['transition' => 'resume', 'terminal_cause' => null, 'finality' => null],
            'CHANNEL_HANGUP_COMPLETE' => ['transition' => 'terminal', 'terminal_cause' => 'hangup', 'finality' => 'final'],
            'CHANNEL_DESTROY' => ['transition' => 'terminal', 'terminal_cause' => 'completed', 'finality' => 'provisional-final'],
            default => self::markTestSkipped(sprintf(
                'ESL_REACT_LIVE_RUNNER_SEMANTIC_EVENT_NAME must be one of CHANNEL_BRIDGE, CHANNEL_TRANSFER, CHANNEL_HOLD, CHANNEL_UNHOLD, CHANNEL_RESUME, CHANNEL_HANGUP_COMPLETE, or CHANNEL_DESTROY. Got "%s".',
                $eventName,
            )),
        };
    }

    private function findSemanticObservation(RuntimeFeedbackSnapshot $feedback, string $transition): ?\Apntalk\EslReact\Runner\RuntimeLifecycleSemanticSnapshot
    {
        for ($index = count($feedback->recentLifecycleSemantics) - 1; $index >= 0; --$index) {
            $snapshot = $feedback->recentLifecycleSemantics[$index];
            if ($snapshot->observation->transition()->value === $transition) {
                return $snapshot;
            }
        }

        return null;
    }

    private function findTerminalPublication(RuntimeFeedbackSnapshot $feedback, string $cause): ?\Apntalk\EslReact\Runner\RuntimeTerminalPublicationSnapshot
    {
        for ($index = count($feedback->recentTerminalPublications) - 1; $index >= 0; --$index) {
            $snapshot = $feedback->recentTerminalPublications[$index];
            if ($snapshot->publication->terminalCause()->value === $cause) {
                return $snapshot;
            }
        }

        return null;
    }

    private function requiredEnv(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || trim($value) === '') {
            self::markTestSkipped(sprintf('%s is required for the live runner lifecycle-semantic harness.', $name));
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
}
