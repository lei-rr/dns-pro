<?php

declare(strict_types=1);

namespace app\exception;

use RuntimeException;

class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 400,
        private readonly string $errorCode = 'bad_request',
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}
