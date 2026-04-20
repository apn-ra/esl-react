<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Contracts;

use Apntalk\EslReact\Config\RuntimeConfig;

interface RuntimeRunnerInputInterface
{
    /**
     * Return the higher-layer endpoint identity for this prepared runtime input.
     */
    public function endpoint(): string;

    /**
     * Return the runtime-owned live config to use for the first runner pass.
     */
    public function runtimeConfig(): RuntimeConfig;
}
