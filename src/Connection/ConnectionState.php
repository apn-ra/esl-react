<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Connection;

enum ConnectionState: string
{
    case Disconnected = 'disconnected';
    case Connecting = 'connecting';
    case Connected = 'connected';
    case Authenticating = 'authenticating';
    case Authenticated = 'authenticated';
    case Reconnecting = 'reconnecting';
    case Draining = 'draining';
    case Closed = 'closed';
    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }
    public function canAcceptCommands(): bool
    {
        return $this === self::Authenticated;
    }
    public function isConnectedOrAbove(): bool
    {
        return in_array($this, [self::Connected, self::Authenticating, self::Authenticated, self::Draining], true);
    }
}
