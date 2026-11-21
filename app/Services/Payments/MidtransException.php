<?php

namespace App\Services\Payments;

use RuntimeException;
use Throwable;

final class MidtransException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $statusCode = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
