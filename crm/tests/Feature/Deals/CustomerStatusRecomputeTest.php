<?php

declare(strict_types=1);

namespace Tests\Feature\Deals;

use App\Modules\Admin\Domain\Contracts\SystemLimitRepositoryInterface;
use App\Modules\Admin\Domain\Settings\SystemLimit;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module 5, Point 3.1 — §4.5's five conditions, wired to the two events that
 * change what they read: `POST /deals` and `PATCH /deals/{id}/status`.
 *
 * `SystemLimit` and `SystemLimitRepositoryInterface` are imported directly
 * from `Admin` here, unlike anywhere in `app/Modules/Deals` — deptrac scans
 * `app/Modules` only (`deptrac.modules.yaml`'s own `paths:`), so a test is
 * free to reach across a boundary application code may not. Going through
 * `put()` rather than inserting into `system_limits` directly is deliberate:
 * `DatabaseSettingReader` reads through `DatabaseSystemLimitRepository`'s
 * cache, and `SettingsCache`'s own docblock names a raw insert as the one path
 * that leaves that cache stale. `put()` is the path that invalidates it.
 */
final class CustomerStatusRecomputeTest extends TestCase
{
    use RefreshDatabase;

    private const DEALS = '/api/v1/deals';

    private const PASSWORD = 'Passw0rd123';

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function userWith(RoleName $role): User
    {
        if (isset($this->users[$role->value])) {
            return $this->users[$role->value];
        }

        $row = Role::query()->where('slug', $role->value)->firstOrFail();

        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label(),
            'email' => str_replace('_', '.', $role->value).'@example.test',
            'password' => self::PASSWORD,
            'role_id' => $row->id,
            'is_active' => true,
            'is_hidden' => $role->isHidden(),
        ]);
        $user->save();

        return $this->users[$role->value] = $user;
    }

    /** @return array<string, string> */
    private function bearerFor(RoleName $role): array
    {
        $user = $this->userWith($role);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(201)->json('data.token');

        self::assertIsString($token);

        return ['Authorization' => 'Bearer '.$token];
    }

    private function customer(): string
    {
        $id = (string) Str::uuid7();

        DB::table('customers')->insert([
            'id' => $id,
            'name' => 'Test Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function customerStatus(string $customerId): string
    {
        $status = DB::table('customers')->where('id', $customerId)->value('customer_status');

        self::assertIsString($status);

        return $status;
    }

    /** @param  array<string, mixed>  $overrides */
    private function seedDeal(string $customerId, array $overrides = []): string
    {
        $id = (string) Str::uuid7();

        DB::table('deals')->insert(array_merge([
            'id' => $id,
            'code' => 'DL-2026-'.substr(str_replace('-', '', $id), -4),
            'customer_id' => $customerId,
            'status' => 'lead',
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    private function statusUrl(string $dealId): string
    {
        return self::DEALS.'/'.$dealId.'/status';
    }

    // ─────────────────────────────── rule 5 and the create-time trigger

    public function test_that_a_brand_new_customers_first_deal_leaves_status_prospect(): void
    {
        $customerId = $this->customer();

        $this->postJson(self::DEALS, [
            'customer_id' => $customerId,
            'source' => 'employee_entry',
        ], $this->bearerFor(RoleName::Manager))->assertStatus(201);

        self::assertSame('prospect', $this->customerStatus($customerId));
    }

    // ─────────────────────────────── rule 1: permanent, and wins over rule 2

    public function test_that_reaching_won_marks_the_customer_a_customer_permanently(): void
    {
        $customerId = $this->customer();
        $dealId = $this->seedDeal($customerId, ['status' => 'quotation_sent']);

        $this->patchJson($this->statusUrl($dealId), ['status' => 'won'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);

        self::assertSame('customer', $this->customerStatus($customerId));

        // A brand-new active deal for the same customer does not undo rule 1.
        $this->postJson(self::DEALS, [
            'customer_id' => $customerId,
            'source' => 'employee_entry',
        ], $this->bearerFor(RoleName::Manager))->assertStatus(201);

        self::assertSame('customer', $this->customerStatus($customerId));
    }

    // ─────────────────────────────── rule 4, and rule 2 reviving it

    public function test_that_every_deal_lost_marks_deal_not_completed(): void
    {
        $customerId = $this->customer();
        $dealId = $this->seedDeal($customerId, ['status' => 'quotation_sent']);

        $this->patchJson(
            $this->statusUrl($dealId),
            ['status' => 'lost', 'reason' => 'Customer chose a competitor'],
            $this->bearerFor(RoleName::Manager),
        )->assertStatus(200);

        self::assertSame('deal_not_completed', $this->customerStatus($customerId));
    }

    public function test_that_a_new_deal_revives_a_deal_not_completed_customer_to_prospect(): void
    {
        $customerId = $this->customer();
        $this->seedDeal($customerId, ['status' => 'lost', 'lost_reason' => 'Budget cut']);
        DB::table('customers')->where('id', $customerId)->update(['customer_status' => 'deal_not_completed']);

        $this->postJson(self::DEALS, [
            'customer_id' => $customerId,
            'source' => 'employee_entry',
        ], $this->bearerFor(RoleName::Manager))->assertStatus(201);

        self::assertSame('prospect', $this->customerStatus($customerId));
    }

    // ─────────────────────────────── rule 3, and the unset-threshold precedent

    public function test_that_a_stale_active_deal_is_no_response_once_a_threshold_is_configured(): void
    {
        app(SystemLimitRepositoryInterface::class)->put(SystemLimit::StaleDealDays, '5');

        $customerId = $this->customer();

        // The only active deal, backdated well past the 5-day threshold —
        // seeded directly, since either wired trigger would stamp
        // `last_activity_at` to now() and defeat the setup.
        $this->seedDeal($customerId, [
            'status' => 'negotiations',
            'last_activity_at' => now()->subDays(30),
        ]);

        // A second deal that reaches Lost — the event that triggers this
        // recompute without touching the stale deal above.
        $secondDealId = $this->seedDeal($customerId, ['status' => 'quotation_sent']);

        $this->patchJson(
            $this->statusUrl($secondDealId),
            ['status' => 'lost', 'reason' => 'Went silent'],
            $this->bearerFor(RoleName::Manager),
        )->assertStatus(200);

        self::assertSame('no_response', $this->customerStatus($customerId));
    }

    public function test_that_a_stale_active_deal_stays_prospect_while_the_threshold_is_unconfigured(): void
    {
        // No `put()` call: `limits.stale_deal_days` is left exactly as
        // `SystemLimit`'s own docblock says it starts — unseeded.
        $customerId = $this->customer();

        $this->seedDeal($customerId, [
            'status' => 'negotiations',
            'last_activity_at' => now()->subDays(30),
        ]);

        $secondDealId = $this->seedDeal($customerId, ['status' => 'quotation_sent']);

        $this->patchJson(
            $this->statusUrl($secondDealId),
            ['status' => 'lost', 'reason' => 'Went silent'],
            $this->bearerFor(RoleName::Manager),
        )->assertStatus(200);

        self::assertSame('prospect', $this->customerStatus($customerId));
    }
}
