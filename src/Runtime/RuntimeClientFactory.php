<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Runtime;

use Apntalk\EslCore\Contracts\CommandInterface;
use Apntalk\EslCore\Contracts\InboundPipelineInterface;
use Apntalk\EslCore\Contracts\ReplayCaptureSinkInterface;
use Apntalk\EslCore\Correlation\ConnectionSessionId;
use Apntalk\EslCore\Correlation\CorrelationContext;
use Apntalk\EslCore\Events\EventFactory;
use Apntalk\EslCore\Inbound\InboundPipeline;
use Apntalk\EslCore\Serialization\CommandSerializer;
use Apntalk\EslReact\Bgapi\BgapiDispatcher;
use Apntalk\EslReact\Bgapi\BgapiJobTracker;
use Apntalk\EslReact\CommandBus\AsyncCommandBus;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Contracts\AsyncEslClientInterface;
use Apntalk\EslReact\Events\EventStream;
use Apntalk\EslReact\Health\RuntimeHealthReporter;
use Apntalk\EslReact\Heartbeat\HeartbeatMonitor;
use Apntalk\EslReact\Heartbeat\IdleTimer;
use Apntalk\EslReact\Protocol\FrameWriter;
use Apntalk\EslReact\Protocol\InboundMessagePump;
use Apntalk\EslReact\Protocol\OutboundMessageDispatcher;
use Apntalk\EslReact\Replay\RuntimeReplayCapture;
use Apntalk\EslReact\Runner\RuntimeSessionContext;
use Apntalk\EslReact\Subscription\ActiveSubscriptionSet;
use Apntalk\EslReact\Subscription\FilterManager;
use Apntalk\EslReact\Subscription\SubscriptionManager;
use Apntalk\EslReact\Supervisor\ReconnectScheduler;
use LogicException;
use React\EventLoop\LoopInterface;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;
use Throwable;

/**
 * @internal Runtime graph factory; not part of the stable public API.
 */
final class RuntimeClientFactory
{
    public static function make(
        RuntimeConfig $config,
        LoopInterface $loop,
        ?ConnectorInterface $connector = null,
        ?string $connectionUri = null,
        ?RuntimeSessionContext $sessionContext = null,
        ?bool $replayCaptureEnabled = null,
        ?array $replayCaptureSinks = null,
        ?InboundPipelineInterface $inboundPipeline = null,
    ): AsyncEslClientInterface {
        /** @var RuntimeClient|null $client */
        $client = null;

        $correlation = new CorrelationContext(ConnectionSessionId::generate());
        $eventStream = new EventStream(new EventFactory(), $correlation);
        /** @var list<ReplayCaptureSinkInterface> $resolvedReplayCaptureSinks */
        $resolvedReplayCaptureSinks = $replayCaptureSinks ?? $config->replayCaptureSinks;
        $replay = new RuntimeReplayCapture(
            correlation: $correlation,
            sinks: $resolvedReplayCaptureSinks,
            enabled: $replayCaptureEnabled ?? $config->replayCaptureEnabled,
            runtimeMetadataProvider: static function () use (&$client, $sessionContext): array {
                if (!$client instanceof RuntimeClient) {
                    return $sessionContext?->replayMetadata() ?? [];
                }

                return [
                    ...($sessionContext?->replayMetadata() ?? []),
                    'runtime-connection-state' => $client->connectionState()->value,
                    'runtime-session-state' => $client->sessionState()->value,
                    'runtime-liveness-state' => $client->livenessState()->name,
                    'runtime-reconnect-attempts' => (string) $client->reconnectAttempts(),
                    'runtime-connection-generation' => (string) $client->connectionGeneration(),
                    'runtime-draining' => $client->isDraining() ? 'true' : 'false',
                    'runtime-overloaded' => $client->isOverloaded() ? 'true' : 'false',
                ];
            },
        );
        $eventStream->onRawEnvelope(static function ($envelope) use ($replay): void {
            $replay->captureEventEnvelope($envelope);
        });

        $outbound = new OutboundMessageDispatcher(new FrameWriter(new CommandSerializer()));
        $commandBus = new AsyncCommandBus(
            sendFn: static function ($command) use ($outbound): void {
                $outbound->dispatch($command);
            },
            loop: $loop,
            maxQueued: $config->backpressure->maxInflightCommands,
            onReplyCorrelationCompromised: static function (Throwable $reason) use (&$client): void {
                if (!$client instanceof RuntimeClient) {
                    return;
                }

                $client->handleApiReplyTimeoutAmbiguity($reason);
            },
        );
        $bgapiTracker = new BgapiJobTracker($loop, $config->commandTimeout->bgapiOrphanTimeoutSeconds);
        $bgapi = new BgapiDispatcher(
            tracker: $bgapiTracker,
            sendCommandReply: static function (CommandInterface $command, string $description, float $timeoutSeconds) use ($commandBus) {
                return $commandBus->dispatch($command, $description, $timeoutSeconds);
            },
            ackTimeoutSeconds: $config->commandTimeout->bgapiAckTimeoutSeconds,
            replayCapture: $replay,
        );

        $idleTimer = new IdleTimer();
        $heartbeat = new HeartbeatMonitor($config->heartbeat, $idleTimer, $loop);
        $activeSubscriptions = new ActiveSubscriptionSet();
        if ($config->subscriptions->subscribeAll) {
            $activeSubscriptions->subscribeAll();
        } elseif ($config->subscriptions->initialEventNames !== []) {
            $activeSubscriptions->subscribe(...$config->subscriptions->initialEventNames);
        }

        $filters = new FilterManager();
        foreach ($config->subscriptions->initialFilters as $filter) {
            $filters->addFilter($filter['headerName'], $filter['headerValue']);
        }

        $subscriptions = new SubscriptionManager(
            activeSubscriptions: $activeSubscriptions,
            filters: $filters,
            dispatchCommand: static function (CommandInterface $command, string $description, float $timeoutSeconds) use ($commandBus) {
                return $commandBus->dispatch($command, $description, $timeoutSeconds);
            },
            timeoutSeconds: $config->commandTimeout->subscriptionTimeoutSeconds,
            assertCanMutateLiveSession: static function () use (&$client): void {
                if (!$client instanceof RuntimeClient) {
                    throw new LogicException('Runtime client not initialized');
                }

                $client->assertCanAcceptSessionMutation();
            },
            replayCapture: $replay,
        );

        $client = new RuntimeClient(
            config: $config,
            loop: $loop,
            connector: $connector ?? new Connector([], $loop),
            connectionUri: $connectionUri ?? $config->connectionUri(),
            inboundPump: new InboundMessagePump($inboundPipeline ?? InboundPipeline::withDefaults()),
            outbound: $outbound,
            commands: $commandBus,
            bgapi: $bgapi,
            bgapiTracker: $bgapiTracker,
            events: $eventStream,
            subscriptions: $subscriptions,
            reconnects: new ReconnectScheduler($config->retryPolicy, $loop),
            heartbeat: $heartbeat,
            replay: $replay,
        );

        $health = new RuntimeHealthReporter(
            connectionStateProvider: static fn() => $client->connectionState(),
            sessionStateProvider: static fn() => $client->sessionState(),
            livenessProvider: static fn() => $client->livenessState(),
            inflightCountProvider: static fn() => $client->inflightCommandCount(),
            bgapiPendingCountProvider: static fn() => $client->pendingBgapiCount(),
            totalInflightCountProvider: static fn() => $client->totalInflightWorkCount(),
            overloadedProvider: static fn() => $client->isOverloaded(),
            subscriptionsProvider: static fn() => $subscriptions->activeEventNames(),
            reconnectAttemptsProvider: static fn() => $client->reconnectAttempts(),
            drainingProvider: static fn() => $client->isDraining(),
            lastHeartbeatProvider: static fn() => $heartbeat->lastHeartbeatAtMicros(),
        );

        $client->attachHealthReporter($health);

        return $client;
    }
}
