<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Application;

use App\Modules\Admin\Domain\Contracts\CurrencyRepositoryInterface;
use App\Modules\Catalog\Domain\Contracts\CatalogItemLabelsInterface;
use App\Modules\Customers\Domain\Contracts\CustomerNamesInterface;
use App\Modules\Dashboard\Domain\Contracts\DashboardAggregatesInterface;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Contracts\UserFactsInterface;

/**
 * `GET /dashboard/breakdowns` — the Sales tab II (`D-144`, `D-145`, `D-147`, `D-150`).
 *
 * | part | shown to |
 * |---|---|
 * | `lost_by_reason` | every dashboard holder, in scope |
 * | `by_employee` | `dashboard.drill_down`; its money only with money |
 * | `by_sector`, `top_customers` | money |
 * | `products` | money and `dashboard.drill_down` (§12.4 hides product detail from the CEO) |
 *
 * Money is `dashboard.view` at All with `quotation.view_cost_and_margin` (`D-145`).
 * Rankings are by base-currency value (`D-149`), ties by name.
 */
final readonly class DashboardBreakdowns
{
    private const TOP_CUSTOMERS = 20;

    private const PRODUCTS = 10;

    public function __construct(
        private AuthorizeAction $authorize,
        private UserFactsInterface $users,
        private CustomerNamesInterface $customers,
        private CatalogItemLabelsInterface $catalog,
        private CurrencyRepositoryInterface $currencies,
        private DashboardAggregatesInterface $aggregates,
        private DashboardOverview $overview,
    ) {}

    /** @return array<string, mixed> */
    public function for(string $actorId, string $from, string $to): array
    {
        $ownerIds = $this->overview->ownerIds($actorId);
        $money = $ownerIds === null && $this->authorize->decide($actorId, 'quotation', 'view_cost_and_margin')->granted;
        $drill = $this->authorize->decide($actorId, 'dashboard', 'drill_down')->granted;
        $parts = $this->aggregates->breakdownsBetween($from, $to, $ownerIds);

        $answer = ['base_currency' => $this->currencies->base()?->code()->value, 'lost_by_reason' => $parts['lost_by_reason']];

        if ($drill) {
            $answer['by_employee'] = $this->byEmployee($parts, $money);
        }
        if ($money) {
            $answer['by_sector'] = $this->bySector($parts['by_sector']);
            $answer['top_customers'] = array_slice($this->ranked($parts['by_customer'], 'customer_id', $this->customers->namesOf(...)), 0, self::TOP_CUSTOMERS);
        }
        if ($money && $drill) {
            $products = $this->ranked($parts['by_product'], 'catalog_item_id', $this->catalog->labelsOf(...));
            $answer['products'] = [
                'best' => array_slice($products, 0, self::PRODUCTS),
                // Lowest first among the products that have a base value to compare (`D-149`).
                'worst' => array_slice(array_reverse(array_values(array_filter($products, static fn (array $p): bool => ($p['base_value'] ?? null) !== null))), 0, self::PRODUCTS),
            ];
        }

        return $answer;
    }

    /**
     * @param  array{won_deals_by_owner: array<string, int>, money_by_owner: list<array{owner_id: string, currency: string, won_value: string}>}  $parts
     * @return list<array<string, mixed>>
     */
    private function byEmployee(array $parts, bool $money): array
    {
        $owners = array_values(array_unique([...array_keys($parts['won_deals_by_owner']), ...array_column($parts['money_by_owner'], 'owner_id')]));
        $names = $this->users->namesOf($owners);

        $rows = [];
        foreach ($owners as $owner) {
            $row = ['owner_id' => $owner, 'name' => $names[$owner] ?? '', 'won_deals' => $parts['won_deals_by_owner'][$owner] ?? 0];
            if ($money) {
                $row['money'] = array_values(array_map(
                    static fn (array $m): array => ['currency' => $m['currency'], 'won_value' => $m['won_value']],
                    array_filter($parts['money_by_owner'], static fn (array $m): bool => $m['owner_id'] === $owner),
                ));
            }
            $rows[] = $row;
        }
        usort($rows, static fn (array $a, array $b): int => [$b['won_deals'], $a['name']] <=> [$a['won_deals'], $b['name']]);

        return $rows;
    }

    /**
     * @param  list<array{sector: ?string, currency: string, won_value: string, won_chains: int}>  $rows
     * @return list<array{sector: ?string, won_chains: int, money: list<array{currency: string, won_value: string}>}>
     */
    private function bySector(array $rows): array
    {
        $sectors = [];
        foreach ($rows as $r) {
            $key = $r['sector'] ?? '';
            $sectors[$key] ??= ['sector' => $r['sector'], 'won_chains' => 0, 'money' => []];
            $sectors[$key]['won_chains'] += $r['won_chains'];
            $sectors[$key]['money'][] = ['currency' => $r['currency'], 'won_value' => $r['won_value']];
        }
        $sectors = array_values($sectors);
        usort($sectors, static fn (array $a, array $b): int => [$b['won_chains'], $a['sector'] ?? ''] <=> [$a['won_chains'], $b['sector'] ?? '']);

        return $sectors;
    }

    /**
     * Rows per (id, currency) folded per id and ranked by base value, highest first,
     * ties by name; an id with no converted value ranks last (`D-149`). Each row is
     * returned under `$idKey` (`customer_id`, `catalog_item_id`).
     *
     * @param  list<array{id: string, currency: string, won_value: string, base_value: numeric-string|null}>  $rows
     * @param  callable(list<string>): array<string, string>  $namesOf
     * @return list<array<string, mixed>>
     */
    private function ranked(array $rows, string $idKey, callable $namesOf): array
    {
        /** @var array<string, list<array{currency: string, won_value: string}>> $money */
        $money = [];
        /** @var array<string, numeric-string|null> $base */
        $base = [];
        foreach ($rows as $r) {
            $money[$r['id']][] = ['currency' => $r['currency'], 'won_value' => $r['won_value']];
            $base[$r['id']] ??= null;
            if ($r['base_value'] !== null) {
                $base[$r['id']] = bcadd($base[$r['id']] ?? '0', $r['base_value'], 6);
            }
        }
        $names = $namesOf(array_keys($money));

        $ids = array_keys($money);
        usort($ids, static function (string $a, string $b) use ($base, $names): int {
            if (($base[$a] === null) !== ($base[$b] === null)) {
                return $base[$a] === null ? 1 : -1;
            }
            $byValue = $base[$a] === null || $base[$b] === null ? 0 : bccomp($base[$b], $base[$a], 6);

            return $byValue !== 0 ? $byValue : strcmp($names[$a] ?? '', $names[$b] ?? '');
        });

        return array_map(static fn (string $id): array => [
            $idKey => $id, 'name' => $names[$id] ?? '', 'base_value' => $base[$id], 'money' => $money[$id],
        ], $ids);
    }
}
