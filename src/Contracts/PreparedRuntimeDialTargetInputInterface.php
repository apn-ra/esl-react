<?php declare(strict_types=1);

namespace Apntalk\EslReact\Contracts;

interface PreparedRuntimeDialTargetInputInterface extends PreparedRuntimeBootstrapInputInterface
{
    /**
     * Return the URI the prepared connector should dial for startup and reconnect attempts.
     */
    public function dialUri(): string;
}
