<?php

declare(strict_types=1);

namespace Apntalk\EslReact;

use Apntalk\EslReact\Config\RuntimeConfig;
use Apntalk\EslReact\Contracts\AsyncEslClientInterface;
use Apntalk\EslReact\Contracts\RuntimeRunnerInterface;
use Apntalk\EslReact\Runner\ReactPhpRuntimeRunner;
use Apntalk\EslReact\Runtime\RuntimeClientFactory;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

final class AsyncEslRuntime
{
    public static function runner(): RuntimeRunnerInterface
    {
        return new ReactPhpRuntimeRunner();
    }

    public static function make(RuntimeConfig $config, ?LoopInterface $loop = null): AsyncEslClientInterface
    {
        $loop ??= Loop::get();

        return RuntimeClientFactory::make($config, $loop);
    }
}
