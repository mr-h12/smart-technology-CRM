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

    // D-100: the heading over the company's name, and the parties block.
    'heading' => 'عرض سعر',

    'header' => [
        'attention' => 'عناية',
        'subject' => 'الموضوع',
    ],

    'parties' => [
        'prepared_for' => 'مقدم إلى',
        'issue_date' => 'تاريخ الإصدار',
        'valid_until' => 'صالح حتى',
    ],

    'intro' => 'يسرّنا أن نتقدم إليكم بعرض الأسعار التالي:',

    'table' => [
        'serial' => '#',
        'item' => 'الوصف',
        'unit_price' => 'سعر الوحدة',
        'quantity' => 'الكمية',
        'line_total' => 'الإجمالي',
    ],

    'terms' => [
        'heading' => 'الشروط والأحكام',
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

    'footer' => [
        'page' => 'صفحة :current من :total',
    ],

    'closing' => 'يسعدنا تواصلكم معنا لأي استفسار.',
    'regards' => 'مع خالص التحية،',

    'attributes' => [
        'locale' => 'لغة المستند',
    ],

    'errors' => [
        'incomplete' => [
            'company_name' => 'لا يمكن إنشاء ملف PDF للعميل بعد: اسم الشركة غير مُعرَّف في إعدادات النظام.',
            'customer_name' => 'لا يمكن إنشاء ملف PDF للعميل بعد: عميل عرض السعر ليس له اسم يُطبع.',
            'line_descriptions' => 'لا يمكن إنشاء ملف PDF للعميل بعد: البنود :lines ليس لها وصف يُطبع.',
        ],
    ],
];
