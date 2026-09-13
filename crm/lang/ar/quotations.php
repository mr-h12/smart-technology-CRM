<?php

declare(strict_types=1);

return [
    // `OpenAPI §5.1` — one 404 for absent or out of reach (Point 3.5).
    'not_found' => 'لم يُعثر على عرض السعر هذا.',

    // `422 business_rule_blocked` — منع §5.6، وجاره الذي يفرضه `D-09`.
    // مفاتيحه هي `QuotationNotPriceable::$reason`؛ أكّد المالك
    // `fx_rate_missing` ككود مستقل في 2026-09-11.
    // `OpenAPI §6.1`/`§6.2` — `GET /quotations` (النقطة 5.2). رسالة الغلاف هي
    // النقطة 5.5 — مجموعة `null` في `group_by=employee`: صفقة بلا مالك.
    'groups' => [
        'unassigned' => 'غير مُسنَد',
    ],

    // `errors.invalid_request`؛ والسبب المحدّد في `details` أدناه.
    'list_query' => [
        'not_a_positive_integer' => 'يجب أن تكون هذه القيمة رقمًا صحيحًا أكبر من صفر.',
        'above_maximum' => 'حجم الصفحة أكبر من الحدّ المسموح به.',
        'unknown_parameter' => 'هذه القائمة لا توفّر هذا المُعامل.',
        'unknown_filter' => 'هذه القائمة لا توفّر هذا المرشّح.',
        'unknown_status' => 'هذه ليست حالة من حالات عرض السعر.',
        'unknown_bucket' => 'يجب أن تكون المجموعة active أو history.',
        'not_a_uuid' => 'قيمة هذا المرشّح ليست معرّفًا.',
        'not_a_code' => 'قيمة هذا المرشّح ليست رمز عملة.',
        'not_an_amount' => 'يجب أن يكون هذا المبلغ رقمًا غير سالب.',
        'currency_required' => 'مرشّحات المبلغ وترتيب الإجمالي تحتاج إلى filter[currency].',
        'not_a_date' => 'يجب أن يكون هذا التاريخ تاريخًا صالحًا بصيغة YYYY-MM-DD.',
        'after_to' => 'بداية نطاق التاريخ بعد نهايته.',
        'unknown_sort_field' => 'لا يمكن ترتيب هذه القائمة بهذا الحقل.',
        'repeated_sort_field' => 'لا يمكن استخدام الحقل نفسه أكثر من مرّة في الترتيب.',
        'unknown_group' => 'لا يمكن تجميع هذه القائمة إلا حسب الموظف أو العميل.',
    ],

    'errors' => [
        'invalid_request' => 'تعذّر فهم هذا الطلب.',
        'supplier_price_missing' => 'لا يوجد سعر صالح لبند المورّد هذا، لذا لا يمكن حفظ عرض السعر.',
        'fx_rate_missing' => 'لا يوجد سعر صرف مسجَّل لتحويل بند المورّد هذا. سجِّل سعر الصرف أولًا.',
        // `PATCH /quotations/{id}` — `QuotationWriteRefused` (النقطة 3.6).
        'if_match_required' => 'أرسل الـ etag الحالي لعرض السعر في If-Match.',
        'stale_version' => 'عدّل شخص آخر عرض السعر هذا. أعد تحميله ثم طبّق تعديلك مجددًا.',
        'quotation_not_draft' => 'لا يمكن التعديل هنا إلا على عرض سعر في حالة مسودة.',
        // `QuotationStatusTransition` (النقطة 4.1) — أسهم §6.4، `OpenAPI §5.1` 409.
        'invalid_transition' => 'لا يمكن نقل عرض السعر هذا إلى هذه الحالة من حيث هو الآن.',
        'version_exists' => 'توجد نسخة أحدث من عرض السعر هذا بالفعل. أكمل العمل عليها.',
    ],

    // تحذير §5.6 — يُحمل في `meta.warnings` عند إنشاء ناجح.
    'warnings' => [
        'quantity_exceeds_recorded' => 'الكمية المطلوبة تتجاوز ما سجّله المورّد.',
        'supplier_price_changed' => 'تغيّر سعر المورّد — راجع التسعير.',
    ],

    // `422 validation_failed` تصدره حالة الاستخدام لا قاعدة تحقق.
    'validation' => [
        'unknown_deal' => 'لم يُعثر على هذه الصفقة.',
        'customer_not_the_deals' => 'يجب أن يكون العميل هو عميل الصفقة.',
    ],
];
