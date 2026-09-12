<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Writing;

use App\Modules\Quotations\Domain\Listing\QuotationDetail;

/**
 * `OpenAPI §9.2`'s token in both directions — `"quotation:uuid:7"` on the
 * way out, the `If-Match` header on the way in — shared by every mutation of
 * one quotation (Points 3.6 and 4.2) and by the read that hands it out, so
 * the shape is written once.
 */
final class QuotationEtag
{
    private const SHAPE = '/^quotation:([0-9a-f-]{36}):(\d+)$/';

    public static function of(QuotationDetail $quotation): string
    {
        return 'quotation:'.$quotation->id.':'.$quotation->versionToken;
    }

    /**
     * The token `If-Match` names for **this** quotation — a well-formed etag
     * for another id is as invalid a header as no header.
     *
     * @throws QuotationWriteRefused `if_match_required`
     */
    public static function tokenFrom(?string $ifMatch, string $quotationId): int
    {
        if ($ifMatch === null || preg_match(self::SHAPE, trim($ifMatch), $m) !== 1 || $m[1] !== $quotationId) {
            throw QuotationWriteRefused::ifMatchRequired();
        }

        return (int) $m[2];
    }
}
