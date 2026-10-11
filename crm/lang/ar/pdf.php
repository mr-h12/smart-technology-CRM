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

    // D-107: the centred title, and the info block under it.
    'heading' => 'عرض تجاري',

    'info' => [
        'offer_no' => 'رقم العرض:',
        'issue_date' => 'تاريخ الإصدار:',
        'prepared_for' => 'مقدم إلى:',
        'attention' => 'عناية:',
        // D-104: the fixed «أ.» whatever the stored title.
        'attention_name' => 'أ. :name',
        'subject' => 'الموضوع:',
    ],

    'intro' => 'يسرّنا أن نتقدم إليكم بعرض الأسعار التالي:',

    'table' => [
        'description' => 'الوصف',
        'unit' => 'الوحدة',
        'quantity' => 'الكمية',
        'unit_price' => 'سعر الوحدة',
        'vat' => 'الضريبة',
        'total' => 'الإجمالي',
    ],

    'terms' => [
        'heading' => 'الشروط والأحكام',
        // D-103: printed for a ready term the employee left unnamed.
        'labels' => [
            'payment_terms' => 'الدفع',
            'warranty' => 'الضمان',
            'delivery_terms' => 'التسليم',
        ],
    ],

    'totals' => [
        'excl_vat' => 'الإجمالي غير شامل الضريبة',
        'discount' => 'الخصم',
        'tax' => 'ضريبة القيمة المضافة :percent%',
        'rounding' => 'التقريب',
        'final' => 'الإجمالي النهائي',
    ],

    'conditions' => [
        'currency' => 'عملة العرض: :currency.',
        'term' => ':name: :body',
        'validity' => 'صلاحية العرض: حتى تاريخ :date.',
        // D-107: the last term, on a taxed quotation only.
        'vat_included' => 'السعر يشمل ضريبة القيمة المضافة.',
    ],

    'footer' => [
        // D-107: the footer is English on the Arabic page too.
        'page' => 'Page :current of :total',
        'address' => 'Address:',
        'tel' => 'Tel:',
        'email' => 'Email:',
    ],

    'closing' => 'يسعدنا تواصلكم معنا لأي استفسار.',
    'regards' => 'مع خالص التحية،',
    'signature' => 'توقيع المبيعات',

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
