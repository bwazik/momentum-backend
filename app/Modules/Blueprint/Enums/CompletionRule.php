<?php

namespace App\Modules\Blueprint\Enums;

use Illuminate\Support\Str;

enum CompletionRule: int
{
    case AnyAssignee = 1;
    case AllAssignees = 2;
    case LeadAssignee = 3;

    public function apiValue(): string
    {
        return Str::snake($this->name);
    }
}
