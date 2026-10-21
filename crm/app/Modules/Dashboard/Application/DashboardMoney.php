<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Application;

use App\Modules\Admin\Domain\Contracts\CurrencyRepositoryInterface;
use App\Modules\Dashboard\Domain\Contracts\DashboardAggregatesInterface;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Identity\Domain\Rbac\Scope;

/**
 * `GET /dashboard/money` (`D-141`, `D-144`, `D-145`, `D-148`, `D-149`): each
 * currency\'s won money, profits, margin and delivery cost; the base-currency
 * totals with what could not be converted; and month by month.
 *
 * Money only for a holder of `quotation.view_cost_and_margin` whose
 * `dashboard.view` covers All — the Team Leader\'s dashboard is counts only
 * (§12.3) — and a refusal otherwise, never an empty answer.
 *
 * Net profit = gross profit + procurement saving (§5.5); Module 11 records no
 * saving yet, so the two are equal and the screen says so (`D-144`).
 */
final readonly class DashboardMoney
{
    public function __construct(
        private AuthorizeAction $authorize,
        private DashboardAggregatesInterface $aggregates,
        private CurrencyRepositoryInterface $currencies,
    ) {}

    /** @return array<string, mixed> */
    public function for(string $actorId, string $from, string $to): array
    {
        if (! $this->authorize->decide($actorId, 'dashboard', 'view')->allows(Scope::All)) {
            throw AuthorizationRefused::of('dashboard', 'view', Scope::All);
        }
        $this->authorize->authorize($actorId, 'quotation', 'view_cost_and_margin');

        $money = $this->aggregates->moneyBetween($from, $to);

        return [
            'currencies' => array_map(static fn (array $row): array => [
                'currency' => $row['currency'], 'won_value' => $row['won_value'], 'cost' => $row['cost'],
                'gross_profit' => $row['gross_profit'], 'net_profit' => $row['gross_profit'],
                'margin_percent' => $row['margin_percent'], 'delivery_cost' => $row['delivery_cost'], 'won_chains' => $row['won_chains'],
            ], $money['currencies']),
            'combined' => [
                'currency' => $this->currencies->base()?->code()->value,
                'won_value' => $money['combined']['won_value'], 'gross_profit' => $money['combined']['gross_profit'],
                'net_profit' => $money['combined']['gross_profit'], 'delivery_cost' => $money['combined']['delivery_cost'],
                'unconverted' => $money['combined']['unconverted'],
            ],
            'monthly' => $money['monthly'],
        ];
    }
}
