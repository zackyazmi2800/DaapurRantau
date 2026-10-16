<?php

namespace App\Exceptions;

use RuntimeException;

class SqliteBusyException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('SQLite contention retry limit reached.');
    }
}
