<?php

return [
    'exceptions' => [
        'user_already_deactivated' => 'User is already deactivated.',
        'user_already_active' => 'User is already active.',
        'primary_position_already_assigned' => 'User already has an active primary position assignment. End the current one first.',
        'duplicate_grant' => 'An active :type with these parameters already exists.',
        'cannot_revoke_system_capability_key' => 'System-defined capability keys cannot be modified.',
        'cannot_delegate_to_self' => 'Cannot delegate authority to yourself.',
    ],
];
