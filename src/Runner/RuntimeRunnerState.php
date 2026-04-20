<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

enum RuntimeRunnerState: string
{
    case Starting = 'starting';
    case Running = 'running';
    case Failed = 'failed';
}
