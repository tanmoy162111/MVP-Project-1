<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(string $message = 'Insufficient stock.')
    {
        parent::__construct($message);
    }
}
