<?php declare(strict_types=1);

namespace Apntalk\EslReact;

use Apntalk\EslCore\Correlation\ConnectionSessionId;
use Apntalk\EslCore\Correlation\CorrelationContext;
use Apntalk\EslCore\Contracts\CommandInterface;
use Apntalk\EslCore\Events\EventFactory;
use Apntalk\EslCore\Parsing\FrameParser;
use Apntalk\EslCore\Serialization\CommandSerializer;
use Apntalk\EslCore\Internal\Classification\InboundMessageClassifier;
use Apntalk\EslReact\Bgapi\BgapiDispatcher;
use Apntalk\EslReact\Bgapi\BgapiJobTracker;
use Apntalk\EslReact\CommandBus\AsyncCommandBus;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Contracts\AsyncEslClientInterface;
use Apntalk\EslReact\Events\EventStream;
use Apntalk\EslReact\Health\RuntimeHealthReporter;
use Apntalk\EslReact\Protocol\EnvelopePump;
use Apntalk\EslReact\Protocol\FrameReader;
use Apntalk\EslReact\Protocol\FrameWriter;
use Apntalk\EslReact\Protocol\InboundMessageRouter;
use Apntalk\EslReact\Protocol\OutboundMessageDispatcher;
use Apntalk\EslReact\Runtime\RuntimeClient;
use Apntalk\EslReact\Subscription\ActiveSubscriptionSet;
use Apntalk\EslReact\Subscription\FilterManager;
use Apntalk\EslReact\Subscription\SubscriptionManager;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\Connector;

final class AsyncEslRuntime
{
    public static function make(RuntimeConfig $config, ?LoopInterface $loop = null): AsyncEslClientInterface
    {
        $loop ??= Loop::get();

        $correlation = new CorrelationContext(ConnectionSessionId::generate());
        $eventStream = new EventStream(new EventFactory(), $correlation);
        $outbound = new OutboundMessageDispatcher(new FrameWriter(new CommandSerializer()));
        $commandBus = new AsyncCommandBus(
            sendFn: static function ($command) use ($outbound): void {
                $outbound->dispatch($command);
            },
            loop: $loop,
            maxQueued: $config->backpressure->maxInflightCommands,
        );
        $bgapiTracker = new BgapiJobTracker($loop, $config->commandTimeout->bgapiOrphanTimeoutSeconds);
        $bgapi = new BgapiDispatcher(
            tracker: $bgapiTracker,
            sendCommandReply: static function (CommandInterface $command, string $description, float $timeoutSeconds) use ($commandBus) {
                return $commandBus->dispatch($command, $description, $timeoutSeconds);
            },
            ackTimeoutSeconds: $config->commandTimeout->bgapiAckTimeoutSeconds,
        );
        $subscriptions = new SubscriptionManager(
            activeSubscriptions: new ActiveSubscriptionSet(),
            filters: new FilterManager(),
            dispatchCommand: static function (CommandInterface $command, string $description, float $timeoutSeconds) use ($commandBus) {
                return $commandBus->dispatch($command, $description, $timeoutSeconds);
            },
            timeoutSeconds: $config->commandTimeout->subscriptionTimeoutSeconds,
        );
        $client = new RuntimeClient(
            config: $config,
            loop: $loop,
            connector: new Connector([], $loop),
            envelopePump: new EnvelopePump(new FrameReader(new FrameParser())),
            router: new InboundMessageRouter(new InboundMessageClassifier()),
            outbound: $outbound,
            commands: $commandBus,
            bgapi: $bgapi,
            bgapiTracker: $bgapiTracker,
            events: $eventStream,
            subscriptions: $subscriptions,
        );

        $health = new RuntimeHealthReporter(
            connectionStateProvider: static fn () => $client->connectionState(),
            sessionStateProvider: static fn () => $client->sessionState(),
            livenessProvider: static fn () => $client->livenessState(),
            inflightCountProvider: static fn () => $client->inflightCommandCount(),
            bgapiPendingCountProvider: static fn () => $bgapiTracker->pendingCount(),
            subscriptionsProvider: static fn () => $subscriptions->activeEventNames(),
            reconnectAttemptsProvider: static fn () => 0,
            drainingProvider: static fn () => $client->isDraining(),
            lastHeartbeatProvider: static fn () => null,
        );

        $client->attachHealthReporter($health);

        return $client;
    }
}
