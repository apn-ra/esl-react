<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Exceptions;

use Throwable;

class CommandTimeoutException extends EslRuntimeException
{
    public function __construct(
        private readonly string $eslCommand,
        private readonly float $timeoutSeconds,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('ESL command "%s" timed out after %.2f seconds', $eslCommand, $timeoutSeconds),
            $code,
            $previous,
        );
    }
    public function eslCommand(): string
    {
        return $this->eslCommand;
    }
    public function timeoutSeconds(): float
    {
        return $this->timeoutSeconds;
    }
}
