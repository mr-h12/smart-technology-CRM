<?php

declare(strict_types=1);

namespace App\Modules\Idempotency\Domain;

use RuntimeException;

/**
 * The two ways `Idempotency-Key` refuses a request, each on its `OpenAPI §5.1`
 * row. One class with a reason, as `QuotationWriteRefused` is, because the
 * two share a renderer and differ only in status and code.
 *
 * | reason | HTTP | `error.code` | why |
 * |---|---|---|---|
 * | `idempotency_key_required` | 400 | `invalid_request` | §3.1 marks the header required on critical creates; absent is "invalid header" |
 * | `idempotency_conflict` | 409 | `idempotency_conflict` | §9.1: the key was reused with a changed payload — or is still being processed |
 */
final class IdempotencyRefused extends RuntimeException
{
    public const KEY_REQUIRED = 'idempotency_key_required';

    public const CONFLICT = 'idempotency_conflict';

    private function __construct(
        public readonly string $reason,
        public readonly int $status,
        public readonly string $errorCode,
        public readonly string $field,
    ) {
        parent::__construct('Idempotency refused: '.$reason);
    }

    public static function keyRequired(): self
    {
        return new self(self::KEY_REQUIRED, 400, 'invalid_request', 'Idempotency-Key');
    }

    public static function conflict(): self
    {
        return new self(self::CONFLICT, 409, 'idempotency_conflict', 'Idempotency-Key');
    }
}
