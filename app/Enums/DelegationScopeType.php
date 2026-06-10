<?php

namespace App\Enums;

enum DelegationScopeType: int
{
    case ALL = 1;
    case BLUEPRINT_CATEGORY = 2;
    case STAGE_TYPE = 3;
    case BLUEPRINT_CATEGORY_AND_STAGE_TYPE = 4;
}
