<?php declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

enum RuntimeReconnectPhase: string
{
    case Idle = 'idle';
    case WaitingToRetry = 'waiting_to_retry';
    case AttemptingReconnect = 'attempting_reconnect';
    case RestoringSession = 'restoring_session';
    case Exhausted = 'exhausted';
}
