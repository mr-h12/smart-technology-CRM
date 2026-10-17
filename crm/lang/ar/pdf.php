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
        'tax' => 'ضريبة القيمة المضافة',
        'rounding' => 'التقريب',
        'final' => 'الإجمالي النهائي',
    ],

    'conditions' => [
        'currency' => 'عملة العرض: :currency.',
        'term' => ':name: :body',
        'validity' => 'صلاحية العرض: حتى تاريخ :date.',
        // D-107: the last term, on a taxed quotation only.
        'vat_included' => 'السعر شامل الضريبة.',
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

    // Module 13, slice 4 (`D-132`) — the stored PDF of a submitted report, Arabic only;
    // the English group is kept in step for the locale guards. The words are the report page's.
    'report' => [
        'title' => 'التقرير :code',
        'heading' => 'تقرير يومي',
        'heading_weekly' => 'تقرير أسبوعي',
        'meta' => [
            'version' => 'النسخة:',
            'period' => 'اليوم:',
            'period_weekly' => 'الأسبوع:',
            'author' => 'مقدَّم من:',
            'submitted_at' => 'وقت الإرسال:',
        ],
        'summary' => 'ملخص اليوم',
        'summary_weekly' => 'ملخص الأسبوع',
        'groups' => [
            'deals_created' => 'صفقات أُنشئت',
            'deal_status_moves' => 'تغييرات حالة الصفقات',
            'quotations_created' => 'عروض أسعار أُنشئت',
            'quotations_sent' => 'عروض أسعار أُرسلت',
            'customer_responses' => 'ردود العملاء',
            'purchase_orders' => 'أوامر شراء سجّلها',
        ],
        'columns' => [
            'code' => 'الرقم',
            'customer' => 'العميل',
            'total' => 'الإجمالي',
            'from' => 'من',
            'to' => 'إلى',
            'response' => 'الرد',
            'day' => 'اليوم',
            'status' => 'الحالة',
        ],
        'deal_status' => [
            'lead' => 'مبدئي',
            'contacted' => 'تم التواصل',
            'waiting_customer_request' => 'بانتظار طلب العميل',
            'supplier_rfq' => 'طلب عرض من مورّد',
            'supplier_quotation' => 'عرض المورّد',
            'quotation_sent' => 'أُرسل عرض السعر',
            'negotiations' => 'تفاوض',
            'won' => 'ناجحة',
            'purchasing' => 'شراء',
            'delivery' => 'توريد',
            'delivery_complete' => 'اكتمل التوريد',
            'lost' => 'خاسرة',
        ],
        'response' => [
            'accepted' => 'قبول',
            'rejected' => 'رفض',
            'partial' => 'قبول جزئي',
            'countered' => 'عرض مقابل',
        ],
        // `D-126`: a daily report's status in the weekly list — the SPA's `reports.status.*`.
        'status' => [
            'draft' => 'مسوّدة',
            'submitted' => 'مُرسَل',
            'viewed' => 'تمّ الاطّلاع',
            'acknowledged' => 'معتمَد',
            'returned' => 'مُرجَع',
            'missed' => 'فائت',
        ],
        // `D-126`, `D-134`: a weekly report's own sections.
        'weekly' => [
            'daily_reports' => 'التقارير اليومية لهذا الأسبوع',
            'no_daily_reports' => 'لا تقارير يومية في هذا الأسبوع.',
            'purchase_orders_received' => 'أوامر شراء وصلت على صفقاته',
            'no_purchase_orders' => 'لا أوامر شراء هذا الأسبوع.',
            'new_customers' => 'العملاء الجدد هذا الأسبوع',
            'no_new_customers' => 'لم يُضف أي عميل هذا الأسبوع.',
        ],
        'no_value' => '—',
        'none' => 'لا شيء مسجَّل.',
        'follow_ups' => 'التواصل والمتابعة',
        'follow_up_time' => 'الوقت',
        'note' => 'الملاحظة',
        'no_follow_ups' => 'لا متابعات.',
        'notes' => 'الملاحظات',
        'no_notes' => 'لا ملاحظات.',
    ],

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
