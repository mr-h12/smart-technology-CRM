<?php

declare(strict_types=1);

return [
    'rejected' => [
        'unreadable' => 'The file could not be read. Please try uploading it again.',
        'empty' => 'The file is empty.',
        'too_large' => 'The file is larger than the allowed limit.',
        'unsupported_type' => 'This file type is not allowed. Allowed types: PDF, JPG, PNG, WEBP, DOCX, XLSX.',
        'corrupted' => 'The file appears to be incomplete or damaged.',
    ],
    // F-22 · 1.2 — `D-97`: `App\Support\Csv\ImportFileRequest`'s refusal.
    'import' => [
        'not_csv' => 'Only CSV files can be imported. In Excel, choose Save As → CSV UTF-8, then upload that file.',
    ],
];
