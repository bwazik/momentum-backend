<?php

namespace App\Modules\Task\Enums;

enum StageInstanceStatus: int
{
    case Pending = 1;
    case Active = 2;
    case Completed = 3;
    case Returned = 4;
    case Skipped = 5;

    public function apiValue(): string
    {
        return strtolower($this->name);
    }
}
