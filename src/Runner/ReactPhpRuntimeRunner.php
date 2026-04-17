<?php declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

use Apntalk\EslReact\AsyncEslRuntime;
use Apntalk\EslReact\Contracts\PreparedRuntimeDialTargetInputInterface;
use Apntalk\EslReact\Contracts\PreparedRuntimeBootstrapInputInterface;
use Apntalk\EslReact\Contracts\RuntimeRunnerInputInterface;
use Apntalk\EslReact\Contracts\RuntimeRunnerInterface;
use Apntalk\EslReact\Runtime\RuntimeClientFactory;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

final class ReactPhpRuntimeRunner implements RuntimeRunnerInterface
{
    public function run(RuntimeRunnerInputInterface $input, ?LoopInterface $loop = null): RuntimeRunnerHandle
    {
        $sessionContext = null;

        if ($input instanceof PreparedRuntimeBootstrapInputInterface) {
            $loop ??= Loop::get();
            $input->inboundPipeline()->reset();
            $sessionContext = $input->sessionContext();
            $client = RuntimeClientFactory::make(
                config: $input->runtimeConfig(),
                loop: $loop,
                connector: $input->connector(),
                connectionUri: $input instanceof PreparedRuntimeDialTargetInputInterface ? $input->dialUri() : null,
            );
        } else {
            $client = AsyncEslRuntime::make($input->runtimeConfig(), $loop);
        }

        $startup = $client->connect();

        return new RuntimeRunnerHandle(
            endpoint: $input->endpoint(),
            client: $client,
            startupPromise: $startup,
            sessionContext: $sessionContext,
        );
    }
}
