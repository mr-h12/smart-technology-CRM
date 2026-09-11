<?php

declare(strict_types=1);

namespace App\Modules\Customers\Infrastructure;

use App\Modules\Customers\Domain\Contracts\CustomerTaxStatusInterface;
use App\Modules\Customers\Infrastructure\Eloquent\Customer;

/**
 * {@see CustomerTaxStatusInterface} over `customers.is_tax_exempt`.
 *
 * One column, by primary key. `->value()` selects it alone rather than
 * hydrating a customer to read one boolean, and the `SoftDeletes` global scope
 * already excludes an archived row — so a deleted or absent customer returns
 * null, which the cast turns into false: the taxed direction, which the
 * interface documents as the safe one.
 *
 * `is_tax_exempt` is uncast on the model (added by Module 7's migration to
 * Module 3's table), but PostgreSQL hands a boolean column back as a PHP bool;
 * the `(bool)` is belt to null's braces, not a string conversion.
 */
final readonly class EloquentCustomerTaxStatus implements CustomerTaxStatusInterface
{
    public function isExempt(string $customerId): bool
    {
        return (bool) Customer::query()->whereKey($customerId)->value('is_tax_exempt');
    }
}
