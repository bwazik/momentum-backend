<?php

namespace App\Modules\Task\Enums;

enum ExternalReferenceType: int
{
    case Correspondence = 1;
    case Contract = 2;
    case MinisterialDecision = 3;
    case AuthorityDecision = 4;
    case MeetingMinute = 5;
    case ExternalOrgRequest = 6;
    case VendorReference = 7;
    case Other = 8;

    public function apiValue(): string
    {
        return strtolower($this->name);
    }
}
