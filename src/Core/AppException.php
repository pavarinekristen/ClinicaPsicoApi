<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class AppException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message, private readonly int $statusCode = 400, private readonly array $details = [])
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }
}