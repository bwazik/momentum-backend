<?php

return [
    'exceptions' => [
        'user_already_deactivated' => 'المستخدم غير نشط بالفعل.',
        'user_already_active' => 'المستخدم نشط بالفعل.',
        'primary_position_already_assigned' => 'المستخدم لديه تعيين منصب رئيسي نشط بالفعل. قم بإنهاء التعيين الحالي أولاً.',
        'duplicate_grant' => 'يوجد :type نشط بهذه المعايير بالفعل.',
        'cannot_revoke_system_capability_key' => 'لا يمكن تعديل مفاتيح الصلاحية المحددة من قبل النظام.',
        'cannot_delegate_to_self' => 'لا يمكن تفويض الصلاحية لنفسك.',
        'delegation_scope_mismatch' => 'نطاق التفويض يفتقر إلى الحقول المطلوبة أو يحتوي على معرفات غير صالحة.',
        'invalid_governance_scope' => 'تكوين نطاق الحوكمة غير صالح.',
    ],
];
