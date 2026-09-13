<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Listing;

use RuntimeException;

/**
 * `OpenAPI §6.1`/`§6.2` — a quotation list query that cannot be honoured.
 *
 * The same shape as {@see \App\Modules\Deals\Domain\Listing\InvalidDealListQuery}
 * and for the reason it records: Domain "may depend on nothing", so a shared
 * base in `App\Support` is not available to it.
 */
final class InvalidQuotationListQuery extends RuntimeException
{
    public const ERROR_CODE = 'invalid_request';

    private function __construct(public readonly string $parameter, public readonly string $detailCode)
    {
        parent::__construct('Invalid quotation list query on '.$parameter.': '.$detailCode);
    }

    public static function of(string $parameter, string $detailCode): self
    {
        return new self($parameter, $detailCode);
    }

    public function messageKey(): string
    {
        return 'quotations.list_query.'.$this->detailCode;
    }
}
