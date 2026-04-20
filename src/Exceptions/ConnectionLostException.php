<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Exceptions;

use Throwable;

class ConnectionLostException extends EslRuntimeException
{
    public function __construct(string $message = 'Connection lost while command was inflight', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
