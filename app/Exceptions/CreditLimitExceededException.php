<?php

namespace App\Exceptions;

use RuntimeException;

class CreditLimitExceededException extends RuntimeException
{
    public function __construct(string $message = 'Credit limit exceeded.')
    {
        parent::__construct($message);
    }
}
