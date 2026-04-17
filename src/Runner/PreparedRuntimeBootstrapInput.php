<?php declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

use Apntalk\EslCore\Contracts\InboundPipelineInterface;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Contracts\PreparedRuntimeDialTargetInputInterface;
use Apntalk\EslReact\Contracts\PreparedRuntimeBootstrapInputInterface;
use React\Socket\ConnectorInterface;

final class PreparedRuntimeBootstrapInput implements PreparedRuntimeBootstrapInputInterface, PreparedRuntimeDialTargetInputInterface
{
    public function __construct(
        private readonly string $endpoint,
        private readonly RuntimeConfig $runtimeConfig,
        private readonly ConnectorInterface $connector,
        private readonly InboundPipelineInterface $inboundPipeline,
        private readonly RuntimeSessionContext $sessionContext,
        private readonly ?string $dialUri = null,
    ) {
        if ($this->endpoint === '') {
            throw new \InvalidArgumentException('endpoint must not be empty');
        }

        if ($this->dialUri !== null && $this->dialUri === '') {
            throw new \InvalidArgumentException('dialUri must not be empty when provided');
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

    public function dialUri(): string
    {
        return $this->dialUri ?? $this->runtimeConfig->connectionUri();
    }
}
