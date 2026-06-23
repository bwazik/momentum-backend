<?php

namespace App\Modules\Task\Enums;

enum ClassificationLevel: int
{
    case Public = 1;
    case Internal = 2;
    case Confidential = 3;

    public function apiValue(): string
    {
        return strtolower($this->name);
    }
}
