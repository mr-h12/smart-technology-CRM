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
 * `PATCH /quotations/{id}/submit-for-approval` — Module 7 Point 4.2. §6.4's
 * first arrow, `Draft ──submit──► Pending`, as the first route through Point
 * 4.1's edge table.
 *
 * {@see QuotationWriteAccess} refuses first — scope (404), `If-Match` (400,
 * then 409 `concurrency_conflict`) — and only then the transition:
 * `409 state_transition_invalid` when the graph draws no arrow from the row's
 * status to `pending`. The stale check comes first so a client holding an old
 * copy is told to reload, not told about a status it has not seen. The write
 * is guarded again in SQL
 * ({@see QuotationDirectoryInterface::moveStatus()}), so two submits that both
 * passed the token check cannot both commit.
 *
 * `QUOTATION_SUBMITTED` (`AUD-01`) records the pair that moved — status and
 * `submitted_at` — not the whole header: nothing else changes on a submit.
 */
final readonly class SubmitQuotation
{
    public function __construct(
        private QuotationDirectoryInterface $quotations,
        private QuotationWriteAccess $access,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  list<string>  $heldScopes  §3.2 codes on `quotation.submit_for_approval`
     *
     * @throws QuotationNotFound
     * @throws QuotationWriteRefused
     */
    public function submit(string $quotationId, ?string $ifMatch, array $heldScopes, string $actorId): QuotationDetail
    {
        return $this->connection->transaction(function () use ($quotationId, $ifMatch, $heldScopes, $actorId): QuotationDetail {
            $before = $this->access->open($quotationId, $ifMatch, $heldScopes, $actorId);

            if (! QuotationStatusTransition::isAllowed($before->status, 'pending')) {
                throw QuotationWriteRefused::invalidTransition($before->status, 'pending');
            }

            if (! $this->quotations->moveStatus($quotationId, 'pending', $before->versionToken, $actorId, ['submitted_at' => now()])) {
                throw QuotationWriteRefused::staleVersion(QuotationEtag::of($before));
            }

            $after = $this->access->reread($quotationId);

            $this->audit->record(
                AuditEvent::of('QUOTATION_SUBMITTED'),
                'quotation',
                $quotationId,
                ['status' => $before->status, 'submitted_at' => $before->submittedAt],
                ['status' => $after->status, 'submitted_at' => $after->submittedAt],
            );

            return $after;
        });
    }
}
