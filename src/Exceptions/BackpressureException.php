<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Exceptions;

use Throwable;

class BackpressureException extends EslRuntimeException
{
    public function __construct(string $message = 'Command rejected: backpressure limit reached', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
