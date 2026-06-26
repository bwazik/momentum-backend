<?php

return [
    'exceptions' => [
        'duplicate_open_escalation' => 'يوجد طلب تصعيد مفتوح بالفعل لهذه المرحلة من هذا المستخدم.',
        'sla_timer_not_active' => 'مؤقت SLA ليس في حالة قابلة للتنفيذ.',
        'sla_timer_already_exists' => 'يوجد مؤقت SLA نشط بالفعل لمثيل هذه المرحلة.',
        'sla_policy_missing' => 'سياسة SLA غير موجودة للمرحلة/المرحلة الفرعية.',
        'escalation_target_not_found' => 'لم يتم العثور على هدف تصعيد لهذه المرحلة.',
        'escalation_resolution_unauthorized' => 'ليس لديك الصلاحية لحل طلب التصعيد هذا.',
        'escalation_already_resolved' => 'تم حل طلب التصعيد هذا بالفعل.',
    ],
];
