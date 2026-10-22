<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Application;

use App\Modules\Admin\Application\Settings\CompanyToday;
use App\Modules\Admin\Domain\Contracts\CurrencyRepositoryInterface;
use App\Modules\Admin\Domain\Contracts\FxRateRepositoryInterface;
use App\Modules\Admin\Domain\Money\CurrencyCode;
use App\Modules\Admin\Domain\Money\ExchangeRate;
use App\Modules\Audit\Domain\Contracts\AuditEntryReaderInterface;
use App\Modules\Customers\Domain\Contracts\NewCustomersInterface;
use App\Modules\Dashboard\Domain\Contracts\DashboardAggregatesInterface;
use App\Modules\Deals\Domain\Contracts\DealFactsInterface;
use App\Modules\Quotations\Domain\Contracts\QuotationReaderInterface;
use App\Modules\SupplierQuotations\Domain\Contracts\SupplierItemPricingInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * J-09 `refresh_dashboard_aggregates` (§15, `D-144`, `D-145`, `D-146`).
 *
 * Nightly it rebuilds every day. Hourly it rebuilds only the days whose records
 * changed since the last run began — a September deal that moves on today
 * rebuilds September's day — and the first hourly run ever rebuilds everything.
 * The "now" counts are rebuilt on every run. Nothing before the go-live date is
 * kept (`D-145`). One transaction, so a second run of the same moment changes
 * nothing. No catch-up: the next run recomputes (§15).
 */
final readonly class RefreshDashboardAggregates
{
    private const SELF_APPROVAL = 'SELF_APPROVAL';

    public function __construct(
        private DealFactsInterface $deals,
        private NewCustomersInterface $customers,
        private QuotationReaderInterface $quotations,
        private AuditEntryReaderInterface $audit,
        private DashboardAggregatesInterface $aggregates,
        private CompanyToday $today,
        private ConnectionInterface $connection,
        private CurrencyRepositoryInterface $currencies,
        private FxRateRepositoryInterface $rates,
        private SupplierItemPricingInterface $supplierPrices,
    ) {}

    /** @return int|null how many days were rebuilt; null when every day was */
    public function run(bool $full): ?int
    {
        $startedAt = CarbonImmutable::now();
        $zone = $this->today->zone();
        $since = $full ? null : $this->aggregates->lastStartedAt();
        $days = $since === null ? null : $this->touchedDays($since, $zone);
        $goLive = $this->today->goLiveDate();
        $live = static fn (array $row): bool => $goLive === null || $row['day'] >= $goLive;

        $this->connection->transaction(function () use ($days, $zone, $live, $full, $startedAt, $goLive): void {
            $this->aggregates->replaceDeals($days, array_values(array_filter($this->deals->createdCountsByDay($zone, $days), $live)));
            $this->aggregates->replaceCustomers($days, array_values(array_filter($this->customers->createdCountsByDay($zone, $days), $live)));
            $this->aggregates->replaceSelfApprovals($days, array_values(array_map(
                static fn (array $row): array => ['day' => $row['day'], 'approver_id' => $row['actor_id'], 'approvals' => $row['entries']],
                array_filter($this->audit->countsByDay(self::SELF_APPROVAL, $zone, $days), $live),
            )));
            $this->aggregates->replaceLiveCounts($this->quotations->liveApprovalCountsByOwner());
            [$versions, $rates] = $this->sentVersions($zone, $goLive);
            $this->aggregates->replaceSentVersions($versions);
            $this->aggregates->replaceSentLines($this->sentLines($rates));
            $this->aggregates->replaceOutcomes($this->outcomes($zone, $goLive));
            $this->aggregates->replaceWorkload($this->deals->openCountsByOwner());
            $this->aggregates->recordRefresh($full ? 'nightly' : 'hourly', $startedAt, CarbonImmutable::now());
        });

        return $days === null ? null : count($days);
    }

    /**
     * The money projection, whole (`D-141`, `D-150`): each sent version's company day,
     * its deal's owner now, and its amounts in the base currency at the rate in force
     * when the version was created — null when that pair had no rate then.
     *
     * Also returns each kept version's rate (null without one), for its lines.
     *
     * @return array{0: list<array{quotation_id: string, chain_id: string, version: int, sent_day: string, owner_id: ?string, currency: string, status: string, net_amount: string, additional_total: string, cost: string, base_net_amount: ?string, base_additional_total: ?string, base_cost: ?string, customer_id: string, sector: ?string}>, 1: array<string, ?ExchangeRate>}
     */
    private function sentVersions(string $zone, ?string $goLive): array
    {
        $versions = $this->quotations->sentVersions();
        $owners = $this->deals->ownersOf(array_values(array_unique(array_column($versions, 'deal_id'))));
        $sectors = $this->customers->sectorsOf(array_values(array_unique(array_column($versions, 'customer_id'))));
        $base = $this->currencies->base()?->code();

        $rows = [];
        $kept = [];
        foreach ($versions as $v) {
            $sentDay = CarbonImmutable::parse($v['sent_at'])->setTimezone($zone)->toDateString();
            if ($goLive !== null && $sentDay < $goLive) {
                continue;
            }
            $rate = $base === null || CurrencyCode::tryFrom($v['currency']) === null
                ? null
                : $this->rates->effectiveRate(CurrencyCode::from($v['currency']), $base, CarbonImmutable::parse($v['created_at']));
            $rows[] = [
                'quotation_id' => $v['quotation_id'], 'chain_id' => $v['chain_id'], 'version' => $v['version'], 'sent_day' => $sentDay,
                'owner_id' => $owners[$v['deal_id']] ?? null, 'currency' => $v['currency'], 'status' => $v['status'],
                'net_amount' => $v['net_amount'], 'additional_total' => $v['additional_total'], 'cost' => $v['cost'],
                'base_net_amount' => $rate?->convert($v['net_amount'], 6),
                'base_additional_total' => $rate?->convert($v['additional_total'], 6),
                'base_cost' => $rate?->convert($v['cost'], 6),
                'customer_id' => $v['customer_id'], 'sector' => $sectors[$v['customer_id']] ?? null,
            ];
            $kept[$v['quotation_id']] = $rate;
        }

        return [$rows, $kept];
    }

    /**
     * Each live line of a kept version, its product (through its supplier line —
     * `SupplierItemPricingInterface`, as the quotation page names its lines), and
     * its total in the base currency at the version's rate (`D-150`).
     *
     * @param  array<string, ?ExchangeRate>  $rates  quotation id => its rate
     * @return list<array{quotation_id: string, catalog_item_id: ?string, line_total: string, base_line_total: ?string}>
     */
    private function sentLines(array $rates): array
    {
        $products = [];
        $rows = [];
        foreach ($this->quotations->sentVersionLines() as $line) {
            if (! array_key_exists($line['quotation_id'], $rates)) {
                continue;
            }
            // ponytail: one lookup per distinct supplier line; a bulk reader when the catalog grows past a few thousand lines.
            $products[$line['supplier_quotation_item_id']] ??= $this->supplierPrices->priceFor($line['supplier_quotation_item_id'])?->catalogItemId;
            $rows[] = [
                'quotation_id' => $line['quotation_id'],
                'catalog_item_id' => $products[$line['supplier_quotation_item_id']],
                'line_total' => $line['line_total'],
                'base_line_total' => $rates[$line['quotation_id']]?->convert($line['line_total'], 6),
            ];
        }

        return $rows;
    }

    /**
     * `D-151`: each deal's first move into `won` or `lost` (its `DEAL_STATUS_CHANGED`
     * entries), by company day and current owner; for won, the days from creation.
     *
     * A lost deal keeps its `lost_reasons` code (`D-147`); one lost before the code
     * existed counts as `other`, as its migration filed it.
     *
     * @return list<array{day: string, owner_id: ?string, outcome: string, deals: int, cycle_days: int, reason_code: ?string}>
     */
    private function outcomes(string $zone, ?string $goLive): array
    {
        $moves = $this->audit->firstEntriesInto('DEAL_STATUS_CHANGED', 'deal', 'status', ['won', 'lost']);
        $deals = $this->deals->creationAndOwnerOf(array_values(array_unique(array_column($moves, 'entity_id'))));

        $grouped = [];
        foreach ($moves as $move) {
            $deal = $deals[$move['entity_id']] ?? null;
            if ($deal === null) {
                continue;
            }
            $day = CarbonImmutable::parse($move['at'])->setTimezone($zone)->startOfDay();
            if ($goLive !== null && $day->toDateString() < $goLive) {
                continue;
            }
            $cycle = $move['value'] === 'won'
                ? max(0, (int) CarbonImmutable::parse($deal['created_at'])->setTimezone($zone)->startOfDay()->diffInDays($day))
                : 0;
            $reason = $move['value'] === 'lost' ? ($deal['lost_reason_code'] ?? 'other') : null;
            $key = $day->toDateString().'|'.($deal['owner_id'] ?? '').'|'.$move['value'].'|'.($reason ?? '');
            $grouped[$key] ??= ['day' => $day->toDateString(), 'owner_id' => $deal['owner_id'], 'outcome' => $move['value'], 'deals' => 0, 'cycle_days' => 0, 'reason_code' => $reason];
            $grouped[$key]['deals']++;
            $grouped[$key]['cycle_days'] += $cycle;
        }

        return array_values($grouped);
    }

    /** @return list<string> */
    private function touchedDays(\DateTimeImmutable $since, string $zone): array
    {
        $days = array_unique([
            ...$this->deals->creationDaysTouchedSince($since, $zone),
            ...$this->customers->creationDaysTouchedSince($since, $zone),
            ...$this->audit->daysWithEntriesSince(self::SELF_APPROVAL, $since, $zone),
        ]);
        sort($days);

        return $days;
    }
}
