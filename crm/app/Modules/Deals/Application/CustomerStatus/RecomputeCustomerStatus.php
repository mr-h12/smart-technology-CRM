<?php

declare(strict_types=1);

namespace App\Modules\Deals\Application\CustomerStatus;

use App\Modules\Customers\Domain\Contracts\CustomerStatusWriterInterface;
use App\Modules\Deals\Domain\Contracts\DealDirectoryInterface;
use App\Modules\Deals\Domain\CustomerStatus\CustomerStatusDerivation;
use App\Support\Settings\SettingReader;
use DateTimeImmutable;

/**
 * `J-02`'s event-triggered half — §4.5, `D-49`. The nightly correction half
 * (`J-02`'s other trigger) is not this class; it is owed its own point, the
 * same way this module's schema and its API were two separate ones.
 *
 * Called by {@see \App\Modules\Deals\Application\Writing\SaveDeal::create()}
 * and {@see \App\Modules\Deals\Application\Approval\ChangeDealStatus::handle()}
 * — the two places a deal's contribution to §4.5's rule can change — inside
 * the same transaction each already opens, so a customer's derived status
 * never observably lags the write that changed it.
 *
 * ── The setting key is a local constant, not an import ──────────────────────
 *
 * `SystemLimit::StaleDealDays->value` is `'limits.stale_deal_days'`, and this
 * class does not import `SystemLimit` to get it — `App\Support\Settings`'s own
 * docblock explains why: it "lets Admin implement it and Identity consume it
 * without either module learning the other's name." `AuthenticateUser`
 * already sets this precedent for `identity.lockout_minutes`
 * (`LOCKOUT_MINUTES_KEY`); this is the same pattern for Deals' one limit.
 */
final readonly class RecomputeCustomerStatus
{
    public const STALE_DEAL_DAYS_KEY = 'limits.stale_deal_days';

    public function __construct(
        private DealDirectoryInterface $deals,
        private CustomerStatusWriterInterface $customers,
        private SettingReader $settings,
    ) {}

    public function forCustomer(string $customerId): void
    {
        $deals = $this->deals->activityForCustomer($customerId);
        $staleDealDays = $this->settings->nullableInteger(self::STALE_DEAL_DAYS_KEY);

        $status = CustomerStatusDerivation::derive($deals, new DateTimeImmutable, $staleDealDays);

        $this->customers->write($customerId, $status);
    }
}
