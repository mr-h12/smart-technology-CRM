<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Writing;

use RuntimeException;

/**
 * The ways a write to one quotation refuses a well-formed request — Module 7
 * Points 3.6 and 4.1 — each mapped to the `OpenAPI §5.1` row it belongs to.
 * One class with a reason, as `QuotationNotPriceable` is, because they share
 * a renderer and differ only in status, code and the detail entry.
 *
 * | reason | HTTP | `error.code` | why |
 * |---|---|---|---|
 * | `if_match_required` | 400 | `invalid_request` | "invalid header" — `§9.2` says a mutation sends the token; none, or not `quotation:<id>:<n>` |
 * | `stale_version` | 409 | `concurrency_conflict` | `DB-12`, `API-12` — the stored token moved; the detail carries the current etag, §5.1's "version metadata needed to refresh" |
 * | `quotation_not_draft` | 422 | `business_rule_blocked` | §3.5 `edit` is "(Draft)"; a submitted quotation is Module 8's to edit |
 * | `invalid_transition` | 409 | `state_transition_invalid` | §5.1 "requested state change violates the documented workflow" — no arrow in `QuotationStatusTransition` from the row's status to the one asked for |
 * | `version_exists` | 409 | `state_transition_invalid` | §6.3 / `DB-03` "one v2 per parent" — `quotations_version_unique_alive` refused a second copy; the workflow continues on the copy that exists |
 * | `rejection_reason_required` | 422 | `validation_failed` | §6.3 "rejection and counter reasons are mandatory"; `OpenAPI §7.2` names the code (Module 10 · 1.4) |
 *
 * Never 412: the document names 409 for this, and `API-12` is the row a
 * client is written against.
 */
final class QuotationWriteRefused extends RuntimeException
{
    public const IF_MATCH_REQUIRED = 'if_match_required';

    public const STALE_VERSION = 'stale_version';

    public const NOT_DRAFT = 'quotation_not_draft';

    public const INVALID_TRANSITION = 'invalid_transition';

    public const VERSION_EXISTS = 'version_exists';

    public const REASON_REQUIRED = 'rejection_reason_required';

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

    /**
     * `$from` and `$to` are named in the exception message only: the envelope
     * says which row was refused and the client already knows both statuses.
     */
    public static function invalidTransition(string $from, string $to): self
    {
        $refused = new self(self::INVALID_TRANSITION, 409, 'state_transition_invalid', 'status', null);
        $refused->message .= ' ('.$from.' → '.$to.')';

        return $refused;
    }

    public static function versionExists(): self
    {
        return new self(self::VERSION_EXISTS, 409, 'state_transition_invalid', 'parent_id', null);
    }

    public static function reasonRequired(): self
    {
        return new self(self::REASON_REQUIRED, 422, 'validation_failed', 'reason', null);
    }
}
