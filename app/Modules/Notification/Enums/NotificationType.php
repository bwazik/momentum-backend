<?php

namespace App\Modules\Notification\Enums;

enum NotificationType: string
{
    case StageAssignmentReceived = 'stage_assignment_received';
    case TaskReturned = 'task_returned';
    case TaskAdvanced = 'task_advanced';
    case SlaWarning = 'sla_warning';
    case SlaBreach = 'sla_breach';
    case EscalationReceived = 'escalation_received';
    case TaskCompleted = 'task_completed';
    case TaskCancelled = 'task_cancelled';
    case TaskSuspended = 'task_suspended';
    case TaskResumed = 'task_resumed';
}
