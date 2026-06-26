<?php

return [
    'exceptions' => [
        'authority_grade_has_active_positions' => 'Cannot delete an authority grade that is referenced by active positions.',
        'department_has_children' => 'Cannot delete a department that has child departments.',
        'department_has_active_positions' => 'Cannot delete a department that has active positions.',
        'circular_reporting_line' => 'Circular reference detected: a position cannot report to itself or its descendants.',
        'circular_department_reference' => 'Circular reference detected: a department cannot be its own ancestor.',
        'cannot_delete_default_calendar' => 'Cannot delete the default working calendar.',
    ],
];
