<?php declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Unit\Runner;

use Apntalk\EslCore\Contracts\InboundPipelineInterface;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Runner\PreparedRuntimeBootstrapInput;
use Apntalk\EslReact\Runner\RuntimeSessionContext;
use PHPUnit\Framework\TestCase;
use React\Socket\ConnectorInterface;

final class PreparedRuntimeBootstrapInputTest extends TestCase
{
    public function testDialUriDefaultsToRuntimeConfigConnectionUri(): void
    {
        $config = RuntimeConfig::create(host: '127.0.0.1', port: 9090, password: 'ClueCon');

        $input = new PreparedRuntimeBootstrapInput(
            endpoint: 'worker://node-a/session-1',
            runtimeConfig: $config,
            connector: $this->createMock(ConnectorInterface::class),
            inboundPipeline: $this->createMock(InboundPipelineInterface::class),
            sessionContext: new RuntimeSessionContext('runner-session-1'),
        );

        self::assertSame('tcp://127.0.0.1:9090', $input->dialUri());
    }

    public function testDialUriReturnsExplicitOverrideWhenProvided(): void
    {
        $input = new PreparedRuntimeBootstrapInput(
            endpoint: 'worker://node-a/session-1',
            runtimeConfig: RuntimeConfig::create(host: '127.0.0.1', port: 9090, password: 'ClueCon'),
            connector: $this->createMock(ConnectorInterface::class),
            inboundPipeline: $this->createMock(InboundPipelineInterface::class),
            sessionContext: new RuntimeSessionContext('runner-session-1'),
            dialUri: 'tls://pbx.example.test:7443',
        );

        self::assertSame('tls://pbx.example.test:7443', $input->dialUri());
    }

    public function testExplicitEmptyDialUriIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dialUri must not be empty when provided');

        new PreparedRuntimeBootstrapInput(
            endpoint: 'worker://node-a/session-1',
            runtimeConfig: RuntimeConfig::create(host: '127.0.0.1', port: 9090, password: 'ClueCon'),
            connector: $this->createMock(ConnectorInterface::class),
            inboundPipeline: $this->createMock(InboundPipelineInterface::class),
            sessionContext: new RuntimeSessionContext('runner-session-1'),
            dialUri: '',
        );
    }
}
