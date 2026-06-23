<?php

namespace App\Modules\Task\Enums;

enum SubStageInstanceStatus: int
{
    case Pending = 1;
    case Active = 2;
    case Completed = 3;
    case Returned = 4;

    public function apiValue(): string
    {
        return strtolower($this->name);
    }
}
