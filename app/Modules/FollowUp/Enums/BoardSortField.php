<?php

namespace App\Modules\FollowUp\Enums;

enum BoardSortField: string
{
    case Priority = 'priority';
    case DueDate = 'due_date';
    case CreatedAt = 'created_at';
    case TimeAtStage = 'time_at_stage';
    case Department = 'department';
    case StageType = 'stage_type';
}
