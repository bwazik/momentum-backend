<?php

namespace App\Enums;

enum AccountType: int
{
    case INTERNAL_USER = 1;
    case TENANT_ADMIN = 2;
    case EXTERNAL_AUDITOR = 3;
    case PLATFORM_ADMIN = 4;
}
