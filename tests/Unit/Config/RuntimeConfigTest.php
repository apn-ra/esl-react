<?php declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Unit\Config;

use Apntalk\EslReact\Config\RuntimeConfig;
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
        $this->expectException(\InvalidArgumentException::class);

        RuntimeConfig::create(host: '127.0.0.1', port: 70000, password: 'ClueCon');
    }
}
