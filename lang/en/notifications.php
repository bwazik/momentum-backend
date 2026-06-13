<?php

return [
    'view_task' => 'View Task',

    'stage_assignment' => [
        'subject' => 'New stage assigned to you',
        'title' => 'Stage Assignment',
        'body' => 'You have been assigned to stage ":stage" of task ":task".',
    ],

    'task_returned' => [
        'subject' => 'Task returned to your stage',
        'title' => 'Task Returned',
        'body' => 'Task ":task" has been returned to stage ":stage".',
    ],

    'task_advanced' => [
        'subject' => 'Task advanced to next stage',
        'title' => 'Task Advanced',
        'body' => 'Task ":task" has advanced from stage ":stage".',
    ],

    'sla_warning' => [
        'subject' => 'Warning: SLA approaching deadline',
        'title' => 'SLA Warning',
        'body' => 'Task ":task" in stage ":stage" is approaching its SLA deadline. Please take action.',
    ],

    'sla_breach' => [
        'subject' => 'SLA deadline breached',
        'title' => 'SLA Breached',
        'body' => 'Task ":task" has breached its SLA deadline in stage ":stage".',
    ],

    'escalation_received' => [
        'subject' => 'Task escalated to you',
        'title' => 'Escalation Received',
        'body' => 'Task ":task" from stage ":stage" has been escalated to you for action.',
    ],

    'task_completed' => [
        'subject' => 'Task completed',
        'title' => 'Task Completed',
        'body' => 'Task ":task" has been completed successfully.',
    ],

    'task_cancelled' => [
        'subject' => 'Task cancelled',
        'title' => 'Task Cancelled',
        'body' => 'Task ":task" has been cancelled. Reason: :reason',
    ],

    'task_suspended' => [
        'subject' => 'Task suspended',
        'title' => 'Task Suspended',
        'body' => 'Task ":task" has been suspended. Reason: :reason',
    ],

    'task_resumed' => [
        'subject' => 'Task resumed',
        'title' => 'Task Resumed',
        'body' => 'Task ":task" has been resumed.',
    ],
];
