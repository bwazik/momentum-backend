<?php

namespace App\Modules\FollowUp\Enums;

enum SlaHealth: int
{
    case Green = 1;
    case Amber = 2;
    case Red = 3;
    case Grey = 4;
}
