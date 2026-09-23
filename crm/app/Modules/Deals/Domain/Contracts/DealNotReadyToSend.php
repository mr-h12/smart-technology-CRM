<?php

declare(strict_types=1);

namespace App\Modules\Deals\Domain\Contracts;

use RuntimeException;

/**
 * `D-90` rule a: a deal before `supplier_quotation` cannot reach
 * `quotation_sent` in one move (§4.4), so sending its quotation is refused.
 * Module 10 · 1.3 renders it as `422 deal_not_ready_to_send`.
 */
final class DealNotReadyToSend extends RuntimeException
{
    private function __construct(public readonly string $dealId, public readonly string $status)
    {
        parent::__construct('Deal '.$dealId.' is at '.$status.', before supplier_quotation.');
    }

    public static function of(string $dealId, string $status): self
    {
        return new self($dealId, $status);
    }
}
