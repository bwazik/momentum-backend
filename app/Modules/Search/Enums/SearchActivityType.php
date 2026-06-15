<?php

namespace App\Modules\Search\Enums;

enum SearchActivityType: int
{
    case TaskViewed = 1;
    case StageCompleted = 2;
    case StageReturned = 3;
    case CommentAdded = 4;
}
