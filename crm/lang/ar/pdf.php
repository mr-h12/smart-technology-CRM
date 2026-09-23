<?php

declare(strict_types=1);

/*
 * Module 9 — كل ما يقرأه العميل في ملف العرض (§14.6، `D-89`).
 *
 * ⚠️ Drafted by the agent and **awaiting the owner's correction** (Step 2's Q8):
 * the owner writes the Arabic the company actually uses, and the module's
 * Arabic acceptance criterion stays open until he has.
 */
return [
    'title' => 'عرض سعر :code',

    'header' => [
        'date' => 'التاريخ',
        'to' => 'إلى',
        'attention' => 'عناية',
        'subject' => 'الموضوع',
    ],

    'intro' => 'يسرّنا أن نتقدم إليكم بعرض الأسعار التالي:',

    'table' => [
        'serial' => 'م',
        'item' => 'الصنف',
        'unit_price' => 'سعر الوحدة',
        'quantity' => 'الكمية',
        'line_total' => 'الإجمالي',
    ],

    'totals' => [
        'subtotal' => 'الإجمالي قبل الخصم',
        'additional' => 'التوصيل والتركيب',
        'discount' => 'الخصم (:percent%)',
        'tax_base' => 'الوعاء الضريبي',
        'tax' => 'ضريبة القيمة المضافة (:percent%)',
        'net' => 'الصافي',
        'rounding' => 'التقريب',
        'final' => 'الإجمالي النهائي',
    ],

    'conditions' => [
        'currency' => 'عملة العرض: :currency.',
        'payment' => 'الدفع: :terms',
        'warranty' => 'الضمان: :terms',
        'delivery' => 'التسليم: :terms',
        'validity' => 'صلاحية العرض: حتى تاريخ :date.',
    ],

    'closing' => 'يسعدنا تواصلكم معنا لأي استفسار.',
    'regards' => 'مع خالص التحية،',
];
