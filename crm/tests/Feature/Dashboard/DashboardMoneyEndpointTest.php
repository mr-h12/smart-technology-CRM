<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Presentation\RefreshDashboardAggregatesJob;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Quotations\Domain\Contracts\QuotationReaderInterface;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Pdf\InsertsQuotationRows;
use Tests\TestCase;

/**
 * Module 14, slice 3 · 3.1 — `GET /api/v1/dashboard/money` (`D-141`, `D-144`,
 * `D-145`, `D-148`, `D-149`).
 *
 * Revenue is `D-141`'s won money: per chain, the latest version sent in
 * `[from, to]`, counted when it is now `accepted` or `partial`. Per currency,
 * as exact decimal strings: won value (`net_amount`), cost (Σ live line cost),
 * gross profit (`net − additional − cost`), net profit (gross + procurement
 * saving — equal until Module 11), margin (gross ÷ won × 100, two places),
 * delivery cost (the additional items, `D-148`), the won chains. Combined: the
 * base amounts (`D-149`), with the chains that had no rate counted apart.
 * Monthly: the same rule month by month inside the period.
 *
 * Money is shown only to a holder of `quotation.view_cost_and_margin` at
 * `dashboard.view` All (`D-145`); anyone else is refused (403).
 */
final class DashboardMoneyEndpointTest extends TestCase
{
    use InsertsQuotationRows;
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private const ENDPOINT = '/api/v1/dashboard/money';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 21)->setTime(9, 0));
    }

    public function test_that_the_manager_sees_each_currencys_won_money_profit_margin_and_delivery_cost(): void
    {
        // Chain A: accepted. Chain B: partial. Chain C: rejected — not won.
        $this->version('A', 1, '2026-10-05', 'EGP', 'accepted', '1100', '100', '600');
        $this->version('B', 1, '2026-10-06', 'EGP', 'partial', '500', '0', '300');
        $this->version('C', 1, '2026-10-06', 'EGP', 'rejected', '9000', '0', '1');
        $this->version('D', 1, '2026-10-07', 'USD', 'accepted', '20', '2', '8', base: ['1000', '100', '400']);

        $this->getJson(self::ENDPOINT.'?from=2026-10-01&to=2026-10-31', $this->bearer(RoleName::Manager))
            ->assertOk()
            ->assertJsonPath('data.currencies', [
                ['currency' => 'EGP', 'won_value' => '1600.000000', 'cost' => '900.000000', 'gross_profit' => '600.000000',
                    'net_profit' => '600.000000', 'margin_percent' => '37.50', 'delivery_cost' => '100.000000', 'won_chains' => 2],
                ['currency' => 'USD', 'won_value' => '20.000000', 'cost' => '8.000000', 'gross_profit' => '10.000000',
                    'net_profit' => '10.000000', 'margin_percent' => '50.00', 'delivery_cost' => '2.000000', 'won_chains' => 1],
            ]);
    }

    public function test_that_a_chain_counts_by_its_latest_version_sent_in_the_period(): void
    {
        // v1 accepted on the 5th; v2 sent on the 6th and rejected.
        $this->version('A', 1, '2026-10-05', 'EGP', 'accepted', '1000', '0', '400');
        $this->version('A', 2, '2026-10-06', 'EGP', 'rejected', '900', '0', '400');
        $manager = $this->bearer(RoleName::Manager);

        // The whole month: v2 is the latest sent, and it is not won.
        $this->getJson(self::ENDPOINT.'?from=2026-10-01&to=2026-10-31', $manager)
            ->assertOk()->assertJsonPath('data.currencies', []);

        // Up to the 5th: v1 is the latest sent in the period, and it is won.
        $this->getJson(self::ENDPOINT.'?from=2026-10-01&to=2026-10-05', $manager)
            ->assertOk()->assertJsonPath('data.currencies.0.won_value', '1000.000000');
    }

    public function test_that_the_combined_figures_sum_the_base_amounts_and_count_the_unconverted(): void
    {
        $this->currencyId('EGP'); // the base currency (`is_base`), as the seeded system has one
        $this->version('A', 1, '2026-10-05', 'EGP', 'accepted', '1100', '100', '600', base: ['1100', '100', '600']);
        $this->version('B', 1, '2026-10-06', 'USD', 'accepted', '20', '2', '8', base: ['1000', '100', '400']);
        $this->version('C', 1, '2026-10-07', 'EUR', 'accepted', '10', '0', '5', base: null);

        $this->getJson(self::ENDPOINT.'?from=2026-10-01&to=2026-10-31', $this->bearer(RoleName::Manager))
            ->assertOk()
            ->assertJsonPath('data.combined', [
                'currency' => 'EGP', 'won_value' => '2100.000000', 'gross_profit' => '900.000000',
                'net_profit' => '900.000000', 'delivery_cost' => '200.000000', 'unconverted' => 1,
            ]);
    }

    public function test_that_the_monthly_series_applies_the_rule_month_by_month(): void
    {
        $this->version('A', 1, '2026-09-20', 'EGP', 'accepted', '1000', '0', '600');
        $this->version('B', 1, '2026-10-05', 'EGP', 'partial', '500', '0', '100');
        $this->version('C', 1, '2026-10-06', 'USD', 'accepted', '20', '0', '10');

        $this->getJson(self::ENDPOINT.'?from=2026-09-01&to=2026-10-31', $this->bearer(RoleName::Manager))
            ->assertOk()
            ->assertJsonPath('data.monthly', [
                ['month' => '2026-09', 'currency' => 'EGP', 'won_value' => '1000.000000', 'gross_profit' => '400.000000'],
                ['month' => '2026-10', 'currency' => 'EGP', 'won_value' => '500.000000', 'gross_profit' => '400.000000'],
                ['month' => '2026-10', 'currency' => 'USD', 'won_value' => '20.000000', 'gross_profit' => '10.000000'],
            ]);
    }

    public function test_that_a_month_on_the_dashboard_equals_that_months_monthly_report(): void
    {
        // Real quotations, J-09, then the same month read both ways (`D-141`).
        $v1 = $this->pricedQuotation('accepted', '2026-10-03 10:00:00', 'EGP', '1000', additional: '100', lineCosts: ['400']);
        $this->pricedQuotation('partial', '2026-10-10 10:00:00', 'EGP', '2000', lineCosts: ['1500']);
        $this->pricedQuotation('rejected', '2026-10-11 10:00:00', 'EGP', '7000', lineCosts: ['1']);
        $this->pricedQuotation('accepted', '2026-10-12 10:00:00', 'USD', '30', additional: '5', lineCosts: ['10', '2']);
        $this->pricedQuotation('accepted', '2026-09-30 10:00:00', 'EGP', '5000', lineCosts: ['10']);
        $this->pricedQuotation('rejected', '2026-10-15 10:00:00', 'EGP', '1000', parentId: $v1);
        $this->app->instance('request', Request::create('http://localhost'));
        $this->app->call([new RefreshDashboardAggregatesJob(full: true), 'handle']);

        $report = $this->app->make(QuotationReaderInterface::class)
            ->wonMoneySentBetween(CarbonImmutable::parse('2026-10-01 00:00:00'), CarbonImmutable::parse('2026-11-01 00:00:00'));

        $dashboard = $this->getJson(self::ENDPOINT.'?from=2026-10-01&to=2026-10-31', $this->bearer(RoleName::Manager))
            ->assertOk()->json('data.currencies');
        self::assertIsArray($dashboard);

        self::assertSame(
            array_map(static fn (array $r): array => [$r['currency'], $r['won_value'], $r['cost'], $r['profit']], $report),
            array_map(static fn (mixed $r): array => is_array($r) ? [$r['currency'] ?? null, $r['won_value'] ?? null, $r['cost'] ?? null, $r['gross_profit'] ?? null] : [], $dashboard),
        );
        self::assertNotSame([], $report, 'The month must hold won money for the comparison to mean anything.');
    }

    // ── refusals ────────────────────────────────────────────────────────────

    public function test_that_the_ceo_sees_the_money(): void
    {
        $this->getJson(self::ENDPOINT.'?from=2026-10-01&to=2026-10-31', $this->bearer(RoleName::Ceo))->assertOk();
    }

    /** @return iterable<string, array{RoleName}> */
    public static function rolesWithoutMoney(): iterable
    {
        yield 'team leader (counts only, §12.3)' => [RoleName::TeamLeader];
        yield 'indoor sales' => [RoleName::IndoorSales];
    }

    #[DataProvider('rolesWithoutMoney')]
    public function test_that_a_role_without_money_is_refused(RoleName $role): void
    {
        $this->getJson(self::ENDPOINT.'?from=2026-10-01&to=2026-10-31', $this->bearer($role))->assertStatus(403);
    }

    public function test_that_a_manager_without_cost_and_margin_is_refused(): void
    {
        DB::table('role_permissions')
            ->where('role_id', Role::query()->where('slug', RoleName::Manager->value)->firstOrFail()->id)
            ->whereIn('permission_id', DB::table('permissions')->where(['resource' => 'quotation', 'action' => 'view_cost_and_margin'])->pluck('id'))
            ->delete();

        $this->getJson(self::ENDPOINT.'?from=2026-10-01&to=2026-10-31', $this->bearer(RoleName::Manager))->assertStatus(403);
    }

    public function test_that_a_bad_period_is_a_422(): void
    {
        $this->getJson(self::ENDPOINT.'?from=2026-10-31&to=2026-10-01', $this->bearer(RoleName::Manager))->assertStatus(422);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** @var array<string, string> chain label => the chain's root quotation id */
    private array $chains = [];

    /**
     * One `dashboard_sent_versions` row, as J-09 leaves it.
     *
     * @param  array{0: string, 1: string, 2: string}|null|false  $base  net, additional, cost in the base currency; null = no rate; false = same as stored (EGP)
     */
    private function version(string $chain, int $version, string $sentDay, string $currency, string $status, string $net, string $additional, string $cost, array|null|false $base = false): void
    {
        $id = (string) Str::uuid7();
        $this->chains[$chain] ??= $id;
        $base = $base === false ? ($currency === 'EGP' ? [$net, $additional, $cost] : null) : $base;
        DB::table('dashboard_sent_versions')->insert([
            'quotation_id' => $id, 'chain_id' => $this->chains[$chain], 'version' => $version, 'sent_day' => $sentDay,
            'owner_id' => null, 'currency' => $currency, 'status' => $status,
            'net_amount' => $net, 'additional_total' => $additional, 'cost' => $cost,
            'base_net_amount' => $base[0] ?? null, 'base_additional_total' => $base[1] ?? null, 'base_cost' => $base[2] ?? null,
        ]);
    }

    /** @return array<string, string> */
    private function bearer(RoleName $role): array
    {
        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label(), 'email' => str_replace('_', '.', $role->value).'@example.test', 'password' => self::PASSWORD,
            'role_id' => Role::query()->where('slug', $role->value)->firstOrFail()->id, 'is_active' => true, 'is_hidden' => $role->isHidden(),
        ]);
        $user->save();
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertStatus(201)->json('data.token');
        self::assertIsString($token);

        return ['Authorization' => 'Bearer '.$token];
    }
}
