<?php

namespace App\Modules\Iam\Exceptions;

use Exception;

class DuplicateGrantException extends Exception
{
    public function __construct(string $type = 'grant')
    {
        parent::__construct("An active {$type} with these parameters already exists.");
    }
}
