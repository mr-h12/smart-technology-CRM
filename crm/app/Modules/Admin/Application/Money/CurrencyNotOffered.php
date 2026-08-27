<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application\Money;

use App\Modules\Admin\Domain\Money\CurrencyCode;
use RuntimeException;

/**
 * The code names a currency this system does not offer — never seeded, or
 * archived since (`D-34`).
 *
 * A 404 rather than a 422: `OpenAPI §5.1` treats an addressed resource that is
 * not there as not found, and the code is in the path.
 */
final class CurrencyNotOffered extends RuntimeException
{
    /**
     * The property is `$currency` and not `$code`: `Exception::$code` already
     * exists and is not readonly, so redeclaring it is a fatal error rather
     * than a shadow. Measured — "Cannot redeclare non-readonly property".
     */
    public function __construct(public readonly CurrencyCode $currency)
    {
        parent::__construct('This system does not offer '.$currency->value.'.');
    }
}
