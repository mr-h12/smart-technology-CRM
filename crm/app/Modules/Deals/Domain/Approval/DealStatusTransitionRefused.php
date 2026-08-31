<?php

declare(strict_types=1);

namespace App\Modules\Deals\Domain\Approval;

use RuntimeException;

/**
 * `OpenAPI §5.1` — `409 state_transition_invalid`, on
 * `DealApprovalRefused`'s precedent: §4.4's graph names every edge that
 * exists, and a request naming any other pair is exactly the documented
 * violation that row exists for.
 *
 * A separate class from `DealApprovalRefused` despite sharing an HTTP code
 * and error code: the two guard different domain rules — this one §4.4's
 * status graph, that one Flow 3's one-time approval decision — and
 * `ApiExceptionRenderer` renders each from its own handler, the same way
 * `InvalidCustomerListQuery` and `InvalidSupplierListQuery` stay separate
 * classes despite an identical rendered shape.
 */
final class DealStatusTransitionRefused extends RuntimeException
{
    public const ERROR_CODE = 'state_transition_invalid';

    private function __construct(public readonly string $dealId, public readonly string $from, public readonly string $to)
    {
        parent::__construct('Deal '.$dealId.' cannot move from '.$from.' to '.$to.'.');
    }

    public static function of(string $dealId, string $from, string $to): self
    {
        return new self($dealId, $from, $to);
    }

    public function messageKey(): string
    {
        return 'deals.status.invalid_transition';
    }
}
