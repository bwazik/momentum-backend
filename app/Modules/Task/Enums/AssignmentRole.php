<?php

namespace App\Modules\Task\Enums;

enum AssignmentRole: int
{
    case Required = 1;
    case Optional = 2;
    case Lead = 3;

    public function apiValue(): string
    {
        return strtolower($this->name);
    }
}
