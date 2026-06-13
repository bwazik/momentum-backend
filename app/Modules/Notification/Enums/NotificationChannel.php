<?php

namespace App\Modules\Notification\Enums;

enum NotificationChannel: int
{
    case InApp = 1;
    case Email = 2;
}
