<?php declare(strict_types=1);

namespace Apntalk\EslReact\Contracts;

use Apntalk\EslCore\Contracts\ReplayCaptureSinkInterface;

interface PreparedRuntimeReplayCaptureInputInterface extends PreparedRuntimeBootstrapInputInterface
{
    public function replayCaptureEnabled(): bool;

    /**
     * @return list<ReplayCaptureSinkInterface>
     */
    public function replayCaptureSinks(): array;
}
