<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Contracts;

use Apntalk\EslCore\Contracts\InboundPipelineInterface;
use Apntalk\EslReact\Runner\RuntimeSessionContext;
use React\Socket\ConnectorInterface;

interface PreparedRuntimeBootstrapInputInterface extends RuntimeRunnerInputInterface
{
    /**
     * Return the prepared ReactPHP transport connector for live runtime startup.
     */
    public function connector(): ConnectorInterface;

    /**
     * Return the prepared ingress pipeline associated with this runtime handoff.
     */
    public function inboundPipeline(): InboundPipelineInterface;

    /**
     * Return runtime-local session identity and metadata for this handoff.
     */
    public function sessionContext(): RuntimeSessionContext;
}
