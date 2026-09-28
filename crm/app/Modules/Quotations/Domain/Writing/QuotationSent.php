<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Writing;

/**
 * F-31 · 1.3 — a quotation went `approved → sent` and the send committed.
 *
 * `D-90` sends without waiting for the PDF and leaves "connecting the two" to
 * Module 9, which listens for this (its Q14: one generation, in the sender's
 * name). Published in `QuotationsContract`; dispatched only after the
 * transaction, so a rolled-back send announces nothing.
 *
 * Ids only, as `AccountLocked` carries: a listener that needs more reads it
 * through `QuotationReaderInterface`.
 */
final readonly class QuotationSent
{
    public function __construct(
        public string $quotationId,
        public string $senderId,
    ) {}
}
