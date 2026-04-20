<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Unit\Config;

use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Tests\Support\CollectingReplaySink;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RuntimeConfigTest extends TestCase
{
    public function testConnectionUriUsesConfiguredHostAndPort(): void
    {
        $config = RuntimeConfig::create(host: '127.0.0.1', port: 9090, password: 'ClueCon');

        self::assertSame('tcp://127.0.0.1:9090', $config->connectionUri());
    }

    public function testCreateRejectsOutOfRangePort(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RuntimeConfig::create(host: '127.0.0.1', port: 70000, password: 'ClueCon');
    }

    public function testReplayCaptureRequiresAtLeastOneSinkWhenEnabled(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RuntimeConfig::create(
            host: '127.0.0.1',
            port: 8021,
            password: 'ClueCon',
            replayCaptureEnabled: true,
            replayCaptureSinks: [],
        );
    }

    public function testReplayCaptureCanBeDisabledEvenWhenSinksAreConfigured(): void
    {
        $sink = new CollectingReplaySink();

        $config = RuntimeConfig::create(
            host: '127.0.0.1',
            port: 8021,
            password: 'ClueCon',
            replayCaptureEnabled: false,
            replayCaptureSinks: [$sink],
        );

        self::assertFalse($config->replayCaptureEnabled);
        self::assertSame([$sink], $config->replayCaptureSinks);
    }
}
