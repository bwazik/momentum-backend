<?php

return [
    'exceptions' => [
        'duplicate_open_escalation' => 'An open escalation already exists for this stage from this user.',
        'sla_timer_not_active' => 'SLA timer is not in an actionable state.',
        'sla_timer_already_exists' => 'An active SLA timer already exists for this stage instance.',
        'sla_policy_missing' => 'SLA policy not found for stage/sub-stage.',
        'escalation_target_not_found' => 'No escalation target could be resolved for this stage.',
        'escalation_resolution_unauthorized' => 'You are not authorized to resolve this escalation.',
        'escalation_already_resolved' => 'This escalation has already been resolved.',
    ],
];
