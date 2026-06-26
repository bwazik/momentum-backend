<?php

return [
    'catalog' => [
        'sub_stage_in_use' => 'Cannot delete sub-stage because it is referenced by one or more task sub-stage instances.',
        'stage_in_use' => 'Cannot delete stage because it is referenced by one or more task stage instances.',
        'category_in_use' => 'Cannot delete category because it is referenced by one or more blueprints.',
        'stage_type_in_use' => 'Cannot delete stage type because it is referenced by one or more blueprint stages.',
        'sla_policy_in_use' => 'Cannot delete SLA policy because it is referenced by one or more blueprint stages.',
    ],
    'exceptions' => [
        'invalid_transition' => 'Invalid transition definition.',
        'invalid_stage_sequence' => 'Invalid stage sequence order.',
        'invalid_blueprint_scope' => 'Department ID is required when blueprint scope is department.',
        'blueprint_locked' => 'Blueprint is locked and cannot be modified.',
        'unauthorized_blueprint_scope' => 'You do not have the required capability for the requested blueprint scope.',
    ],
];
