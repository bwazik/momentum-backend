<?php

namespace App\Modules\Blueprint\Enums;

use Illuminate\Support\Str;

enum AssignmentType: int
{
    case SpecificPosition = 1;
    case DepartmentHead = 2;
    case ManualAtLaunch = 3;

    public function apiValue(): string
    {
        return Str::snake($this->name);
    }
}
