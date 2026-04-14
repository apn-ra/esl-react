<?php declare(strict_types=1);
namespace Apntalk\EslReact\Exceptions;
class AuthenticationException extends EslRuntimeException {
    public function __construct(string $reason = 'Authentication rejected', int $code = 0, ?\Throwable $previous = null) {
        parent::__construct($reason, $code, $previous);
    }
}
