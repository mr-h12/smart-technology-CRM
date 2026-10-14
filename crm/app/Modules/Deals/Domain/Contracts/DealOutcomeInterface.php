<?php

declare(strict_types=1);

namespace App\Modules\Deals\Domain\Contracts;

/**
 * The deal moves a quotation causes (`D-90`, `D-119`), for Quotations to call
 * inside its own transaction — a rolled-back caller rolls these back too.
 * The caller has already authorised the quotation action, which is scoped
 * through this deal, so no deal permission is asked again.
 */
interface DealOutcomeInterface
{
    /**
     * `D-119`: a quotation created from the deal walks it, one §4.4 edge at a
     * time, up to `supplier_quotation`; a deal already there or later is left
     * where it is.
     */
    public function quotationCreated(string $dealId, string $actorId): void;

    /**
     * `supplier_quotation → quotation_sent`; a deal already at
     * `quotation_sent` or later is left where it is.
     *
     * @throws DealNotReadyToSend when the deal is before `supplier_quotation` (rule a)
     */
    public function quotationSent(string $dealId, string $actorId): void;

    /** `D-119`: the customer's Accept moves the deal to `won`; a deal with no `won` edge is left where it is. */
    public function quotationAccepted(string $dealId, string $actorId): void;

    /** `D-119`: Partial or Counter moves the deal to `negotiations`; a deal with no such edge (already there, or past it) is left where it is. */
    public function quotationNegotiated(string $dealId, string $actorId): void;

    /**
     * The deal goes `lost` with the reason. Returns false, and changes
     * nothing, when its status has no `lost` edge (rule b).
     */
    public function quotationRejected(string $dealId, string $reason, string $actorId): bool;
}
