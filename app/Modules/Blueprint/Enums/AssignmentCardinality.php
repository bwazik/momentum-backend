<?php

namespace App\Modules\Blueprint\Enums;

enum AssignmentCardinality: int
{
    case Single = 1;
    case Multiple = 2;

    public function apiValue(): string
    {
        return strtolower($this->name);
    }
}
