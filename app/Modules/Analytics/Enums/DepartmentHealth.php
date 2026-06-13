<?php

namespace App\Modules\Analytics\Enums;

enum DepartmentHealth: int
{
    case Green = 1;
    case Amber = 2;
    case Red = 3;
}
