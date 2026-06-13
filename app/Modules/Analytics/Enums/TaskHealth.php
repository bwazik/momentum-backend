<?php

namespace App\Modules\Analytics\Enums;

enum TaskHealth: int
{
    case Green = 1;
    case Amber = 2;
    case Red = 3;
    case Grey = 4;
}
