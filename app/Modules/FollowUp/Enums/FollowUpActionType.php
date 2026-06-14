<?php

namespace App\Modules\FollowUp\Enums;

enum FollowUpActionType: int
{
    case PhoneCall = 1;
    case Message = 2;
    case Meeting = 3;
    case Email = 4;
    case Other = 5;
}
