<?php declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Integration;

use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Config\BackpressureConfig;
use Apntalk\EslReact\Config\HeartbeatConfig;
use Apntalk\EslReact\Config\RetryPolicy;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Exceptions\BackpressureException;
use Apntalk\EslReact\Tests\FakeServer\ScriptedFakeEslServer;
use Apntalk\EslReact\Tests\Support\AsyncTestCase;
use React\Promise\Deferred;

final class BurstAndPressureHardeningTest extends AsyncTestCase
{
    public function testLargeEventBurstInterleavedWithApiTrafficDoesNotDropPublicEvents(): void
    {
        $server = $this->authenticatedServer();
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $server->writeApiResponse($connection, "+OK interleaved\n");
        });
        $client = $this->authenticatedClient($server);

        $seen = [];
        $events = new Deferred();
        $client->events()->onAnyEvent(function ($event) use (&$seen, $events): void {
            $seen[] = $event->uniqueId();
            if (count($seen) === 40) {
                $events->resolve($seen);
            }
        });

        for ($i = 1; $i <= 20; $i++) {
            $server->emitPlainEvent([
                'Event-Name' => 'CHANNEL_CREATE',
                'Event-Sequence' => (string) $i,
                'Unique-ID' => 'uuid-before-' . $i,
            ]);
        }

        $api = $client->api('status');

        for ($i = 1; $i <= 20; $i++) {
            $server->emitPlainEvent([
                'Event-Name' => 'CHANNEL_ANSWER',
                'Event-Sequence' => (string) (20 + $i),
                'Unique-ID' => 'uuid-after-' . $i,
            ]);
        }

        self::assertSame("+OK interleaved\n", $this->await($api)->body());
        $delivered = $this->await($events->promise(), 0.5);

        self::assertCount(40, $delivered);
        self::assertSame('uuid-before-1', $delivered[0]);
        self::assertSame('uuid-after-20', $delivered[39]);
        self::assertTrue($client->health()->snapshot()->isLive);

        $server->close();
    }

    public function testRapidCommandSubmissionHonorsBackpressureBoundaryAndSettlesAcceptedWork(): void
    {
        $server = $this->authenticatedServer();
        $server->queueCommandHandler(static function ($connection, string $command): void {
            self::assertSame('api status', $command);
        });
        $client = $this->authenticatedClient(
            $server,
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                retryPolicy: RetryPolicy::disabled(),
                heartbeat: HeartbeatConfig::disabled(),
                backpressure: BackpressureConfig::withLimit(2, 0.05),
            ),
        );

        $first = $client->api('status');
        $second = $client->api('status');

        try {
            $this->await($client->api('status'), 0.1);
            self::fail('Expected third rapid command to be rejected at the inflight boundary');
        } catch (BackpressureException) {
        }

        $overloaded = $client->health()->snapshot();
        self::assertSame(2, $overloaded->totalInflightCount);
        self::assertTrue($overloaded->isOverloaded);

        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $server->writeApiResponse($connection, "+OK second\n");
        });
        $server->writeApiResponse($server->activeConnection(), "+OK first\n");

        self::assertSame("+OK first\n", $this->await($first, 0.2)->body());
        self::assertSame("+OK second\n", $this->await($second, 0.2)->body());

        $settled = $client->health()->snapshot();
        self::assertSame(0, $settled->totalInflightCount);
        self::assertFalse($settled->isOverloaded);

        $server->close();
    }

    private function authenticatedServer(): ScriptedFakeEslServer
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });

        return $server;
    }

    private function authenticatedClient(
        ScriptedFakeEslServer $server,
        ?RuntimeConfig $config = null,
    ) {
        $client = AsyncEslRuntime::make(
            $config ?? RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                retryPolicy: RetryPolicy::disabled(),
                heartbeat: HeartbeatConfig::disabled(),
            ),
            $this->loop,
        );

        $this->await($client->connect());

        return $client;
    }
}
