<?php

declare(strict_types=1);

namespace App\Modules\Reports\Domain;

use RuntimeException;

/**
 * `OpenAPI §5.1`'s 400 / 409 / 422 for a report write, on
 * `QuotationWriteRefused`'s table: each refusal carries its status, its
 * envelope code, the field it names and, on a stale write, the current etag.
 */
final class ReportWriteRefused extends RuntimeException
{
    public const IF_MATCH_REQUIRED = 'if_match_required';

    public const STALE_VERSION = 'stale_version';

    public const NOT_DRAFT = 'report_not_draft';

    public const RECIPIENT_NOT_ALLOWED = 'recipient_not_allowed';

    public const NOT_AWAITING_ANSWER = 'report_not_awaiting_answer';

    public const NOT_EDITABLE = 'report_not_editable';

    public const CUSTOMER_NOT_ALLOWED = 'customer_not_allowed';

    public const FOLLOW_UP_NOT_ALLOWED = 'report_follow_up_not_allowed';

    private function __construct(
        public readonly string $reason,
        public readonly int $status,
        public readonly string $errorCode,
        public readonly string $field,
        public readonly ?string $currentEtag,
    ) {
        parent::__construct('Report write refused: '.$reason);
    }

    public static function ifMatchRequired(): self
    {
        return new self(self::IF_MATCH_REQUIRED, 400, 'invalid_request', 'If-Match', null);
    }

    public static function staleVersion(string $currentEtag): self
    {
        return new self(self::STALE_VERSION, 409, 'concurrency_conflict', 'If-Match', $currentEtag);
    }

    /** §11.4: past Draft, the content is the frozen copy's. */
    public static function notDraft(): self
    {
        return new self(self::NOT_DRAFT, 422, 'business_rule_blocked', 'status', null);
    }

    /** `D-131`: a follow-up or note is written only while the report is a draft or returned. */
    public static function notEditable(): self
    {
        return new self(self::NOT_EDITABLE, 409, 'state_transition_invalid', 'status', null);
    }

    /** `D-131`: a follow-up's customer is one the author reaches under `customer.view` — never a hint that another exists. */
    public static function customerNotAllowed(): self
    {
        return new self(self::CUSTOMER_NOT_ALLOWED, 422, 'validation_failed', 'customer_id', null);
    }

    /** `D-137`: follow-ups belong to the daily report (`D-131`); on any other type the write is refused, not hidden. */
    public static function followUpNotAllowed(): self
    {
        return new self(self::FOLLOW_UP_NOT_ALLOWED, 422, 'business_rule_blocked', 'type', null);
    }

    /** §11.4: only a Submitted or Viewed report is acknowledged or returned; Acknowledged is final. */
    public static function notAwaitingAnswer(): self
    {
        return new self(self::NOT_AWAITING_ANSWER, 422, 'business_rule_blocked', 'status', null);
    }

    /** `D-127`: recipients come from the author's candidates only. */
    public static function recipientNotAllowed(): self
    {
        return new self(self::RECIPIENT_NOT_ALLOWED, 422, 'validation_failed', 'recipient_ids', null);
    }
}
