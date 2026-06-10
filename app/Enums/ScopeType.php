<?php

namespace App\Enums;

enum ScopeType: int
{
    case TENANT = 1;
    case OWN_DEPARTMENT = 2;
    case SPECIFIC_DEPARTMENT = 3;
    case DEPARTMENT_TREE = 4;
    case OWN_TASKS = 5;
    case AUDIT_GRANT = 6;
}
