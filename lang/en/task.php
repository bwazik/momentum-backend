<?php

return [
    'exceptions' => [
        'manual_assignment_required' => 'Stage \':name\' requires manual assignment but none were provided.',
        'manual_assignment_required_sub' => 'Sub-stage \':name\' requires manual assignment but none were provided.',
        'invalid_sub_stage_return_target' => 'Invalid sub-stage return target: must be an earlier sub-stage in the same parent stage.',
        'invalid_return_target' => 'Invalid return target: no return transition defined for this stage.',
        'blueprint_not_active' => 'Blueprint is not active.',
        'blueprint_has_no_stages' => 'Blueprint has no stages defined.',
        'assignee_not_found_for_override' => 'User is not an active assignee that can be overridden.',
        'required_sub_stages_incomplete' => 'Cannot complete stage: required sub-stages are not all completed.',
        'invalid_task_state_transition' => 'Invalid task state transition.',
        'stage_not_active' => 'Stage instance is not in active status.',
        'sub_stage_not_active' => 'Sub-stage instance is not in active status.',
        'user_not_assignee' => 'User is not an active assignee of this stage.',
        'task_not_suspended' => 'Task is not in suspended status.',
        'task_not_draft' => 'Task is not in draft status.',
        'task_not_active' => 'Task is not in active status.',
        'task_already_cancelled' => 'Task is already cancelled.',
        'unresolvable_assignment' => 'Cannot resolve assignee for this stage.',
    ],
];
