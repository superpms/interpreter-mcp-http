<?php

namespace pms\exception;

use RuntimeException;

class McpProtocolException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $rpcCode = -32602,
        public readonly int $httpStatus = 400,
        public readonly mixed $data = null,
    ) {
        parent::__construct($message);
    }
}
