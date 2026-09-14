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
 * `PATCH /quotations/{id}/return` — Module 8 Point 1.2. §6.4's
 * `Pending ──return with note──► Draft`, refused and guarded exactly as
 * {@see ApproveQuotation}.
 *
 * The owner's Q2 ruling (#131): the **same row** goes back to `draft` —
 * no copy, no Returned status — with `returned_at` and the mandatory note
 * written on it and `submitted_at` cleared, so `D-11`'s "days waiting"
 * restarts when the draft is resubmitted. The pending snapshot survives in
 * the audit row's old values, not as a second row. `QUOTATION_RETURNED`
 * (`AUD-01`) carries the note beside the status pair.
 */
final readonly class ReturnQuotation
{
    public function __construct(
        private QuotationDirectoryInterface $quotations,
        private QuotationWriteAccess $access,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  list<string>  $heldScopes  §3.2 codes on `quotation.return_with_note`
     *
     * @throws QuotationNotFound
     * @throws QuotationWriteRefused
     */
    public function return(string $quotationId, string $note, ?string $ifMatch, array $heldScopes, string $actorId): QuotationDetail
    {
        return $this->connection->transaction(function () use ($quotationId, $note, $ifMatch, $heldScopes, $actorId): QuotationDetail {
            $before = $this->access->open($quotationId, $ifMatch, $heldScopes, $actorId);

            if (! QuotationStatusTransition::isAllowed($before->status, 'draft')) {
                throw QuotationWriteRefused::invalidTransition($before->status, 'draft');
            }

            $moved = $this->quotations->moveStatus($quotationId, 'draft', $before->versionToken, $actorId, [
                'submitted_at' => null,
                'returned_at' => now(),
                'return_note' => $note,
            ]);

            if (! $moved) {
                throw QuotationWriteRefused::staleVersion(QuotationEtag::of($before));
            }

            $after = $this->access->reread($quotationId);

            $this->audit->record(
                AuditEvent::of('QUOTATION_RETURNED'),
                'quotation',
                $quotationId,
                ['status' => $before->status, 'submitted_at' => $before->submittedAt, 'return_note' => $before->returnNote],
                ['status' => $after->status, 'submitted_at' => $after->submittedAt, 'return_note' => $after->returnNote],
            );

            return $after;
        });
    }
}
