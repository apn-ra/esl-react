<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

use Apntalk\EslCore\Contracts\InboundPipelineInterface;
use Apntalk\EslCore\Contracts\ReplayCaptureSinkInterface;
use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Contracts\PreparedRuntimeBootstrapInputInterface;
use Apntalk\EslReact\Contracts\PreparedRuntimeDialTargetInputInterface;
use Apntalk\EslReact\Contracts\PreparedRuntimeReplayCaptureInputInterface;
use InvalidArgumentException;
use React\Socket\ConnectorInterface;

final class PreparedRuntimeBootstrapInput implements PreparedRuntimeBootstrapInputInterface, PreparedRuntimeDialTargetInputInterface, PreparedRuntimeReplayCaptureInputInterface
{
    /** @var list<ReplayCaptureSinkInterface>|null */
    private readonly ?array $replayCaptureSinksOverride;

    public function __construct(
        private readonly string $endpoint,
        private readonly RuntimeConfig $runtimeConfig,
        private readonly ConnectorInterface $connector,
        private readonly InboundPipelineInterface $inboundPipeline,
        private readonly RuntimeSessionContext $sessionContext,
        private readonly ?string $dialUri = null,
        private readonly ?bool $replayCaptureEnabledOverride = null,
        ?array $replayCaptureSinksOverride = null,
    ) {
        $this->replayCaptureSinksOverride = $replayCaptureSinksOverride !== null
            ? array_values($replayCaptureSinksOverride)
            : null;

        if ($this->endpoint === '') {
            throw new InvalidArgumentException('endpoint must not be empty');
        }

        if ($this->dialUri !== null && $this->dialUri === '') {
            throw new InvalidArgumentException('dialUri must not be empty when provided');
        }

        if ($this->replayCaptureEnabledOverride === false && $this->replayCaptureSinksOverride !== null && $this->replayCaptureSinksOverride !== []) {
            throw new InvalidArgumentException('replayCaptureSinks must be empty when replay capture is explicitly disabled');
        }

        if ($this->replayCaptureEnabled() && $this->replayCaptureSinks() === []) {
            throw new InvalidArgumentException('replayCaptureSinks must not be empty when replay capture is enabled');
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

    public function replayCaptureEnabled(): bool
    {
        if ($this->replayCaptureEnabledOverride !== null) {
            return $this->replayCaptureEnabledOverride;
        }

        if ($this->replayCaptureSinksOverride !== null) {
            return $this->replayCaptureSinksOverride !== [];
        }

        return $this->runtimeConfig->replayCaptureEnabled;
    }

    /**
     * @return list<ReplayCaptureSinkInterface>
     */
    public function replayCaptureSinks(): array
    {
        return $this->replayCaptureSinksOverride ?? $this->runtimeConfig->replayCaptureSinks;
    }
}
