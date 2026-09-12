<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Writing;

use RuntimeException;

/**
 * The three ways `PATCH /quotations/{id}` refuses a well-formed body —
 * Module 7 Point 3.6 — each mapped to the `OpenAPI §5.1` row it belongs to.
 * One class with a reason, as `QuotationNotPriceable` is, because the three
 * share a renderer and differ only in status, code and the detail entry.
 *
 * | reason | HTTP | `error.code` | why |
 * |---|---|---|---|
 * | `if_match_required` | 400 | `invalid_request` | "invalid header" — `§9.2` says a mutation sends the token; none, or not `quotation:<id>:<n>` |
 * | `stale_version` | 409 | `concurrency_conflict` | `DB-12`, `API-12` — the stored token moved; the detail carries the current etag, §5.1's "version metadata needed to refresh" |
 * | `quotation_not_draft` | 422 | `business_rule_blocked` | §3.5 `edit` is "(Draft)"; a submitted quotation is Module 8's to edit |
 *
 * Never 412: the document names 409 for this, and `API-12` is the row a
 * client is written against.
 */
final class QuotationWriteRefused extends RuntimeException
{
    public const IF_MATCH_REQUIRED = 'if_match_required';

    public const STALE_VERSION = 'stale_version';

    public const NOT_DRAFT = 'quotation_not_draft';

    private function __construct(
        public readonly string $reason,
        public readonly int $status,
        public readonly string $errorCode,
        public readonly string $field,
        public readonly ?string $currentEtag,
    ) {
        parent::__construct('Quotation write refused: '.$reason);
    }

    public static function ifMatchRequired(): self
    {
        return new self(self::IF_MATCH_REQUIRED, 400, 'invalid_request', 'If-Match', null);
    }

    public static function staleVersion(string $currentEtag): self
    {
        return new self(self::STALE_VERSION, 409, 'concurrency_conflict', 'If-Match', $currentEtag);
    }

    public static function notDraft(): self
    {
        return new self(self::NOT_DRAFT, 422, 'business_rule_blocked', 'status', null);
    }
}
