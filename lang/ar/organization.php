<?php

return [
    'exceptions' => [
        'authority_grade_has_active_positions' => 'لا يمكن حذف درجة صلاحية مرتبطة بمناصب نشطة.',
        'department_has_children' => 'لا يمكن حذف إدارة تحتوي على إدارات فرعية.',
        'department_has_active_positions' => 'لا يمكن حذف إدارة تحتوي على مناصب نشطة.',
        'circular_reporting_line' => 'تم اكتشاف مرجع دائري: لا يمكن لمنصب أن يتبع نفسه أو مناصبه التابعة.',
        'circular_department_reference' => 'تم اكتشاف مرجع دائري: لا يمكن لإدارة أن تكون سلفًا لنفسها.',
        'cannot_delete_default_calendar' => 'لا يمكن حذف التقويم الافتراضي للعمل.',
    ],
];
