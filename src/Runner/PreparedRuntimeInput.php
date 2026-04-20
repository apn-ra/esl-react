<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Contracts\RuntimeRunnerInputInterface;
use InvalidArgumentException;

final class PreparedRuntimeInput implements RuntimeRunnerInputInterface
{
    public function __construct(
        private readonly string $endpoint,
        private readonly RuntimeConfig $runtimeConfig,
    ) {
        if ($this->endpoint === '') {
            throw new InvalidArgumentException('endpoint must not be empty');
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
}
