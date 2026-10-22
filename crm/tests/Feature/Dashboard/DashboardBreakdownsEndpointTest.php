<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module 14, slice 5 · 5.1 — `GET /api/v1/dashboard/breakdowns` (`D-144`, `D-145`, `D-147`, `D-151`).
 *
 * | part | who | what |
 * |---|---|---|
 * | `lost_by_reason` | everyone with the dashboard, in scope | deals lost in the period, by `lost_reasons` code |
 * | `by_employee` | `dashboard.drill_down` (`D-151`) | won deals per owner; won money per currency with money |
 * | `by_sector` | money | `D-141`'s won chains and money per customer sector |
 * | `top_customers` | money | up to 20 customers by base-currency won value (`D-150`) |
 * | `products` | money and `dashboard.drill_down` (§12.4 hides them from the CEO) | the ten best and worst sold products by base value |
 *
 * Absent parts are absent keys, never empty lists.
 */
final class DashboardBreakdownsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private const ENDPOINT = '/api/v1/dashboard/breakdowns?from=2026-10-01&to=2026-10-31';

    private User $leader;

    private User $member;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 21)->setTime(9, 0));
        $this->leader = $this->user(RoleName::TeamLeader, 'leader');
        $this->member = $this->user(RoleName::IndoorSales, 'member', $this->leader);
        $this->outsider = $this->user(RoleName::IndoorSales, 'outsider');
    }

    public function test_that_the_manager_sees_every_part(): void
    {
        $this->seedTheMonth();

        $data = $this->breakdowns(RoleName::Manager);

        self::assertSame([['code' => 'price_too_high', 'deals' => 3], ['code' => 'other', 'deals' => 1]], $data['lost_by_reason']);
        self::assertSame([
            ['owner_id' => $this->member->id, 'name' => $this->member->name, 'won_deals' => 2, 'money' => [['currency' => 'EGP', 'won_value' => '1500.000000']]],
            ['owner_id' => $this->outsider->id, 'name' => $this->outsider->name, 'won_deals' => 1, 'money' => [['currency' => 'USD', 'won_value' => '20.000000']]],
        ], $data['by_employee']);
        self::assertSame([
            ['sector' => 'medical', 'won_chains' => 2, 'money' => [['currency' => 'EGP', 'won_value' => '1500.000000']]],
            ['sector' => 'banks', 'won_chains' => 1, 'money' => [['currency' => 'USD', 'won_value' => '20.000000']]],
        ], $data['by_sector']);
        self::assertIsArray($data['top_customers']);
        self::assertSame(['Delta Medical', 'Nile Bank', 'Cairo Clinic'], array_column($data['top_customers'], 'name'));
        self::assertSame('EGP', $data['base_currency']);
        self::assertIsArray($data['products']);
        self::assertIsArray($data['products']['best']);
        self::assertIsArray($data['products']['worst']);
        self::assertSame(['Widget', 'Gadget'], array_column($data['products']['best'], 'name'));
        self::assertSame(['Gadget', 'Widget'], array_column($data['products']['worst'], 'name'));
    }

    public function test_that_the_team_leader_sees_counts_for_the_team_only(): void
    {
        $this->seedTheMonth();

        $data = $this->breakdowns($this->leader);

        self::assertSame([['code' => 'price_too_high', 'deals' => 2]], $data['lost_by_reason']);
        self::assertSame([['owner_id' => $this->member->id, 'name' => $this->member->name, 'won_deals' => 2]], $data['by_employee']);
        foreach (['by_sector', 'top_customers', 'products'] as $moneyPart) {
            self::assertArrayNotHasKey($moneyPart, $data);
        }
    }

    public function test_that_the_ceo_sees_sectors_and_customers_but_no_employee_or_product_detail(): void
    {
        $this->seedTheMonth();

        $data = $this->breakdowns(RoleName::Ceo);

        self::assertArrayHasKey('by_sector', $data);
        self::assertArrayHasKey('top_customers', $data);
        self::assertArrayNotHasKey('by_employee', $data);
        self::assertArrayNotHasKey('products', $data);
    }

    public function test_that_top_customers_stop_at_twenty(): void
    {
        for ($i = 1; $i <= 22; $i++) {
            $this->version('C'.$i, 'Customer '.$i, 'medical', $this->member->id, 'EGP', (string) (100 * $i), (string) (100 * $i));
        }

        $top = $this->breakdowns(RoleName::Manager)['top_customers'];
        self::assertIsArray($top);
        self::assertIsArray($top[0]);

        self::assertCount(20, $top);
        self::assertSame('Customer 22', $top[0]['name']);
    }

    public function test_that_a_sales_role_is_refused(): void
    {
        $this->getJson(self::ENDPOINT, $this->bearer($this->outsider))->assertStatus(403);
    }

    /**
     * October. Won chains (latest sent version accepted):
     * - member: Delta Medical (medical) EGP 1000, base 1000, lines Widget 900 · Gadget 100;
     *           Cairo Clinic (medical) EGP 500, base 500, line Widget 500
     * - outsider: Nile Bank (banks) USD 20, base 1000, line Gadget 20 (base 1000)
     * Outcomes: member won 2, lost 2 (price_too_high); outsider won 1, lost 1 price_too_high + 1 other.
     */
    private function seedTheMonth(): void
    {
        $widget = $this->product('Widget');
        $gadget = $this->product('Gadget');

        $a = $this->version('A', 'Delta Medical', 'medical', $this->member->id, 'EGP', '1000', '1000');
        $this->line($a, $widget, '900', '900');
        $this->line($a, $gadget, '100', '100');
        $b = $this->version('B', 'Cairo Clinic', 'medical', $this->member->id, 'EGP', '500', '500');
        $this->line($b, $widget, '500', '500');
        $c = $this->version('C', 'Nile Bank', 'banks', $this->outsider->id, 'USD', '20', '1000');
        $this->line($c, $gadget, '20', '1000');

        $this->outcome($this->member->id, 'won', 2, null);
        $this->outcome($this->member->id, 'lost', 2, 'price_too_high');
        $this->outcome($this->outsider->id, 'won', 1, null);
        $this->outcome($this->outsider->id, 'lost', 1, 'price_too_high');
        $this->outcome($this->outsider->id, 'lost', 1, 'other');
    }

    /** @var array<string, string> */
    private array $customers = [];

    private function version(string $chain, string $customer, string $sector, string $ownerId, string $currency, string $net, string $baseNet): string
    {
        $id = (string) Str::uuid7();
        $this->customers[$customer] ??= $this->customer($customer);
        DB::table('dashboard_sent_versions')->insert([
            'quotation_id' => $id, 'chain_id' => $id, 'version' => 1, 'sent_day' => '2026-10-05', 'owner_id' => $ownerId,
            'currency' => $currency, 'status' => 'accepted', 'net_amount' => $net, 'additional_total' => '0', 'cost' => '0',
            'base_net_amount' => $baseNet, 'base_additional_total' => '0', 'base_cost' => '0',
            'customer_id' => $this->customers[$customer], 'sector' => $sector,
        ]);

        return $id;
    }

    private function line(string $quotationId, string $catalogItemId, string $total, string $baseTotal): void
    {
        DB::table('dashboard_sent_lines')->insert(['quotation_id' => $quotationId, 'catalog_item_id' => $catalogItemId, 'line_total' => $total, 'base_line_total' => $baseTotal]);
    }

    private function outcome(string $ownerId, string $outcome, int $deals, ?string $reason): void
    {
        DB::table('dashboard_daily_outcomes')->insert([
            'day' => '2026-10-10', 'owner_id' => $ownerId, 'outcome' => $outcome, 'deals' => $deals, 'cycle_days' => 0, 'reason_code' => $reason,
        ]);
    }

    private function customer(string $name): string
    {
        $id = (string) Str::uuid7();
        DB::table('customers')->insert(['id' => $id, 'name' => $name, 'created_at' => '2020-01-01', 'updated_at' => '2020-01-01']);

        return $id;
    }

    private function product(string $name): string
    {
        $id = (string) Str::uuid7();
        DB::table('catalog_items')->insert(['id' => $id, 'kind' => 'product', 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** @return array<string, mixed> */
    private function breakdowns(RoleName|User $who): array
    {
        DB::table('currencies')->updateOrInsert(['code' => 'EGP'], ['id' => (string) Str::uuid7(), 'rounding_unit' => '1', 'rounding_enabled' => true, 'is_base' => true, 'created_at' => now(), 'updated_at' => now()]);
        $data = $this->getJson(self::ENDPOINT, $this->bearer($who))->assertOk()->json('data');
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function user(RoleName $role, string $tag, ?User $leader = null): User
    {
        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label().' '.$tag, 'email' => str_replace('_', '.', $role->value).'.'.$tag.'@example.test', 'password' => self::PASSWORD,
            'role_id' => Role::query()->where('slug', $role->value)->firstOrFail()->id, 'is_active' => true, 'is_hidden' => $role->isHidden(),
        ]);
        $user->save();
        if ($leader !== null) {
            DB::table('users')->where('id', $user->id)->update(['team_leader_id' => $leader->id]);
        }

        return $user;
    }

    /** @return array<string, string> */
    private function bearer(RoleName|User $who): array
    {
        $user = $who instanceof User ? $who : $this->user($who, 'x');
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertStatus(201)->json('data.token');
        self::assertIsString($token);

        return ['Authorization' => 'Bearer '.$token];
    }
}
