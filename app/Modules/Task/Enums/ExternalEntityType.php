<?php

namespace App\Modules\Task\Enums;

enum ExternalEntityType: int
{
    case GovernmentMinistry = 1;
    case GovernmentAuthority = 2;
    case SemiGovernment = 3;
    case University = 4;
    case Hospital = 5;
    case PrivateCompany = 6;
    case Vendor = 7;
    case Other = 8;

    public function apiValue(): string
    {
        return strtolower($this->name);
    }
}
