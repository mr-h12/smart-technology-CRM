<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Presentation\RefreshDashboardAggregatesJob;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Feature\Pdf\InsertsQuotationRows;
use Tests\TestCase;

/**
 * Module 14, slice 3 · 3.1 — J-09's money projection (`D-141`, `D-144`, `D-148`, `D-149`).
 *
 * `dashboard_sent_versions`: one row per live quotation version that was sent,
 * with its chain (the root version's id), its company day of sending, its deal's
 * current owner, its status now, its amounts as stored, its live line cost, and
 * the same three amounts in the base currency at the rate in force when the
 * version was created (`D-149`) — null when that pair had no rate then. Rebuilt
 * whole on every run: a status, an owner or a line can change on any day.
 */
final class RefreshDashboardMoneyTest extends TestCase
{
    use InsertsQuotationRows;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 21)->setTime(9, 0));
    }

    public function test_that_each_sent_version_is_one_row_with_its_chain_day_owner_status_amounts_and_cost(): void
    {
        $sara = $this->user('sara');
        $v1 = $this->pricedQuotation('partial', '2026-10-05 10:00:00', 'EGP', '1000', additional: '100', lineCosts: ['400', '200'], deletedLineCosts: ['999']);
        $v2 = $this->pricedQuotation('accepted', '2026-10-08 10:00:00', 'EGP', '900', additional: '100', lineCosts: ['500'], parentId: $v1);
        $this->ownDealOf($v1, $sara->id);
        $this->pricedQuotation('draft', null, 'EGP', '50');

        $this->refresh();

        self::assertSame([
            [$v1, $v1, 1, '2026-10-05', $sara->id, 'EGP', 'partial', '1100.000000', '100.000000', '600.000000', '1100.000000', '100.000000', '600.000000'],
            [$v2, $v1, 2, '2026-10-08', $sara->id, 'EGP', 'accepted', '1000.000000', '100.000000', '500.000000', '1000.000000', '100.000000', '500.000000'],
        ], $this->projection());
    }

    public function test_that_base_amounts_use_the_rate_in_force_when_the_version_was_created_and_stay_null_without_one(): void
    {
        $this->currencyId('EGP');
        $this->rate('USD', 'EGP', '50', '2026-10-01 00:00:00');
        $this->rate('USD', 'EGP', '60', '2026-10-10 00:00:00');

        $this->travelTo(now()->setDate(2026, 10, 5)->setTime(9, 0));
        $usd = $this->pricedQuotation('accepted', '2026-10-05 10:00:00', 'USD', '10', additional: '2', lineCosts: ['4']);
        $eur = $this->pricedQuotation('accepted', '2026-10-05 10:00:00', 'EUR', '10');
        // A rate recorded after the version was created never moves it.
        $this->travelTo(now()->setDate(2026, 10, 21)->setTime(9, 0));

        $this->refresh();

        $rows = array_column($this->projection(), null, 0);
        self::assertSame(['600.000000', '100.000000', '200.000000'], array_slice($rows[$usd], 10, 3));
        self::assertSame([null, null, null], array_slice($rows[$eur], 10, 3));
    }

    public function test_that_a_soft_deleted_version_is_not_a_row(): void
    {
        $id = $this->pricedQuotation('accepted', '2026-10-05 10:00:00', 'EGP', '1000');
        DB::table('quotations')->where('id', $id)->update(['deleted_at' => now()]);

        $this->refresh();

        self::assertSame([], $this->projection());
    }

    private function refresh(): void
    {
        $this->app->instance('request', Request::create('http://localhost'));
        $this->app->call([new RefreshDashboardAggregatesJob(full: false), 'handle']);
    }

    /** @return list<list<mixed>> */
    private function projection(): array
    {
        return array_values(array_map(
            static fn (object $r): array => [
                $r->quotation_id, $r->chain_id, is_numeric($r->version) ? (int) $r->version : null, $r->sent_day, $r->owner_id, $r->currency, $r->status,
                $r->net_amount, $r->additional_total, $r->cost, $r->base_net_amount, $r->base_additional_total, $r->base_cost,
            ],
            DB::table('dashboard_sent_versions')->orderBy('sent_day')->orderBy('version')->get()->all(),
        ));
    }

    private function ownDealOf(string $quotationId, string $ownerId): void
    {
        DB::table('deals')->where('id', DB::table('quotations')->where('id', $quotationId)->value('deal_id'))->update(['owner_id' => $ownerId]);
    }

    private function rate(string $from, string $to, string $rate, string $effectiveFrom): void
    {
        DB::table('fx_rates')->insert([
            'id' => Uuid::uuid7()->toString(), 'from_currency_id' => $this->currencyId($from), 'to_currency_id' => $this->currencyId($to),
            'rate' => $rate, 'effective_from' => $effectiveFrom, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function user(string $tag): User
    {
        $user = new User;
        $user->fill([
            'name' => 'Test '.$tag, 'email' => $tag.'@example.test', 'password' => 'Passw0rd123',
            'role_id' => Role::query()->where('slug', RoleName::IndoorSales->value)->firstOrFail()->id,
            'is_active' => true, 'is_hidden' => false,
        ]);
        $user->save();

        return $user;
    }
}
