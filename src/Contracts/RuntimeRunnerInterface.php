<?php declare(strict_types=1);

namespace Apntalk\EslReact\Contracts;

use Apntalk\EslReact\Runner\RuntimeRunnerHandle;
use React\EventLoop\LoopInterface;

interface RuntimeRunnerInterface
{
    /**
     * Start a live esl-react runtime from a prepared higher-layer input bundle.
     *
     * The returned handle tracks the coarse runner startup state and exposes
     * the stable async client facade for ongoing runtime supervision.
     */
    public function run(RuntimeRunnerInputInterface $input, ?LoopInterface $loop = null): RuntimeRunnerHandle;
}
