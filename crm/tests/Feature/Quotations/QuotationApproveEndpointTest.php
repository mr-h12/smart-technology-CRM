<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Quotations\Domain\Writing\QuotationWriteRefused;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use stdClass;
use Tests\TestCase;

/**
 * `PATCH /api/v1/quotations/{id}/approve` — Module 8 Point 1.1.
 *
 * §6.4's `Pending ──approve──► Approved` through Point 4.1's edge table.
 * §3.5's `approve` row: Manager All, Team Leader Team (`D-a`: fails closed
 * until a team entity exists), nothing else. `If-Match` on 3.6's terms
 * (`DB-12`, `API-12`). §6.5 / `D-50`: when the approver is the quotation's
 * `created_by` (Q3) the row is flagged `is_self_approved` and the audit entry
 * is typed `SELF_APPROVAL` **instead of** `QUOTATION_APPROVED`.
 */
final class QuotationApproveEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/quotations';

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

    // ────────────────────────────────────────────────────────── authorisation

    public function test_that_an_unauthenticated_caller_cannot_approve(): void
    {
        $this->patchJson(self::ENDPOINT.'/'.Uuid::uuid4()->toString().'/approve')->assertStatus(401);
    }

    public function test_that_the_manager_approves_any_pending_quotation(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);

        $this->approve($id, $etag, RoleName::Manager)->assertStatus(200);
    }

    /** `D-a`: `team` is granted and unbacked — fails closed, as submit, edit and read do. */
    public function test_that_the_team_leader_sees_nothing_until_teams_exist(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);

        $this->approve($id, $etag, RoleName::TeamLeader)->assertStatus(404);
    }

    /**
     * §3.5's `approve` cell is `—` for every other role.
     *
     * @return array<string, array{RoleName}>
     */
    public static function nonApprovers(): array
    {
        return [
            'indoor sales' => [RoleName::IndoorSales],
            'outdoor sales' => [RoleName::OutdoorSales],
            'procurement' => [RoleName::Procurement],
            'ceo' => [RoleName::Ceo],
            'outdoor supervisor' => [RoleName::OutdoorSupervisor],
        ];
    }

    #[DataProvider('nonApprovers')]
    public function test_that_a_role_without_the_grant_cannot_approve(RoleName $role): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);

        $this->approve($id, $etag, $role)->assertStatus(403);

        self::assertSame('pending', DB::table('quotations')->where('id', $id)->value('status'));
    }

    /** `SEC-07`: the grant is a database row, and withdrawing it withdraws the route. */
    public function test_that_withdrawing_the_grant_refuses_a_role_that_had_it(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'approve')->delete();

        $this->approve($id, $etag, RoleName::Manager)->assertStatus(403);
    }

    // ──────────────────────────────────────────────────── optimistic locking

    public function test_that_a_missing_if_match_is_a_400(): void
    {
        [$id] = $this->pending(RoleName::IndoorSales);

        $this->patchJson(self::ENDPOINT.'/'.$id.'/approve', [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonPath('error.details.0.field', 'If-Match')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::IF_MATCH_REQUIRED);
    }

    /** `API-12`: a stale token is `409 concurrency_conflict` and names the current etag; nothing moves. */
    public function test_that_a_stale_token_is_a_409_with_the_current_etag(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);

        $this->approve($id, 'quotation:'.$id.':1', RoleName::Manager)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'concurrency_conflict')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::STALE_VERSION)
            ->assertJsonPath('error.details.0.current_etag', $etag);

        self::assertSame('pending', DB::table('quotations')->where('id', $id)->value('status'));
    }

    // ───────────────────────────────────────────────────── the transition

    /** §6.4 draws no arrow from `draft` to `approved`: an unsubmitted quotation is `409 state_transition_invalid`. */
    public function test_that_approving_a_draft_is_a_transition_error(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null), RoleName::Manager);

        $this->approve($id, $etag, RoleName::Manager)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'state_transition_invalid')
            ->assertJsonPath('error.details.0.field', 'status')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::INVALID_TRANSITION);

        self::assertSame('draft', DB::table('quotations')->where('id', $id)->value('status'));
        self::assertSame(1, DB::table('quotations')->where('id', $id)->value('version_token'), 'a refused transition must not move the token.');
    }

    /** Nor from `approved` to `approved`: a second approve with the fresh etag is a transition error, not a concurrency one. */
    public function test_that_approving_an_approved_quotation_is_a_transition_error(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);
        $this->approve($id, $etag, RoleName::Manager)->assertStatus(200);

        $this->approve($id, 'quotation:'.$id.':3', RoleName::Manager)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'state_transition_invalid');
    }

    /** `Pending ──approve──► Approved`, the token advanced, §6.3's version untouched, 3.5's body returned. */
    public function test_that_an_approve_moves_the_pending_quotation_to_approved(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);

        $this->approve($id, $etag, RoleName::Manager)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.is_self_approved', false)
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.etag', 'quotation:'.$id.':3')
            ->assertJsonPath('data.items.0.quantity', '2.0000');

        $row = DB::table('quotations')->where('id', $id)->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertSame('approved', $row->status);
        self::assertFalse((bool) $row->is_self_approved);
        self::assertNotNull($row->submitted_at, 'the submission moment survives the approval — "days waiting" was measured from it.');
    }

    // ──────────────────────────────────────────────── self-approval (§6.5)

    /** `D-50`: the approver is `created_by` → `is_self_approved = true`. */
    public function test_that_approving_ones_own_quotation_flags_it_as_self_approved(): void
    {
        [$id, $etag] = $this->pending(RoleName::Manager);

        $this->approve($id, $etag, RoleName::Manager)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.is_self_approved', true);

        self::assertTrue((bool) DB::table('quotations')->where('id', $id)->value('is_self_approved'));
    }

    // ────────────────────────────────────────────────────────────── audit

    /** `AUD-01`: `QUOTATION_APPROVED` with the old and new status, and no `SELF_APPROVAL` row. */
    public function test_that_an_approve_by_another_user_is_audited_as_a_normal_approval(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);

        $this->approve($id, $etag, RoleName::Manager)->assertStatus(200);

        $row = $this->auditRow($id, 'QUOTATION_APPROVED');
        self::assertInstanceOf(stdClass::class, $row);
        self::assertIsString($row->old_values);
        self::assertIsString($row->new_values);
        $old = json_decode($row->old_values, true);
        $new = json_decode($row->new_values, true);
        self::assertIsArray($old);
        self::assertIsArray($new);
        self::assertSame('pending', $old['status'] ?? null);
        self::assertSame('approved', $new['status'] ?? null);
        self::assertFalse($new['is_self_approved'] ?? null);

        self::assertNull($this->auditRow($id, 'SELF_APPROVAL'));
    }

    /** §6.5: the entry is typed `SELF_APPROVAL` — **not** a normal approval — so the row is `SELF_APPROVAL` and there is no `QUOTATION_APPROVED`. */
    public function test_that_a_self_approval_is_audited_as_self_approval_instead_of_a_normal_approval(): void
    {
        [$id, $etag] = $this->pending(RoleName::Manager);

        $this->approve($id, $etag, RoleName::Manager)->assertStatus(200);

        $row = $this->auditRow($id, 'SELF_APPROVAL');
        self::assertInstanceOf(stdClass::class, $row);
        self::assertIsString($row->new_values);
        $new = json_decode($row->new_values, true);
        self::assertIsArray($new);
        self::assertSame('approved', $new['status'] ?? null);
        self::assertTrue($new['is_self_approved'] ?? null);

        self::assertNull($this->auditRow($id, 'QUOTATION_APPROVED'));
    }

    public function test_that_an_unknown_id_is_a_404(): void
    {
        $id = Uuid::uuid4()->toString();

        $this->approve($id, 'quotation:'.$id.':1', RoleName::Manager)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    // ──────────────────────────────────────────────────────────── fixtures

    /** @return TestResponse<\Symfony\Component\HttpFoundation\Response> */
    private function approve(string $id, string $ifMatch, RoleName $role): TestResponse
    {
        return $this->patchJson(self::ENDPOINT.'/'.$id.'/approve', [], [...$this->bearerFor($role), 'If-Match' => $ifMatch]);
    }

    private function auditRow(string $id, string $event): ?stdClass
    {
        $row = DB::table('audit_log')->where('entity_type', 'quotation')->where('entity_id', $id)->where('event', $event)->first();

        return $row instanceof stdClass ? $row : null;
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
