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
 * `PATCH /api/v1/quotations/{id}/submit-for-approval` — Module 7 Point 4.2.
 *
 * §6.4's first arrow, `Draft ──submit──► Pending`, through Point 4.1's edge
 * table. §3.5's `submit for approval` row: Manager All, Team Leader Team,
 * Outdoor and Indoor Sales Own, nothing for Procurement and the CEO. `If-Match`
 * on 3.6's terms (`DB-12`, `API-12`); no `Idempotency-Key` (the owner's Q6
 * ruling — a repeated submit is already a `409` by the token). `submitted_at`
 * is set (Q2) and `QUOTATION_SUBMITTED` audited with old and new status
 * (`AUD-01`).
 */
final class QuotationSubmitEndpointTest extends TestCase
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

    public function test_that_an_unauthenticated_caller_cannot_submit(): void
    {
        $this->patchJson(self::ENDPOINT.'/'.Uuid::uuid4()->toString().'/submit-for-approval')->assertStatus(401);
    }

    public function test_that_the_manager_submits_any_draft(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->submit($id, $etag, RoleName::Manager)->assertStatus(200);
    }

    /** @return array<string, array{RoleName}> */
    public static function ownScoped(): array
    {
        return ['outdoor sales' => [RoleName::OutdoorSales], 'indoor sales' => [RoleName::IndoorSales]];
    }

    #[DataProvider('ownScoped')]
    public function test_that_an_own_scoped_role_submits_its_own_deals_draft(RoleName $role): void
    {
        [$id, $etag] = $this->quotation($this->deal($this->userWith($role)->id));

        $this->submit($id, $etag, $role)->assertStatus(200);
    }

    /** §5.1: out of reach is a 404, indistinguishable from absent. */
    #[DataProvider('ownScoped')]
    public function test_that_an_own_scoped_role_cannot_submit_another_owners_draft(RoleName $role): void
    {
        [$id, $etag] = $this->quotation($this->deal($this->userWith(RoleName::Manager)->id));

        $this->submit($id, $etag, $role)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    /** `team` is granted and unbacked — fails closed, as the read and the edit do. */
    public function test_that_the_team_leader_sees_nothing_until_teams_exist(): void
    {
        [$id, $etag] = $this->quotation($this->deal($this->userWith(RoleName::TeamLeader)->id));

        $this->submit($id, $etag, RoleName::TeamLeader)->assertStatus(404);
    }

    /**
     * §3.5's `submit for approval` cell is `—` for Procurement and the CEO, and
     * the Outdoor Supervisor has no quotation row at all.
     *
     * @return array<string, array{RoleName}>
     */
    public static function nonSubmitters(): array
    {
        return [
            'procurement' => [RoleName::Procurement],
            'ceo' => [RoleName::Ceo],
            'outdoor supervisor' => [RoleName::OutdoorSupervisor],
        ];
    }

    #[DataProvider('nonSubmitters')]
    public function test_that_a_role_without_the_grant_cannot_submit(RoleName $role): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->submit($id, $etag, $role)->assertStatus(403);
    }

    /** `SEC-07`: the grant is a database row, and withdrawing it withdraws the route. */
    public function test_that_withdrawing_the_grant_refuses_a_role_that_had_it(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'submit_for_approval')->delete();

        $this->submit($id, $etag, RoleName::Manager)->assertStatus(403);
    }

    // ──────────────────────────────────────────────────── optimistic locking

    public function test_that_a_missing_if_match_is_a_400(): void
    {
        [$id] = $this->quotation($this->deal(null));

        $this->patchJson(self::ENDPOINT.'/'.$id.'/submit-for-approval', [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonPath('error.details.0.field', 'If-Match')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::IF_MATCH_REQUIRED);
    }

    /** `API-12`: a stale token is `409 concurrency_conflict` and names the current etag; nothing moves. */
    public function test_that_a_stale_token_is_a_409_with_the_current_etag(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->submit($id, 'quotation:'.$id.':0', RoleName::Manager)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'concurrency_conflict')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::STALE_VERSION)
            ->assertJsonPath('error.details.0.current_etag', $etag);

        self::assertSame('draft', DB::table('quotations')->where('id', $id)->value('status'));
    }

    /** The list's verifier: a second submit with the etag that just succeeded is `409 concurrency_conflict`, not a transition error. */
    public function test_that_a_second_submit_with_the_old_etag_is_a_concurrency_conflict(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->submit($id, $etag, RoleName::Manager)
            ->assertStatus(200)
            ->assertJsonPath('data.etag', 'quotation:'.$id.':2');

        $this->submit($id, $etag, RoleName::Manager)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'concurrency_conflict');
    }

    // ───────────────────────────────────────────────────── the transition

    /** §6.4 draws no arrow from `pending` to `pending`: a fresh etag on a submitted quotation is `409 state_transition_invalid`. */
    public function test_that_submitting_a_pending_quotation_is_a_transition_error(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));
        $this->submit($id, $etag, RoleName::Manager)->assertStatus(200);
        $fresh = 'quotation:'.$id.':2';

        $this->submit($id, $fresh, RoleName::Manager)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'state_transition_invalid')
            ->assertJsonPath('error.details.0.field', 'status')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::INVALID_TRANSITION);

        self::assertSame(2, DB::table('quotations')->where('id', $id)->value('version_token'), 'a refused transition must not move the token.');
    }

    /** `Draft ──submit──► Pending`, `submitted_at` set (Q2), the token advanced, §6.3's version untouched, 3.5's body returned. */
    public function test_that_a_submit_moves_the_draft_to_pending(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $response = $this->submit($id, $etag, RoleName::Manager)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.etag', 'quotation:'.$id.':2')
            ->assertJsonPath('data.items.0.quantity', '2.0000');

        $submittedAt = $response->json('data.submitted_at');
        self::assertIsString($submittedAt);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $submittedAt, '`DB-08`: an ISO-8601 instant in UTC.');

        $row = DB::table('quotations')->where('id', $id)->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertSame('pending', $row->status);
        self::assertNotNull($row->submitted_at);
    }

    /** A draft carries no submission moment until it is submitted. */
    public function test_that_a_draft_has_no_submitted_at(): void
    {
        [$id] = $this->quotation($this->deal(null));

        $data = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'draft')
            ->json('data');

        self::assertIsArray($data);
        // `assertJsonPath(…, null)` is satisfied by an absent key; the field must be present and empty.
        self::assertArrayHasKey('submitted_at', $data);
        self::assertNull($data['submitted_at']);
    }

    // ────────────────────────────────────────────────────────────── audit

    /** `AUD-01`: `QUOTATION_SUBMITTED` with the old and new status. */
    public function test_that_a_submit_is_written_to_the_audit_log(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->submit($id, $etag, RoleName::Manager)->assertStatus(200);

        $row = DB::table('audit_log')->where('entity_type', 'quotation')->where('entity_id', $id)->where('event', 'QUOTATION_SUBMITTED')->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertIsString($row->old_values);
        self::assertIsString($row->new_values);
        $old = json_decode($row->old_values, true);
        $new = json_decode($row->new_values, true);
        self::assertIsArray($old);
        self::assertIsArray($new);
        self::assertSame('draft', $old['status'] ?? null);
        self::assertNull($old['submitted_at'] ?? null);
        self::assertSame('pending', $new['status'] ?? null);
        self::assertIsString($new['submitted_at'] ?? null);
    }

    public function test_that_an_unknown_id_is_a_404(): void
    {
        $id = Uuid::uuid4()->toString();

        $this->submit($id, 'quotation:'.$id.':1', RoleName::Manager)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    // ──────────────────────────────────────────────────────────── fixtures

    /** @return TestResponse<\Symfony\Component\HttpFoundation\Response> */
    private function submit(string $id, string $ifMatch, RoleName $role): TestResponse
    {
        return $this->patchJson(self::ENDPOINT.'/'.$id.'/submit-for-approval', [], [...$this->bearerFor($role), 'If-Match' => $ifMatch]);
    }

    /**
     * Creates a quotation through Point 3.4's endpoint and reads its etag
     * through Point 3.5's: one line of 2 × 10 at 20 % margin, plus 5 delivery.
     *
     * @return array{string, string} id and etag
     */
    private function quotation(string $dealId): array
    {
        $id = $this->postJson(self::ENDPOINT, [
            'deal_id' => $dealId,
            'customer_id' => $this->customerId,
            'currency' => 'EGP',
            'default_margin' => '20',
            'discount_percent' => '0',
            'lines' => [['supplier_quotation_item_id' => $this->lineId, 'quantity' => '2']],
            'additional_items' => [['description' => 'Delivery', 'amount' => '5']],
        ], ['Idempotency-Key' => Uuid::uuid4()->toString()] + $this->bearerFor(RoleName::Manager))->assertStatus(201)->json('data.id');

        self::assertIsString($id);

        $etag = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))->assertStatus(200)->json('data.etag');
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
