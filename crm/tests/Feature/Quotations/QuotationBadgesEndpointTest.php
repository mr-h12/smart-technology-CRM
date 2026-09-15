<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * `GET /api/v1/badges` — Module 8 Point 2.3 (Q5). §18.1 and Design System
 * §5.1 permit the *Approvals* and *My Quotations* sidebar counters; this is
 * the one read that feeds both: `approvals` is the `pending` quotations within
 * the caller's `quotation.approve` reach (`SEC-08`), `my_quotations` the
 * caller's own returned drafts (2.2's `incomplete`, `own` reach). `auth`
 * only — a role without the grant is answered `0`, not `403`, because a
 * sidebar is drawn for everyone.
 */
final class QuotationBadgesEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/quotations';

    private const BADGES = '/api/v1/badges';

    private const PASSWORD = 'Passw0rd123';

    /** @var array<string, User> */
    private array $users = [];

    private string $customerId;

    private string $lineId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->currency('EGP', '1', false, true);
        $this->customerId = $this->customer();
        $this->lineId = $this->supplierLine('10', '5');
    }

    public function test_that_an_anonymous_caller_is_refused(): void
    {
        $this->getJson(self::BADGES)->assertStatus(401);
    }

    /** The Manager holds `approve · All`: every pending quotation counts, and none of the drafts. */
    public function test_that_the_manager_counts_every_pending_quotation(): void
    {
        $this->pending(RoleName::IndoorSales);
        $this->pending(RoleName::IndoorSales);
        $this->quotation($this->deal($this->userWith(RoleName::IndoorSales)->id), RoleName::IndoorSales);

        $this->getJson(self::BADGES, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJson(['data' => ['approvals' => 2, 'my_quotations' => 0]]);
    }

    /** Sales holds no `approve` cell: a zero, not a 403. Their own returned draft is counted once; a plain draft is not. */
    public function test_that_sales_sees_zero_approvals_and_their_own_returned_draft_once(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);
        $this->quotation($this->deal($this->userWith(RoleName::IndoorSales)->id), RoleName::IndoorSales);

        $this->getJson(self::BADGES, $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(200)
            ->assertJson(['data' => ['approvals' => 0, 'my_quotations' => 0]]);

        $this->patchJson(self::ENDPOINT.'/'.$id.'/return', ['note' => 'Price'], [...$this->bearerFor(RoleName::Manager), 'If-Match' => $etag])
            ->assertStatus(200);

        $this->getJson(self::BADGES, $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(200)
            ->assertJson(['data' => ['approvals' => 0, 'my_quotations' => 1]]);
        $this->getJson(self::BADGES, $this->bearerFor(RoleName::IndoorSales))
            ->assertJsonPath('data.my_quotations', 1);
    }

    /** Another seller's returned draft is not mine. */
    public function test_that_a_returned_draft_of_another_seller_is_not_counted(): void
    {
        [$id, $etag] = $this->pending(RoleName::OutdoorSales);
        $this->patchJson(self::ENDPOINT.'/'.$id.'/return', ['note' => 'Price'], [...$this->bearerFor(RoleName::Manager), 'If-Match' => $etag])
            ->assertStatus(200);

        $this->getJson(self::BADGES, $this->bearerFor(RoleName::IndoorSales))
            ->assertJson(['data' => ['approvals' => 0, 'my_quotations' => 0]]);
    }

    /** `D-a`: the Team Leader's `approve · Team` reaches no rows until a team entity exists — zero, consistently with the 404 on the actions. */
    public function test_that_the_team_leader_counts_nothing_until_team_exists(): void
    {
        $this->pending(RoleName::IndoorSales);

        $this->getJson(self::BADGES, $this->bearerFor(RoleName::TeamLeader))
            ->assertStatus(200)
            ->assertJson(['data' => ['approvals' => 0, 'my_quotations' => 0]]);
    }

    /**
     * A quotation `$creator` built on their own deal and submitted (4.2), so
     * `created_by` is `$creator` — the fact Q3 keys self-approval on.
     *
     * @return array{string, string} id and the etag after the submit
     */
    private function pending(RoleName $creator): array
    {
        [$id, $etag] = $this->quotation($this->deal($this->userWith($creator)->id), $creator);

        $etag = $this->patchJson(self::ENDPOINT.'/'.$id.'/submit-for-approval', [], [...$this->bearerFor($creator), 'If-Match' => $etag])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'pending')
            ->json('data.etag');
        self::assertIsString($etag);

        return [$id, $etag];
    }

    /**
     * Creates a quotation through Point 3.4's endpoint as `$creator` and reads
     * its etag through Point 3.5's: one line of 2 × 10 at 20 % margin, plus 5
     * delivery.
     *
     * @return array{string, string} id and etag
     */
    private function quotation(string $dealId, RoleName $creator): array
    {
        $id = $this->postJson(self::ENDPOINT, [
            'deal_id' => $dealId,
            'customer_id' => $this->customerId,
            'currency' => 'EGP',
            'default_margin' => '20',
            'discount_percent' => '0',
            'lines' => [['supplier_quotation_item_id' => $this->lineId, 'quantity' => '2']],
            'additional_items' => [['description' => 'Delivery', 'amount' => '5']],
        ], ['Idempotency-Key' => Uuid::uuid4()->toString()] + $this->bearerFor($creator))->assertStatus(201)->json('data.id');

        self::assertIsString($id);

        $etag = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor($creator))->assertStatus(200)->json('data.etag');
        self::assertIsString($etag);

        return [$id, $etag];
    }

    private function currency(string $code, string $unit, bool $enabled, bool $base): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('currencies')->insert([
            'id' => $id,
            'code' => $code,
            'rounding_unit' => $unit,
            'rounding_enabled' => $enabled,
            'is_base' => $base,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function currencyId(string $code): string
    {
        $id = DB::table('currencies')->where('code', $code)->value('id');
        self::assertIsString($id);

        return $id;
    }

    private function customer(): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('customers')->insert([
            'id' => $id,
            'name' => 'Nile Trading',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function deal(?string $ownerId): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('deals')->insert([
            'id' => $id,
            'code' => 'DL-'.now()->format('Y').'-'.substr($id, 0, 4),
            'customer_id' => $this->customerId,
            'owner_id' => $ownerId,
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** Seeds §4.1's chain in EGP and returns the `supplier_quotation_items` id a line points at. */
    private function supplierLine(string $unitPrice, string $quantity): string
    {
        $supplierId = Uuid::uuid4()->toString();
        $catalogItemId = Uuid::uuid4()->toString();
        $offerId = Uuid::uuid4()->toString();
        $lineId = Uuid::uuid4()->toString();

        DB::table('suppliers')->insert([
            'id' => $supplierId, 'name' => 'Alpha Supplies',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('catalog_items')->insert([
            'id' => $catalogItemId, 'kind' => 'product', 'name' => 'Widget',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supplier_quotations')->insert([
            'id' => $offerId,
            'code' => 'SQ-'.now()->format('Y').'-'.substr($lineId, 0, 4),
            'supplier_id' => $supplierId,
            'total_price' => '1',
            'currency_id' => $this->currencyId('EGP'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supplier_quotation_items')->insert([
            'id' => $lineId,
            'supplier_quotation_id' => $offerId,
            'catalog_item_id' => $catalogItemId,
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $lineId;
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
}
