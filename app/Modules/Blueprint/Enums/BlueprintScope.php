<?php

namespace App\Modules\Blueprint\Enums;

enum BlueprintScope: int
{
    case Organization = 1;
    case Department = 2;

    public function apiValue(): string
    {
        return strtolower($this->name);
    }
}
