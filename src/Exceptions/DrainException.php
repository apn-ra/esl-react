<?php declare(strict_types=1);
namespace Apntalk\EslReact\Exceptions;
class DrainException extends EslRuntimeException {
    public function __construct(string $message = 'Cannot accept new commands: runtime is draining', int $code = 0, ?\Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}
