<?php

return [
    'exceptions' => [
        'manual_assignment_required' => 'المرحلة \':name\' تتطلب تعيينًا يدويًا ولكن لم يتم توفير أي منها.',
        'manual_assignment_required_sub' => 'المرحلة الفرعية \':name\' تتطلب تعيينًا يدويًا ولكن لم يتم توفير أي منها.',
        'invalid_sub_stage_return_target' => 'هدف الإرجاع للمرحلة الفرعية غير صالح: يجب أن يكون مرحلة فرعية سابقة في نفس المرحلة الأم.',
        'invalid_return_target' => 'هدف الإرجاع غير صالح: لم يتم تعريف انتقال إرجاع لهذه المرحلة.',
        'blueprint_not_active' => 'نموذج العمل غير نشط.',
        'blueprint_has_no_stages' => 'نموذج العمل لا يحتوي على مراحل محددة.',
        'assignee_not_found_for_override' => 'المستخدم ليس مفوضًا نشطًا يمكن تجاوزه.',
        'required_sub_stages_incomplete' => 'لا يمكن إكمال المرحلة: لم يتم إكمال جميع المراحل الفرعية المطلوبة.',
        'invalid_task_state_transition' => 'انتقال حالة المهمة غير صالح.',
        'stage_not_active' => 'مثيل المرحلة ليس في حالة نشطة.',
        'sub_stage_not_active' => 'مثيل المرحلة الفرعية ليس في حالة نشطة.',
        'user_not_assignee' => 'المستخدم ليس مفوضًا نشطًا لهذه المرحلة.',
        'task_not_suspended' => 'المهمة ليست في حالة معلقة.',
        'task_not_draft' => 'المهمة ليست في حالة مسودة.',
        'task_not_active' => 'المهمة ليست في حالة نشطة.',
        'task_already_cancelled' => 'المهمة ملغاة بالفعل.',
        'unresolvable_assignment' => 'لا يمكن تحديد المفوض لهذه المرحلة.',
        'invalid_comment_parent' => 'يجب أن يكون التعليق الأصلي تعليقًا رئيسيًا في نفس المهمة.',
        'comment_not_found' => 'التعليق غير موجود.',
        'external_entity_not_found' => 'الجهة الخارجية غير موجودة.',
        'external_entity_inactive' => 'الجهة الخارجية غير نشطة ولا يمكن استخدامها للإشارات الجديدة.',
        'external_reference_not_found' => 'الإشارة الخارجية غير موجودة.',
        'task_not_visible' => 'ليس لديك حق الوصول إلى هذه المهمة.',
    ],
];
