<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Writing;

use App\Modules\Deals\Domain\Contracts\DealFactsInterface;
use App\Modules\Quotations\Domain\Access\QuotationRowScope;
use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Listing\QuotationDetail;
use App\Modules\Quotations\Domain\Listing\QuotationNotFound;
use App\Modules\Quotations\Domain\Writing\QuotationEtag;
use App\Modules\Quotations\Domain\Writing\QuotationWriteRefused;

/**
 * The preamble every mutation of one quotation shares, in the order
 * {@see UpdateQuotation} fixed and for its reasons — scope (`SEC-08`, 404),
 * then `If-Match` (400), then the stored token (`API-12`, 409) — and the
 * re-read that answers the call. Written once here so Points 3.6 and 4.2 (and
 * the actions Modules 8–10 add) refuse the same request the same way.
 *
 * Call it inside the use case's transaction: the row read here is the row
 * the guarded write compares against.
 */
final readonly class QuotationWriteAccess
{
    public function __construct(
        private QuotationDirectoryInterface $quotations,
        private DealFactsInterface $deals,
    ) {}

    /**
     * The quotation as stored, once the caller may reach it and holds its
     * current token — `$detail->versionToken` is the token the write must
     * guard on.
     *
     * @param  list<string>  $heldScopes  §3.2 codes on the action's permission
     *
     * @throws QuotationNotFound
     * @throws QuotationWriteRefused `if_match_required`, `stale_version`
     */
    public function open(string $quotationId, ?string $ifMatch, array $heldScopes, string $actorId): QuotationDetail
    {
        $scope = QuotationRowScope::resolve($heldScopes, $actorId);
        $before = $this->quotations->find($quotationId);

        if (! $before instanceof QuotationDetail
            || $scope->permitsNothing()
            || (! $scope->unrestricted && ! $scope->reaches($this->deals->factsOf($before->dealId)?->ownerId))) {
            throw QuotationNotFound::of($quotationId);
        }

        if (QuotationEtag::tokenFrom($ifMatch, $quotationId) !== $before->versionToken) {
            throw QuotationWriteRefused::staleVersion(QuotationEtag::of($before));
        }

        return $before;
    }

    /**
     * The row after the write, inside the same transaction.
     *
     * @throws QuotationNotFound a row that vanished between two statements of one transaction is a defect, and a silent 200 would hide it
     */
    public function reread(string $quotationId): QuotationDetail
    {
        $after = $this->quotations->find($quotationId);

        if (! $after instanceof QuotationDetail) {
            throw QuotationNotFound::of($quotationId);
        }

        return $after;
    }
}
