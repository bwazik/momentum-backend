<?php

namespace App\Modules\Blueprint\Enums;

enum AssignmentType: int
{
    case SpecificPosition = 1;
    case DepartmentHead = 2;
    case ManualAtLaunch = 3;
}
