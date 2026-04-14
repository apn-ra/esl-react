<?php declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Integration;

use Apntalk\EslCore\Replies\ApiReply;
use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Config\CommandTimeoutConfig;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Connection\ConnectionState;
use Apntalk\EslReact\Exceptions\AuthenticationException;
use Apntalk\EslReact\Exceptions\CommandTimeoutException;
use Apntalk\EslReact\Exceptions\ConnectionException;
use Apntalk\EslReact\Exceptions\ConnectionLostException;
use Apntalk\EslReact\Session\SessionState;
use Apntalk\EslReact\Tests\FakeServer\ScriptedFakeEslServer;
use Apntalk\EslReact\Tests\Support\AsyncTestCase;

final class ConnectAuthLifecycleTest extends AsyncTestCase
{
    public function testConnectAuthenticatesAndAllowsApiCommand(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('api status', $command);
            $server->writeApiResponse($connection, "+OK FreeSWITCH ready\n");
        });

        $config = RuntimeConfig::create(host: '127.0.0.1', port: $server->port(), password: 'ClueCon');
        $client = AsyncEslRuntime::make($config, $this->loop);

        $this->await($client->connect());

        $reply = $this->await($client->api('status'));

        self::assertInstanceOf(ApiReply::class, $reply);
        self::assertSame("+OK FreeSWITCH ready\n", $reply->body());
        self::assertSame(
            ['auth ClueCon', 'api status'],
            $server->receivedCommands(),
        );

        $snapshot = $client->health()->snapshot();
        self::assertSame(ConnectionState::Authenticated, $snapshot->connectionState);
        self::assertSame(SessionState::Active, $snapshot->sessionState);
        self::assertTrue($snapshot->isLive);

        $server->close();
    }

    public function testConnectRejectsWhenAuthenticationFails(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth wrong-password', $command);
            $server->writeCommandReply($connection, '-ERR invalid password');
        });

        $config = RuntimeConfig::create(host: '127.0.0.1', port: $server->port(), password: 'wrong-password');
        $client = AsyncEslRuntime::make($config, $this->loop);

        try {
            $this->await($client->connect());
            self::fail('Expected authentication to fail');
        } catch (AuthenticationException $e) {
            self::assertSame('invalid password', $e->getMessage());
        }

        $snapshot = $client->health()->snapshot();
        self::assertSame(ConnectionState::Disconnected, $snapshot->connectionState);
        self::assertSame(SessionState::Failed, $snapshot->sessionState);

        $server->close();
    }

    public function testApiBeforeConnectRejects(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(host: '127.0.0.1', port: $server->port(), password: 'ClueCon'),
            $this->loop,
        );

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Runtime is not authenticated');

        $this->await($client->api('status'));
    }

    public function testRepeatedConnectCallsShareTheSamePendingPromise(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $this->loop->addTimer(0.01, function () use ($connection, $server): void {
                $server->writeCommandReply($connection, '+OK accepted');
            });
        });

        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(host: '127.0.0.1', port: $server->port(), password: 'ClueCon'),
            $this->loop,
        );

        $first = $client->connect();
        $second = $client->connect();

        self::assertSame($first, $second);
        $this->await($first);
        $this->await($second);

        $snapshot = $client->health()->snapshot();
        self::assertSame(ConnectionState::Authenticated, $snapshot->connectionState);
        self::assertSame(SessionState::Active, $snapshot->sessionState);

        $server->close();
    }

    public function testConnectTimesOutWhenHandshakeDoesNotComplete(): void
    {
        $server = new ScriptedFakeEslServer($this->loop, autoAuthRequest: false);
        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                commandTimeout: CommandTimeoutConfig::withApiTimeout(0.05),
            ),
            $this->loop,
        );

        try {
            $this->await($client->connect(), 0.25);
            self::fail('Expected connect() to time out');
        } catch (CommandTimeoutException $e) {
            self::assertSame('connect/auth handshake', $e->eslCommand());
        }

        $snapshot = $client->health()->snapshot();
        self::assertSame(ConnectionState::Disconnected, $snapshot->connectionState);
        self::assertSame(SessionState::Failed, $snapshot->sessionState);
        self::assertSame(CommandTimeoutException::class, $snapshot->lastErrorClass);
        self::assertFalse($snapshot->isLive);

        $server->close();
    }

    public function testMalformedAuthReplyFailsClosed(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeRawFrame($connection, "Content-Type: command/reply\n\n");
        });

        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(host: '127.0.0.1', port: $server->port(), password: 'ClueCon'),
            $this->loop,
        );

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Unexpected inbound frame during connect/auth handshake');

        try {
            $this->await($client->connect());
        } finally {
            $snapshot = $client->health()->snapshot();
            self::assertSame(ConnectionState::Disconnected, $snapshot->connectionState);
            self::assertSame(SessionState::Failed, $snapshot->sessionState);
            self::assertSame(ConnectionException::class, $snapshot->lastErrorClass);
            $server->close();
        }
    }

    public function testDisconnectBeforeAuthCompletesRejectsPendingConnectAndClosesRuntime(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(static function ($connection, string $command): void {
            self::assertSame('auth ClueCon', $command);
        });

        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(host: '127.0.0.1', port: $server->port(), password: 'ClueCon'),
            $this->loop,
        );

        $connect = $client->connect();
        $connect->then(null, static function (): void {
        });
        $this->await($client->disconnect());

        try {
            $this->await($connect);
            self::fail('Expected pending connect() to reject after disconnect()');
        } catch (ConnectionLostException $e) {
            self::assertSame('Disconnect requested before auth completed', $e->getMessage());
        }

        $snapshot = $client->health()->snapshot();
        self::assertSame(ConnectionState::Closed, $snapshot->connectionState);
        self::assertSame(SessionState::Disconnected, $snapshot->sessionState);
        self::assertFalse($snapshot->isLive);

        $server->close();
    }

    public function testApiReplyTimeoutRejectsWithoutDroppingAuthenticatedState(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(static function ($connection, string $command): void {
            self::assertSame('api status', $command);
        });

        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(
                host: '127.0.0.1',
                port: $server->port(),
                password: 'ClueCon',
                commandTimeout: CommandTimeoutConfig::withApiTimeout(0.05),
            ),
            $this->loop,
        );

        $this->await($client->connect());

        try {
            $this->await($client->api('status'), 0.25);
            self::fail('Expected api() to time out');
        } catch (CommandTimeoutException $e) {
            self::assertSame('api status', $e->eslCommand());
        }

        $snapshot = $client->health()->snapshot();
        self::assertSame(ConnectionState::Authenticated, $snapshot->connectionState);
        self::assertSame(SessionState::Active, $snapshot->sessionState);
        self::assertSame(CommandTimeoutException::class, $snapshot->lastErrorClass);
        self::assertTrue($snapshot->isLive);

        $server->close();
    }

    public function testDisconnectDuringInflightApiRejectsTheCommand(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });
        $server->queueCommandHandler(static function ($connection, string $command): void {
            self::assertSame('api status', $command);
        });

        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(host: '127.0.0.1', port: $server->port(), password: 'ClueCon'),
            $this->loop,
        );

        $this->await($client->connect());

        $api = $client->api('status');
        $api->then(null, static function (): void {
        });
        $disconnect = $client->disconnect();

        $this->await($disconnect);

        $this->expectException(ConnectionLostException::class);
        try {
            $this->await($api);
        } finally {
            $snapshot = $client->health()->snapshot();
            self::assertSame(ConnectionState::Closed, $snapshot->connectionState);
            self::assertSame(SessionState::Disconnected, $snapshot->sessionState);
            self::assertFalse($snapshot->isLive);
            $server->close();
        }
    }

    public function testDisconnectAfterAuthenticatedStateClosesTheRuntime(): void
    {
        $server = new ScriptedFakeEslServer($this->loop);
        $server->queueCommandHandler(function ($connection, string $command) use ($server): void {
            self::assertSame('auth ClueCon', $command);
            $server->writeCommandReply($connection, '+OK accepted');
        });

        $client = AsyncEslRuntime::make(
            RuntimeConfig::create(host: '127.0.0.1', port: $server->port(), password: 'ClueCon'),
            $this->loop,
        );

        $this->await($client->connect());
        $this->await($client->disconnect());

        $snapshot = $client->health()->snapshot();
        self::assertSame(ConnectionState::Closed, $snapshot->connectionState);
        self::assertSame(SessionState::Disconnected, $snapshot->sessionState);
        self::assertFalse($snapshot->isLive);

        $server->close();
    }
}
