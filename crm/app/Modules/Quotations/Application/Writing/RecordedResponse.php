<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Writing;

use App\Modules\Quotations\Domain\Listing\PurchaseOrderSummary;
use App\Modules\Quotations\Domain\Listing\QuotationDetail;
use App\Modules\Quotations\Domain\Listing\QuotationSummary;

/**
 * What {@see RespondToQuotation::respond()} hands back: the answered
 * quotation, the draft `partial` / `counter` opened (1.4), whether a
 * rejection made the deal `lost` (1.5, `D-90` rule b), and the purchase order
 * an acceptance wrote (1.6) — each null where that response has none.
 */
final readonly class RecordedResponse
{
    public function __construct(
        public QuotationDetail $quotation,
        public ?QuotationSummary $newVersion,
        public ?bool $dealLost,
        public ?PurchaseOrderSummary $purchaseOrder = null,
    ) {}
}
