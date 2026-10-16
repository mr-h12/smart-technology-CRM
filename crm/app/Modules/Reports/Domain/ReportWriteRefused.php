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
