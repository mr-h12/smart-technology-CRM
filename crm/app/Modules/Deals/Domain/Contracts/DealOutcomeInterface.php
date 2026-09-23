<?php

declare(strict_types=1);

namespace App\Modules\Deals\Domain\Contracts;

/**
 * The two deal moves a quotation causes (`D-90`), for Quotations to call
 * inside its own transaction — a rolled-back caller rolls these back too.
 * The caller has already authorised the quotation action, which is scoped
 * through this deal, so no deal permission is asked again.
 */
interface DealOutcomeInterface
{
    /**
     * `supplier_quotation → quotation_sent`; a deal already at
     * `quotation_sent` or later is left where it is.
     *
     * @throws DealNotReadyToSend when the deal is before `supplier_quotation` (rule a)
     */
    public function quotationSent(string $dealId, string $actorId): void;

    /**
     * The deal goes `lost` with the reason. Returns false, and changes
     * nothing, when its status has no `lost` edge (rule b).
     */
    public function quotationRejected(string $dealId, string $reason, string $actorId): bool;
}
