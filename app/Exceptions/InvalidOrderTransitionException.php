<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidOrderTransitionException extends RuntimeException
{
    public function __construct(string $message = 'Invalid order status transition.')
    {
        parent::__construct($message);
    }
}
