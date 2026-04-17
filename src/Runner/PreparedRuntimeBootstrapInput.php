<?php declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

use Apntalk\EslCore\Contracts\InboundPipelineInterface;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Contracts\PreparedRuntimeBootstrapInputInterface;
use React\Socket\ConnectorInterface;

final class PreparedRuntimeBootstrapInput implements PreparedRuntimeBootstrapInputInterface
{
    public function __construct(
        private readonly string $endpoint,
        private readonly RuntimeConfig $runtimeConfig,
        private readonly ConnectorInterface $connector,
        private readonly InboundPipelineInterface $inboundPipeline,
        private readonly RuntimeSessionContext $sessionContext,
    ) {
        if ($this->endpoint === '') {
            throw new \InvalidArgumentException('endpoint must not be empty');
        }
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function runtimeConfig(): RuntimeConfig
    {
        return $this->runtimeConfig;
    }

    public function connector(): ConnectorInterface
    {
        return $this->connector;
    }

    public function inboundPipeline(): InboundPipelineInterface
    {
        return $this->inboundPipeline;
    }

    public function sessionContext(): RuntimeSessionContext
    {
        return $this->sessionContext;
    }
}
