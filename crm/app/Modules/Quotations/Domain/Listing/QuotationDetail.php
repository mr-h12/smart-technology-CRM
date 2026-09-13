<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Listing;

use DateTimeImmutable;

/**
 * What `GET /quotations/{id}` reads — Module 7 Point 3.5. The `quotations`
 * header as Point 1.1 built it, plus its two child tables, as one object.
 *
 * Every money and percent field is the decimal text the `Precision` casts
 * return (`DB-07`); `taxPercent` and `taxAmount` are null for `D-63`'s exempt
 * quotation; the two dates are `YYYY-MM-DD` strings for the reason the model
 * leaves them uncast; `versionToken` is what `OpenAPI §9.2`'s `etag` is built
 * from and what Point 3.6's `If-Match` compares against.
 *
 * `QuotationSummary` stays the create's two-field answer; this is the read's,
 * and it grew here rather than there because "a field with no reader is the
 * unused component the waste audit exists to catch" — now every field has one.
 */
final readonly class QuotationDetail
{
    /**
     * @param  list<QuotationLine>  $items
     * @param  list<QuotationAdditionalLine>  $additionalItems
     */
    public function __construct(
        public string $id,
        public string $code,
        public string $dealId,
        public string $customerId,
        public ?string $quotationDate,
        public ?string $validUntil,
        public string $status,
        public string $currencyId,
        public string $defaultMargin,
        public string $discountPercent,
        public ?string $taxPercent,
        public string $roundingUnit,
        public bool $roundingEnabled,
        public string $subtotal,
        public string $additionalTotal,
        public string $discountAmount,
        public string $taxBase,
        public ?string $taxAmount,
        public string $netAmount,
        public string $totalBeforeRound,
        public string $finalTotal,
        public string $roundingDiff,
        public ?string $paymentTerms,
        public ?string $warranty,
        public ?string $deliveryTerms,
        public bool $showDeliveryTerms,
        public int $version,
        public ?string $parentId,
        public ?string $rejectionReason,
        public ?string $sentAt,
        public ?string $submittedAt,
        public bool $isSelfApproved,
        public int $versionToken,
        public ?string $createdBy,
        public ?string $updatedBy,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public array $items,
        public array $additionalItems,
    ) {}
}
