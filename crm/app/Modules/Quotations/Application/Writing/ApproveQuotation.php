<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Writing;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Listing\QuotationDetail;
use App\Modules\Quotations\Domain\Listing\QuotationNotFound;
use App\Modules\Quotations\Domain\Status\QuotationStatusTransition;
use App\Modules\Quotations\Domain\Writing\QuotationEtag;
use App\Modules\Quotations\Domain\Writing\QuotationWriteRefused;
use Illuminate\Database\ConnectionInterface;

/**
 * `PATCH /quotations/{id}/approve` — Module 8 Point 1.1. §6.4's
 * `Pending ──approve──► Approved`, refused and guarded exactly as
 * {@see SubmitQuotation}: scope (404), `If-Match` (400, then 409
 * `concurrency_conflict`), then the edge table (409
 * `state_transition_invalid`), then the token-guarded SQL write.
 *
 * §6.5 / `D-50`: the approver may be the quotation's own author — "a
 * quotation they built themselves" is read as `created_by` (Q3) — on
 * condition of transparency: the row is flagged `is_self_approved` and the
 * audit entry is typed `SELF_APPROVAL` **instead of** `QUOTATION_APPROVED`,
 * so a query for ordinary approvals never finds it and a query for
 * self-approvals always does (§3.12 rule 4).
 */
final readonly class ApproveQuotation
{
    public function __construct(
        private QuotationDirectoryInterface $quotations,
        private QuotationWriteAccess $access,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  list<string>  $heldScopes  §3.2 codes on `quotation.approve`
     *
     * @throws QuotationNotFound
     * @throws QuotationWriteRefused
     */
    public function approve(string $quotationId, ?string $ifMatch, array $heldScopes, string $actorId): QuotationDetail
    {
        return $this->connection->transaction(function () use ($quotationId, $ifMatch, $heldScopes, $actorId): QuotationDetail {
            $before = $this->access->open($quotationId, $ifMatch, $heldScopes, $actorId);

            if (! QuotationStatusTransition::isAllowed($before->status, 'approved')) {
                throw QuotationWriteRefused::invalidTransition($before->status, 'approved');
            }

            $selfApproved = $before->createdBy === $actorId;

            if (! $this->quotations->approve($quotationId, $before->versionToken, $actorId, $selfApproved)) {
                throw QuotationWriteRefused::staleVersion(QuotationEtag::of($before));
            }

            $after = $this->access->reread($quotationId);

            $this->audit->record(
                $selfApproved ? AuditEvent::selfApproval() : AuditEvent::of('QUOTATION_APPROVED'),
                'quotation',
                $quotationId,
                ['status' => $before->status, 'is_self_approved' => $before->isSelfApproved],
                ['status' => $after->status, 'is_self_approved' => $after->isSelfApproved],
            );

            return $after;
        });
    }
}
