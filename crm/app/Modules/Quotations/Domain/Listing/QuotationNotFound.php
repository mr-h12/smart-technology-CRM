<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Listing;

use RuntimeException;

/**
 * `OpenAPI §5.1` — 404 for a quotation that is absent **or** out of reach.
 *
 * One exception for both cases, as `DealNotFound` is: §5.1 says "does not
 * exist or is not visible to the caller. Do not reveal which case applies."
 */
final class QuotationNotFound extends RuntimeException
{
    public const ERROR_CODE = 'not_found';

    private function __construct(public readonly string $quotationId)
    {
        parent::__construct('Quotation '.$quotationId.' is not visible to this caller.');
    }

    public static function of(string $quotationId): self
    {
        return new self($quotationId);
    }

    public function messageKey(): string
    {
        return 'quotations.not_found';
    }
}
