<?php

namespace App\Modules\FollowUp\Exceptions;

use App\Exceptions\DomainException;

class InvalidBoardFilterException extends DomainException
{
    protected int $statusCode = 422;

    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? __('followup.exceptions.invalid_board_filter'));
    }
}
