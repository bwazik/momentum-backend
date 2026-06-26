<?php

namespace App\Modules\Iam\Exceptions;

use App\Exceptions\DomainException;

class DuplicateGrantException extends DomainException
{
    public function __construct(string $type = 'grant')
    {
        parent::__construct(__('iam.exceptions.duplicate_grant', ['type' => $type]));
    }
}
