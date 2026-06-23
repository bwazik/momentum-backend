<?php

namespace App\Modules\Tracking\Enums;

enum EscalationStatus: int
{
    case Open = 1;
    case Resolved = 2;

    public function apiValue(): string
    {
        return strtolower($this->name);
    }
}
