<?php

namespace App\Modules\Blueprint\Enums;

enum SlaUnit: int
{
    case Hours = 1;
    case Days = 2;

    public function apiValue(): string
    {
        return strtolower($this->name);
    }
}
