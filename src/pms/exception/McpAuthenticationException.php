<?php

namespace pms\exception;

use RuntimeException;

class McpAuthenticationException extends RuntimeException
{
    public function __construct(
        string $message = 'Authentication required',
        public readonly int $httpStatus = 401,
        public readonly string $error = 'invalid_token',
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
