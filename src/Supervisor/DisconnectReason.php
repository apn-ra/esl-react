<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Supervisor;

enum DisconnectReason
{
    case Expected;       // Clean exit via ExitCommand
    case AuthFailure;    // Authentication rejected
    case NetworkError;   // TCP/socket error
    case ProtocolError;  // Malformed protocol
    case Unknown;        // Could not determine

    public function shouldReconnect(): bool
    {
        return match ($this) {
            self::Expected,
            self::AuthFailure => false,
            self::NetworkError,
            self::ProtocolError,
            self::Unknown => true,
        };
    }
}
