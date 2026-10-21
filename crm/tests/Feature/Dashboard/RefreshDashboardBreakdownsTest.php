<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Presentation\RefreshDashboardAggregatesJob;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Feature\Pdf\InsertsQuotationRows;
use Tests\TestCase;

/**
 * Module 14, slice 5 · 5.1 — what J-09 adds for the breakdowns (`D-144`, `D-147`, `D-149`).
 *
 * - `dashboard_sent_versions` also carries each version's customer and that customer's sector now;
 * - `dashboard_sent_lines`: each live line of a sent version, its catalog product
 *   (through its supplier line), its `line_total`, and that total in the base
 *   currency at the version's rate (`D-149`), null without one;
 * - `dashboard_daily_outcomes` keeps a lost deal's `lost_reasons` code (`D-147`);
 *   a lost deal recorded before the code existed counts as `other`.
 */
final class RefreshDashboardBreakdownsTest extends TestCase
{
    use InsertsQuotationRows;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 21)->setTime(9, 0));
    }

    public function test_that_a_sent_version_carries_its_customer_and_the_customers_sector(): void
    {
        $id = $this->pricedQuotation('accepted', '2026-10-05 10:00:00', 'EGP', '1000');
        $customer = DB::table('quotations')->where('id', $id)->value('customer_id');
        DB::table('customers')->where('id', $customer)->update(['sector' => 'medical']);

        $this->refresh();

        $row = DB::table('dashboard_sent_versions')->where('quotation_id', $id)->first(['customer_id', 'sector']);
        self::assertSame([$customer, 'medical'], array_values((array) $row));
    }

    public function test_that_each_live_line_of_a_sent_version_is_a_row_with_its_product_and_base_total(): void
    {
        $this->currencyId('EGP');
        $this->rate('USD', 'EGP', '50', '2026-10-01 00:00:00');
        $id = $this->pricedQuotation('accepted', '2026-10-05 10:00:00', 'USD', '30', lineCosts: ['10', '2'], deletedLineCosts: ['7']);
        $gadget = $this->secondProduct();
        $lines = DB::table('quotation_items')->where('quotation_id', $id)->whereNull('deleted_at')->orderBy('line_no')->pluck('id')->all();
        DB::table('quotation_items')->where('id', $lines[1])->update(['supplier_quotation_item_id' => $gadget['line']]);
        $widget = DB::table('catalog_items')->where('name', 'Widget')->value('id');

        $this->refresh();

        self::assertSame([
            [$widget, '10.000000', '500.000000'],
            [$gadget['item'], '2.000000', '100.000000'],
        ], array_map(
            static fn (object $r): array => [$r->catalog_item_id, $r->line_total, $r->base_line_total],
            DB::table('dashboard_sent_lines')->where('quotation_id', $id)->orderByDesc('line_total')->get()->all(),
        ));
    }

    public function test_that_a_lost_outcome_keeps_its_reason_code_and_an_older_one_counts_as_other(): void
    {
        $coded = $this->lostDeal('price_too_high');
        $older = $this->lostDeal(null);

        $this->refresh();

        self::assertSame(
            [['other', 1], ['price_too_high', 1]],
            array_map(static fn (object $r): array => [$r->reason_code, is_numeric($r->deals) ? (int) $r->deals : null],
                DB::table('dashboard_daily_outcomes')->where('outcome', 'lost')->orderBy('reason_code')->get(['reason_code', 'deals'])->all()),
        );
        self::assertNotSame($coded, $older);
    }

    private function refresh(): void
    {
        $this->app->instance('request', Request::create('http://localhost'));
        $this->app->call([new RefreshDashboardAggregatesJob(full: true), 'handle']);
    }

    private function lostDeal(?string $code): string
    {
        $customer = Uuid::uuid7()->toString();
        DB::table('customers')->insert(['id' => $customer, 'name' => 'Nile Trading', 'created_at' => '2020-01-01', 'updated_at' => '2020-01-01']);
        $id = Uuid::uuid7()->toString();
        DB::table('deals')->insert([
            'id' => $id, 'code' => 'DL-2026-'.substr(str_replace('-', '', $id), -6), 'customer_id' => $customer, 'owner_id' => null,
            'status' => 'lost', 'lost_reason' => $code === null ? 'Price.' : null, 'lost_reason_code' => $code,
            'last_activity_at' => '2026-10-01', 'created_at' => '2026-10-01', 'updated_at' => '2026-10-01',
        ]);
        DB::table('audit_log')->insert([
            'id' => Uuid::uuid7()->toString(), 'user_id' => null, 'impersonated_user_id' => null,
            'event' => 'DEAL_STATUS_CHANGED', 'entity_type' => 'deal', 'entity_id' => $id,
            'old_values' => json_encode(['status' => 'quotation_sent']), 'new_values' => json_encode(['status' => 'lost']),
            'ip_address' => null, 'user_agent' => null, 'request_id' => null, 'correlation_id' => null, 'created_at' => '2026-10-04 12:00:00',
        ]);

        return $id;
    }

    /** @return array{item: string, line: string} a second catalog product with its own supplier line */
    private function secondProduct(): array
    {
        $item = Uuid::uuid7()->toString();
        $line = Uuid::uuid7()->toString();
        DB::table('catalog_items')->insert(['id' => $item, 'kind' => 'product', 'name' => 'Gadget', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('supplier_quotation_items')->insert([
            'id' => $line, 'supplier_quotation_id' => DB::table('supplier_quotations')->value('id'), 'catalog_item_id' => $item,
            'unit_price' => '2', 'quantity' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['item' => $item, 'line' => $line];
    }

    private function rate(string $from, string $to, string $rate, string $effectiveFrom): void
    {
        DB::table('fx_rates')->insert([
            'id' => Uuid::uuid7()->toString(), 'from_currency_id' => $this->currencyId($from), 'to_currency_id' => $this->currencyId($to),
            'rate' => $rate, 'effective_from' => $effectiveFrom, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
