<?php

declare(strict_types=1);

// Module 13 — the server's report messages (`OpenAPI §5.1`). The screens'
// labels live in `resources/js/locales/*.json` under `reports`.
return [
    'not_found' => 'هذا التقرير غير موجود أو ليس لك.',

    'attributes' => [
        'type' => 'نوع التقرير',
        'notes' => 'الملاحظات',
        'reason' => 'السبب',
        'recipient_ids' => 'المستلمون',
        'customer_id' => 'العميل',
        'follow_up_note' => 'ملاحظة المتابعة',
        'note_body' => 'الملاحظة',
        'comment_body' => 'التعليق',
    ],

    'errors' => [
        'if_match_required' => 'أرسل نسخة التقرير الحاليّة في If-Match.',
        'stale_version' => 'تغيّر هذا التقرير منذ فتحته. أعد تحميله ثم حاول مجدّداً.',
        'recipient_not_allowed' => 'أحد المستلمين لا يمكنه استلام هذا التقرير.',
        'report_not_awaiting_answer' => 'لا يمكن اعتماد التقرير أو إرجاعه إلا وهو مُرسَل أو مُطَّلَع عليه.',
        'report_not_editable' => 'لم يعد ممكناً تعديل هذا التقرير: يُعدَّل وهو مسوّدة أو مُرجَع فقط.',
        'report_follow_up_not_allowed' => 'المتابعات للتقرير اليومي فقط؛ التقرير الأسبوعي يقبل الملاحظات.',
        'report_duplicate_period' => 'لديك تقرير يومي لهذا اليوم بالفعل.',
        'customer_not_allowed' => 'اختر عميلاً من عملائك.',
        'report_pdf_not_retryable' => 'لا تُعاد المحاولة إلا لملف PDF تعذّر تجهيزه.',
    ],
];
