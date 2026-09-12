<?php

declare(strict_types=1);

return [
    // `OpenAPI §9.1` — مفاتيحه هي `IdempotencyRefused::$reason` (النقطة 3.7).
    'errors' => [
        'idempotency_key_required' => 'أرسل ترويسة Idempotency-Key فريدة مع هذا الطلب.',
        'idempotency_conflict' => 'استُخدم مفتاح Idempotency-Key هذا لطلب مختلف من قبل، أو أن ذلك الطلب ما زال قيد التنفيذ.',
    ],
];
