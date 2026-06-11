<?php

namespace App\Modules\Blueprint\Enums;

enum CompletionRule: int
{
    case AnyAssignee = 1;
    case AllAssignees = 2;
    case LeadAssignee = 3;
}
