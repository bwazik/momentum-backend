<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class InvalidCommentParentException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('task.exceptions.invalid_comment_parent'));
    }
}
