<?php

declare(strict_types=1);

return [
    // `OpenAPI §9.1` — keyed by `IdempotencyRefused::$reason` (Module 7 Point 3.7).
    'errors' => [
        'idempotency_key_required' => 'Send a unique Idempotency-Key header with this request.',
        'idempotency_conflict' => 'This Idempotency-Key was already used for a different request, or that request is still running.',
    ],
];
