<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Session;

enum SessionState: string
{
    case NotStarted = 'not_started';
    case Authenticating = 'authenticating';
    case Active = 'active';
    case Disconnected = 'disconnected';
    case Failed = 'failed';
    public function isActive(): bool
    {
        return $this === self::Active;
    }
    public function isFailed(): bool
    {
        return $this === self::Failed;
    }
    public function isTerminal(): bool
    {
        return $this === self::Failed || $this === self::Disconnected;
    }
}
