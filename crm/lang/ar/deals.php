<?php

declare(strict_types=1);

return [
    'errors' => [
        'invalid_request' => 'تعذّر فهم هذا الطلب.',
    ],

    'not_found' => 'لم يُعثر على هذه الصفقة.',

    'list_query' => [
        'not_a_positive_integer' => 'يجب أن تكون هذه القيمة رقمًا صحيحًا أكبر من صفر.',
        'above_maximum' => 'حجم الصفحة أكبر من الحدّ المسموح به.',
        'unknown_sort_field' => 'لا يمكن ترتيب هذه القائمة بهذا الحقل.',
        'repeated_sort_field' => 'لا يمكن استخدام الحقل نفسه أكثر من مرّة في الترتيب.',
        'unknown_filter' => 'هذه القائمة لا توفّر هذا المرشّح.',
        'not_a_code' => 'قيمة هذا المرشّح ليست رمزًا صالحًا.',
        'not_a_string' => 'يجب أن يكون نص البحث كتابةً.',
    ],

    'validation' => [
        'unknown_owner' => 'هذا المالك ليس مستخدمًا في هذا النظام.',
        'status_is_derived' => 'تتغيّر حالة الصفقة عبر إجراء الحالة، ولا يمكن ضبطها هنا.',
        'approval_is_derived' => 'حالة الموافقة يحدّدها النظام حسب من أنشأ الطلب، ولا يمكن تعديلها هنا.',
        'code_is_generated' => 'يُنشأ رمز الصفقة تلقائيًا ولا يمكن ضبطه هنا.',
        'customer_is_fixed_at_creation' => 'يُحدَّد عميل الصفقة عند إنشائها ولا يمكن تغييره هنا.',
        'assign_has_its_own_action' => 'نقل الصفقة إلى مالك آخر إجراء منفصل، وليس حقلًا في هذا النموذج.',
    ],

    'attributes' => [
        'customer_id' => 'العميل',
        'title' => 'العنوان',
        'source' => 'المصدر',
        'service_type' => 'نوع الخدمة',
        'owner_id' => 'المالك',
        'code' => 'الرمز',
        'status' => 'الحالة',
        'approval_status' => 'حالة الموافقة',
        'rejection_reason' => 'سبب الرفض',
        'last_activity_at' => 'آخر نشاط',
    ],
];
