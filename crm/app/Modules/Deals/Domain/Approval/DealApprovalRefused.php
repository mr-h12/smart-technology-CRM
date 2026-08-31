<?php

declare(strict_types=1);

namespace App\Modules\Deals\Domain\Approval;

use RuntimeException;

/**
 * `OpenAPI §5.1` — `409 state_transition_invalid`: "Requested state change
 * violates the documented workflow."
 *
 * Flow 3 gives `approval_status` exactly one decision point: a `pending`
 * request is approved or rejected. Nothing describes re-deciding an
 * `approved` or `rejected` request, or deciding one that was never submitted
 * for approval (`NULL` — a Team-Leader-entered deal, Flow 1). Both are
 * refused here rather than treated as an idempotent repeat the way
 * `ArchiveCustomer` treats archiving an already-archived row: Flow 7
 * describes a select-all restore where "already active" is the ordinary
 * case, and no source describes an ordinary case for re-deciding an
 * approval.
 */
final class DealApprovalRefused extends RuntimeException
{
    public const ERROR_CODE = 'state_transition_invalid';

    private function __construct(public readonly string $dealId, public readonly ?string $currentApprovalStatus)
    {
        parent::__construct(
            'Deal '.$dealId.' cannot be approved or rejected from approval_status '
            .($currentApprovalStatus ?? 'null').'.',
        );
    }

    public static function notPending(string $dealId, ?string $currentApprovalStatus): self
    {
        return new self($dealId, $currentApprovalStatus);
    }

    public function messageKey(): string
    {
        return $this->currentApprovalStatus === null
            ? 'deals.approval.not_submitted'
            : 'deals.approval.already_decided';
    }
}
