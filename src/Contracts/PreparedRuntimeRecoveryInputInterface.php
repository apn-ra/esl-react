<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Contracts;

use Apntalk\EslReact\Runner\PreparedRuntimeRecoveryContext;

interface PreparedRuntimeRecoveryInputInterface extends RuntimeRunnerInputInterface
{
    /**
     * Return prepared recovery/reconstruction truth for this runtime bootstrap.
     *
     * This is descriptive only. It does not make esl-react own durable
     * persistence, replay execution, or cross-process orchestration.
     */
    public function recoveryContext(): PreparedRuntimeRecoveryContext;
}
