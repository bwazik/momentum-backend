<?php

namespace App\Modules\Tracking\Enums;

use Illuminate\Support\Str;

enum EscalationType: int
{
    case AutoSlaBreach = 1;
    case Manual = 2;

    public function apiValue(): string
    {
        return Str::snake($this->name);
    }
}
