<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Application;

use App\Modules\Dashboard\Domain\Contracts\DashboardAggregatesInterface;
use App\Modules\Dashboard\Domain\DealProgress;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Identity\Domain\Rbac\Scope;

/**
 * `GET /dashboard/sales` — the Sales tab's pipeline figures (`D-144`, `D-145`, `D-151`).
 *
 * Scope as the Overview's. Average deal size only with money (`dashboard.view`
 * All and `view_cost_and_margin`); workload, a per-employee figure, only for a
 * holder of `dashboard.drill_down` (`D-151`) — the keys are absent otherwise.
 */
final readonly class DashboardSales
{
    public function __construct(
        private AuthorizeAction $authorize,
        private UserFactsInterface $users,
        private DashboardAggregatesInterface $aggregates,
        private DashboardOverview $overview,
    ) {}

    /** @return array<string, mixed> */
    public function for(string $actorId, string $from, string $to): array
    {
        $ownerIds = $this->overview->ownerIds($actorId);
        $sales = $this->aggregates->salesBetween($from, $to, $ownerIds);

        $created = array_sum($sales['deals_by_status']);
        $reachedWon = array_sum(array_intersect_key($sales['deals_by_status'], array_flip(DealProgress::REACHED_WON)));

        $answer = [
            'funnel' => array_map(static fn (string $status): array => ['status' => $status, 'deals' => $sales['deals_by_status'][$status] ?? 0], DealProgress::PIPELINE),
            'conversion' => ['created' => $created, 'reached_won' => $reachedWon, 'rate_percent' => DealProgress::percent($reachedWon, $created)],
            'win_loss' => ['won' => $sales['won'], 'lost' => $sales['lost'], 'win_rate_percent' => DealProgress::percent($sales['won'], $sales['won'] + $sales['lost'])],
            'average_sales_cycle_days' => DealProgress::ratio($sales['cycle_days'], $sales['won']),
        ];

        if ($ownerIds === null && $this->authorize->decide($actorId, 'quotation', 'view_cost_and_margin')->granted) {
            $answer['average_deal_size'] = array_map(static fn (array $row): array => [
                'currency' => $row['currency'],
                'value' => $row['won_chains'] === 0 ? '0.000000' : bcdiv($row['won_value'], (string) $row['won_chains'], 6),
            ], $this->aggregates->moneyBetween($from, $to)['currencies']);
        }

        if ($this->authorize->decide($actorId, 'dashboard', 'drill_down')->granted) {
            $names = $this->users->namesOf(array_column($sales['workload'], 'owner_id'));
            $answer['workload'] = array_map(static fn (array $row): array => [
                'owner_id' => $row['owner_id'], 'name' => $names[$row['owner_id']] ?? '', 'open_deals' => $row['open_deals'],
            ], $sales['workload']);
        }

        return $answer;
    }
}
