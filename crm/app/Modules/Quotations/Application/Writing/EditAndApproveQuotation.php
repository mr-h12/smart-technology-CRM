<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Writing;

use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Quotations\Domain\Listing\QuotationNotFound;
use App\Modules\Quotations\Domain\Status\QuotationStatusTransition;
use App\Modules\Quotations\Domain\Writing\QuotationEtag;
use App\Modules\Quotations\Domain\Writing\QuotationWriteRefused;
use Illuminate\Database\ConnectionInterface;

/**
 * `PATCH /quotations/{id}/edit-and-approve` — Module 8 Point 1.3. §6.4's
 * `Pending ──edit + approve──► Approved`: the approver "may edit tax,
 * margin, or any field — and every edit is written to the audit log".
 *
 * The owner's Q4 ruling (#131): 6.7's body, one transaction, and nothing
 * new to price or guard — {@see UpdateQuotation} re-prices the pending row
 * exactly as it re-prices a draft (`edit_margin` / `edit_tax` asked the
 * same way, `QUOTATION_UPDATED` old → new), then {@see ApproveQuotation}
 * moves it (`QUOTATION_APPROVED` or `SELF_APPROVAL`, §6.5). The two inner
 * transactions become savepoints of this one, so a refused approval also
 * undoes the edit. §6.4's arrow is checked here first, so a Draft is 1.1's
 * `409 state_transition_invalid` and not 3.6's `422 quotation_not_draft`.
 */
final readonly class EditAndApproveQuotation
{
    public function __construct(
        private UpdateQuotation $update,
        private ApproveQuotation $approve,
        private QuotationWriteAccess $access,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  `SaveQuotationRequest::validated()`
     * @param  list<string>  $heldScopes  §3.2 codes on `quotation.approve`
     *
     * @throws QuotationNotFound
     * @throws QuotationWriteRefused
     * @throws AuthorizationRefused
     */
    public function approve(string $quotationId, array $validated, ?string $ifMatch, array $heldScopes, string $actorId): QuotationUpdated
    {
        return $this->connection->transaction(function () use ($quotationId, $validated, $ifMatch, $heldScopes, $actorId): QuotationUpdated {
            // ponytail: this read repeats `UpdateQuotation`'s — one extra row read
            // per call, so a Draft is refused with §6.4's 409 and not 3.6's 422.
            // Thread `$before` through `update()` instead if this path gets hot.
            $before = $this->access->open($quotationId, $ifMatch, $heldScopes, $actorId);

            if (! QuotationStatusTransition::isAllowed($before->status, 'approved')) {
                throw QuotationWriteRefused::invalidTransition($before->status, 'approved');
            }

            $edited = $this->update->update($quotationId, $validated, $ifMatch, $heldScopes, $actorId, 'pending');

            // The edit moved the token; the approval guards on the one it left.
            $approved = $this->approve->approve($quotationId, QuotationEtag::of($edited->quotation), $heldScopes, $actorId);

            return new QuotationUpdated($approved, $edited->quantityWarnings);
        });
    }
}
