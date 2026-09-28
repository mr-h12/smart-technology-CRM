<?php

declare(strict_types=1);

return [
    'rejected' => [
        'unreadable' => 'تعذّرت قراءة الملفّ. يُرجى رفعه مرّة أخرى.',
        'empty' => 'الملفّ فارغ.',
        'too_large' => 'حجم الملفّ يتجاوز الحدّ المسموح به.',
        'unsupported_type' => 'نوع الملفّ غير مسموح به. الأنواع المسموحة: PDF و JPG و PNG و WEBP و DOCX و XLSX.',
        'corrupted' => 'يبدو أنّ الملفّ ناقص أو تالف.',
    ],
    // F-22 · 1.2 — `D-97`: `App\Support\Csv\ImportFileRequest`'s refusal.
    'import' => [
        'not_csv' => 'يمكن استيراد ملفات CSV فقط. في Excel اختر حفظ باسم ← CSV UTF-8 ثم ارفع ذلك الملف.',
    ],
];
