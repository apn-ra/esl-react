<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Runner;

enum RuntimeReconnectStopReason: string
{
    case ExplicitShutdown = 'explicit_shutdown';
    case RetryExhausted = 'retry_exhausted';
    case RetryDisabled = 'retry_disabled';
    case AuthenticationRejected = 'authentication_rejected';
    case HandshakeTimeout = 'handshake_timeout';
    case HandshakeProtocolFailure = 'handshake_protocol_failure';

    public function isPolicyDerived(): bool
    {
        return match ($this) {
            self::ExplicitShutdown,
            self::RetryExhausted,
            self::RetryDisabled => true,
            self::AuthenticationRejected,
            self::HandshakeTimeout,
            self::HandshakeProtocolFailure => false,
        };
    }

    public function isFailClosed(): bool
    {
        return match ($this) {
            self::ExplicitShutdown => false,
            self::RetryExhausted,
            self::RetryDisabled,
            self::AuthenticationRejected,
            self::HandshakeTimeout,
            self::HandshakeProtocolFailure => true,
        };
    }
}
