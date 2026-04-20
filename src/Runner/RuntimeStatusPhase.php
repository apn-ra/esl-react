<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

enum RuntimeStatusPhase: string
{
    case Starting = 'starting';
    case Connecting = 'connecting';
    case Authenticating = 'authenticating';
    case Active = 'active';
    case Reconnecting = 'reconnecting';
    case Disconnected = 'disconnected';
    case Draining = 'draining';
    case Closed = 'closed';
    case Failed = 'failed';
}
