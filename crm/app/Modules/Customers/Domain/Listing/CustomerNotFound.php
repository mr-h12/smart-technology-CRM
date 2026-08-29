<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Listing;

use RuntimeException;

/**
 * `OpenAPI §5.1` — 404 for a customer that is absent **or** out of reach.
 *
 * One exception for both cases on purpose: §5.1 says 404 covers "does not exist
 * or is not visible to the caller. Do not reveal which case applies." Two
 * exception types would let a caller tell them apart from the response, which is
 * the leak the rule exists to close.
 */
final class CustomerNotFound extends RuntimeException
{
    public const ERROR_CODE = 'not_found';

    private function __construct(public readonly string $customerId)
    {
        parent::__construct('Customer '.$customerId.' is not visible to this caller.');
    }

    public static function of(string $customerId): self
    {
        return new self($customerId);
    }

    public function messageKey(): string
    {
        return 'customers.not_found';
    }
}
