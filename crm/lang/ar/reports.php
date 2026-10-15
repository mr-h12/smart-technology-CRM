<?php

declare(strict_types=1);

// Module 13 — the server's report messages (`OpenAPI §5.1`). The screens'
// labels live in `resources/js/locales/*.json` under `reports`.
return [
    'not_found' => 'هذا التقرير غير موجود أو ليس لك.',

    'attributes' => [
        'type' => 'نوع التقرير',
        'notes' => 'الملاحظات',
        'recipient_ids' => 'المستلمون',
    ],

    'errors' => [
        'if_match_required' => 'أرسل نسخة التقرير الحاليّة في If-Match.',
        'stale_version' => 'تغيّر هذا التقرير منذ فتحته. أعد تحميله ثم حاول مجدّداً.',
        'report_not_draft' => 'لا يمكن تعديل التقرير إلا وهو مسوّدة.',
        'recipient_not_allowed' => 'أحد المستلمين لا يمكنه استلام هذا التقرير.',
    ],
];
