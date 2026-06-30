<?php

namespace App\Modules\Task\Exceptions;

use App\Exceptions\DomainException;

class CommentNotFoundException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('task.exceptions.comment_not_found'));
    }
}
