<?php

namespace App\Modules\Blueprint\Enums;

enum TransitionType: int
{
    case Advance = 1;
    case Return = 2;

    public function apiValue(): string
    {
        return strtolower($this->name);
    }
}
