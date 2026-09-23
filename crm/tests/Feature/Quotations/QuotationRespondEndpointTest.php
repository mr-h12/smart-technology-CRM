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
 * Module 10 · 1.4 — `PATCH /quotations/{id}/respond` for `partial` and
 * `counter` (`OpenAPI §7.2`, §6.3, `D-08`): `sent → partial|counter` and the
 * new `draft` version written in the same transaction and named in the
 * answer (`new_version`). `counter` needs a reason (`rejection_reason_required`);
 * a field that belongs to another response is refused (owner, 2026-09-23, A);
 * `accepted` / `rejected` are refused until 1.6 / 1.5 (B). The deal does not
 * move (Q2).
 */
final class QuotationRespondEndpointTest extends TestCase
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

        $this->currency('EGP');
        $this->customerId = $this->customer();
        $this->lineId = $this->supplierLine();
    }

    // ────────────────────────────────────────────────────────── the transition

    public function test_partial_moves_sent_to_partial_and_writes_the_draft_copy(): void
    {
        $deal = $this->deal(null);
        [$id, $etag] = $this->sent($deal);

        $response = $this->respond($id, $etag, RoleName::Manager, ['response' => 'partial'])
            ->assertStatus(200)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.new_version.version', 2);

        self::assertNotSame($etag, $response->json('data.etag'));

        $copyId = $response->json('data.new_version.id');
        self::assertIsString($copyId);
        $copy = DB::table('quotations')->where('id', $copyId)->first();
        self::assertInstanceOf(stdClass::class, $copy);
        self::assertSame('draft', $copy->status);
        self::assertSame($id, $copy->parent_id);
        self::assertSame($copy->code, $response->json('data.new_version.code'));
        self::assertSame(1, DB::table('quotation_items')->where('quotation_id', $copyId)->count());

        self::assertSame('partial', $this->statusOf('quotations', $id));
        self::assertNull(DB::table('quotations')->where('id', $id)->value('rejection_reason'));
        self::assertSame('quotation_sent', $this->statusOf('deals', $deal));
        self::assertSame(0, DB::table('audit_log')->where('event', 'DEAL_STATUS_CHANGED')->count());
    }

    public function test_counter_stores_its_reason_on_the_parent_only(): void
    {
        $deal = $this->deal(null);
        [$id, $etag] = $this->sent($deal);

        $copyId = $this->respond($id, $etag, RoleName::Manager, ['response' => 'counter', 'reason' => 'Asks 10% off'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'counter')
            ->json('data.new_version.id');
        self::assertIsString($copyId);

        self::assertSame('Asks 10% off', DB::table('quotations')->where('id', $id)->value('rejection_reason'));
        self::assertNull(DB::table('quotations')->where('id', $copyId)->value('rejection_reason'));
        self::assertSame('draft', $this->statusOf('quotations', $copyId));
        self::assertSame('quotation_sent', $this->statusOf('deals', $deal));
    }

    /** @return array<string, array{array<string, string>}> */
    public static function counterWithoutAReason(): array
    {
        return [
            'missing' => [['response' => 'counter']],
            'blank' => [['response' => 'counter', 'reason' => '   ']],
        ];
    }

    /**
     * §6.3 "counter reasons are mandatory before the status change is accepted" — nothing is written.
     *
     * @param  array<string, string>  $body
     */
    #[DataProvider('counterWithoutAReason')]
    public function test_counter_without_a_reason_is_refused_and_writes_nothing(array $body): void
    {
        [$id, $etag] = $this->sent($this->deal(null));

        $this->respond($id, $etag, RoleName::Manager, $body)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.0.field', 'reason')
            ->assertJsonPath('error.details.0.code', 'rejection_reason_required')
            ->assertJsonPath('error.details.0.message', __('quotations.errors.rejection_reason_required'));

        $this->assertNothingWritten($id);
    }

    /** Q7: the copy stops carrying the earlier return's marks — they belong to the row that was returned. */
    public function test_the_copy_does_not_carry_the_earlier_return(): void
    {
        [$id, $etag] = $this->sent($this->deal(null));
        DB::table('quotations')->where('id', $id)->update(['returned_at' => now(), 'return_note' => 'Fix the margin']);

        $copyId = $this->respond($id, $etag, RoleName::Manager, ['response' => 'partial'])
            ->assertStatus(200)
            ->json('data.new_version.id');
        self::assertIsString($copyId);

        $copy = DB::table('quotations')->where('id', $copyId)->first();
        self::assertInstanceOf(stdClass::class, $copy);
        self::assertNull($copy->returned_at);
        self::assertNull($copy->return_note);
    }

    /** One transaction: a copy the database refuses (`DB-03`, one v2 per parent) leaves the parent `sent`. */
    public function test_a_refused_copy_rolls_the_status_back(): void
    {
        [$id, $etag] = $this->sent($this->deal(null));

        // An existing v2: open it by hand from `partial`, then put the parent back.
        DB::table('quotations')->where('id', $id)->update(['status' => 'partial']);
        $this->postJson(self::ENDPOINT.'/'.$id.'/new-version', [], ['Idempotency-Key' => Uuid::uuid4()->toString()] + $this->bearerFor(RoleName::Manager))
            ->assertStatus(201);
        DB::table('quotations')->where('id', $id)->update(['status' => 'sent']);

        $this->respond($id, $etag, RoleName::Manager, ['response' => 'partial'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'state_transition_invalid')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::VERSION_EXISTS);

        self::assertSame('sent', $this->statusOf('quotations', $id));
        self::assertSame(0, DB::table('audit_log')->where('event', 'QUOTATION_PARTIAL')->count());
    }

    public function test_responding_to_a_quotation_that_is_not_sent_is_a_transition_error(): void
    {
        [$id, $etag] = $this->sent($this->deal(null));
        DB::table('quotations')->where('id', $id)->update(['status' => 'approved']);

        $this->respond($id, $etag, RoleName::Manager, ['response' => 'partial'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'state_transition_invalid');

        self::assertSame(1, DB::table('quotations')->count());
    }

    public function test_the_two_responses_are_written_to_the_audit_log(): void
    {
        [$partialId, $partialEtag] = $this->sent($this->deal(null));
        [$counterId, $counterEtag] = $this->sent($this->deal(null));

        $copyId = $this->respond($partialId, $partialEtag, RoleName::Manager, ['response' => 'partial'])->assertStatus(200)->json('data.new_version.id');
        $this->respond($counterId, $counterEtag, RoleName::Manager, ['response' => 'counter', 'reason' => 'Too high'])->assertStatus(200);

        [$old, $new] = $this->audited('QUOTATION_PARTIAL', $partialId);
        self::assertSame('sent', $old['status'] ?? null);
        self::assertSame('partial', $new['status'] ?? null);

        [$old, $new] = $this->audited('QUOTATION_COUNTERED', $counterId);
        self::assertSame('sent', $old['status'] ?? null);
        self::assertSame('counter', $new['status'] ?? null);
        self::assertSame('Too high', $new['rejection_reason'] ?? null);

        self::assertIsString($copyId);
        [, $new] = $this->audited('QUOTATION_VERSION_CREATED', $copyId);
        self::assertSame($partialId, $new['parent_id'] ?? null);
        self::assertSame(2, DB::table('audit_log')->where('event', 'QUOTATION_VERSION_CREATED')->count());
    }

    // ──────────────────────────────────────────── the body's boundary (A, B)

    /**
     * Owner, 2026-09-23: a field that belongs to another response is refused, not dropped;
     * `accepted` and `rejected` wait for 1.6 and 1.5.
     *
     * @return array<string, array{array<string, string>, string}>
     */
    public static function refusedBodies(): array
    {
        return [
            'reason on partial' => [['response' => 'partial', 'reason' => 'x'], 'reason'],
            'po reference on partial' => [['response' => 'partial', 'customer_po_reference' => 'PO-7'], 'customer_po_reference'],
            'po date on counter' => [['response' => 'counter', 'reason' => 'x', 'po_date' => '2026-09-23'], 'po_date'],
            'accepted before 1.6' => [['response' => 'accepted', 'customer_po_reference' => 'PO-7', 'po_date' => '2026-09-23'], 'response'],
            'rejected before 1.5' => [['response' => 'rejected', 'reason' => 'x'], 'response'],
            'no response' => [[], 'response'],
        ];
    }

    /** @param array<string, string> $body */
    #[DataProvider('refusedBodies')]
    public function test_a_body_outside_partial_and_counter_is_refused(array $body, string $field): void
    {
        [$id, $etag] = $this->sent($this->deal(null));

        $this->respond($id, $etag, RoleName::Manager, $body)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.0.field', $field);

        $this->assertNothingWritten($id);
    }

    // ────────────────────────────────────────────────────── optimistic locking

    public function test_a_missing_if_match_is_a_400(): void
    {
        [$id] = $this->sent($this->deal(null));

        $this->patchJson(self::ENDPOINT.'/'.$id.'/respond', ['response' => 'partial'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::IF_MATCH_REQUIRED);
    }

    public function test_a_stale_token_is_a_409_and_writes_nothing(): void
    {
        [$id] = $this->sent($this->deal(null));

        $this->respond($id, 'quotation:'.$id.':0', RoleName::Manager, ['response' => 'partial'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'concurrency_conflict');

        $this->assertNothingWritten($id);
    }

    // ─────────────────────────────────────────────────────────── authorisation

    public function test_an_unauthenticated_caller_cannot_respond(): void
    {
        $this->patchJson(self::ENDPOINT.'/'.Uuid::uuid4()->toString().'/respond', ['response' => 'partial'])->assertStatus(401);
    }

    /** @return array<string, array{RoleName}> */
    public static function ownScoped(): array
    {
        return ['outdoor sales' => [RoleName::OutdoorSales], 'indoor sales' => [RoleName::IndoorSales]];
    }

    #[DataProvider('ownScoped')]
    public function test_an_own_scoped_role_responds_on_its_own_deals_quotation(RoleName $role): void
    {
        [$id, $etag] = $this->sent($this->deal($this->userWith($role)->id));

        $this->respond($id, $etag, $role, ['response' => 'partial'])->assertStatus(200)->assertJsonPath('data.status', 'partial');
    }

    /** §5.1: out of reach is a 404 — and nothing of the other owner's moves (`deal_id` only from the authorised quotation). */
    #[DataProvider('ownScoped')]
    public function test_an_own_scoped_role_cannot_respond_on_another_owners_quotation(RoleName $role): void
    {
        $deal = $this->deal($this->userWith(RoleName::Manager)->id);
        [$id, $etag] = $this->sent($deal);

        $this->respond($id, $etag, $role, ['response' => 'counter', 'reason' => 'x'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');

        $this->assertNothingWritten($id);
        self::assertSame('quotation_sent', $this->statusOf('deals', $deal));
    }

    /** `team` is granted and unbacked — fails closed (`D-a`). */
    public function test_the_team_leader_sees_nothing_until_teams_exist(): void
    {
        [$id, $etag] = $this->sent($this->deal($this->userWith(RoleName::TeamLeader)->id));

        $this->respond($id, $etag, RoleName::TeamLeader, ['response' => 'partial'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');

        $this->assertNothingWritten($id);
    }

    /**
     * §3.5's `record customer response` has no cell for Procurement, the CEO or the Outdoor Supervisor.
     *
     * @return array<string, array{RoleName}>
     */
    public static function nonResponders(): array
    {
        return [
            'procurement' => [RoleName::Procurement],
            'ceo' => [RoleName::Ceo],
            'outdoor supervisor' => [RoleName::OutdoorSupervisor],
        ];
    }

    #[DataProvider('nonResponders')]
    public function test_a_role_without_the_grant_cannot_respond(RoleName $role): void
    {
        [$id, $etag] = $this->sent($this->deal(null));

        $this->respond($id, $etag, $role, ['response' => 'partial'])->assertStatus(403);

        $this->assertNothingWritten($id);
    }

    public function test_an_unknown_id_is_a_404(): void
    {
        $id = Uuid::uuid4()->toString();

        $this->respond($id, 'quotation:'.$id.':1', RoleName::Manager, ['response' => 'partial'])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    // ──────────────────────────────────────────────────────────────── fixtures

    /**
     * @param  array<string, string>  $body
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function respond(string $id, string $ifMatch, RoleName $role, array $body): TestResponse
    {
        return $this->patchJson(self::ENDPOINT.'/'.$id.'/respond', $body, [...$this->bearerFor($role), 'If-Match' => $ifMatch]);
    }

    /** The parent is still `sent` with no reason, no copy exists, and no response was audited. */
    private function assertNothingWritten(string $id): void
    {
        self::assertSame('sent', $this->statusOf('quotations', $id));
        self::assertNull(DB::table('quotations')->where('id', $id)->value('rejection_reason'));
        self::assertSame(0, DB::table('quotations')->where('parent_id', $id)->count());
        self::assertSame(0, DB::table('audit_log')->whereIn('event', ['QUOTATION_PARTIAL', 'QUOTATION_COUNTERED', 'QUOTATION_VERSION_CREATED'])->count());
    }

    /** @return array{array<mixed>, array<mixed>} old and new values */
    private function audited(string $event, string $entityId): array
    {
        $row = DB::table('audit_log')->where('entity_type', 'quotation')->where('entity_id', $entityId)->where('event', $event)->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertIsString($row->new_values);
        $old = is_string($row->old_values) ? json_decode($row->old_values, true) : [];
        $new = json_decode($row->new_values, true);
        self::assertIsArray($old);
        self::assertIsArray($new);

        return [$old, $new];
    }

    private function statusOf(string $table, string $id): string
    {
        $status = DB::table($table)->where('id', $id)->value('status');
        self::assertIsString($status);

        return $status;
    }

    /**
     * A draft through Point 3.4's endpoint, then set `sent` in place — the
     * etag is `version_token`, which a status column write leaves alone.
     *
     * @return array{string, string} id and etag
     */
    private function sent(string $dealId): array
    {
        $id = $this->postJson(self::ENDPOINT, [
            'deal_id' => $dealId,
            'customer_id' => $this->customerId,
            'currency' => 'EGP',
            'default_margin' => '20',
            'discount_percent' => '0',
            'lines' => [['supplier_quotation_item_id' => $this->lineId, 'quantity' => '2']],
        ], ['Idempotency-Key' => Uuid::uuid4()->toString()] + $this->bearerFor(RoleName::Manager))->assertStatus(201)->json('data.id');
        self::assertIsString($id);

        DB::table('quotations')->where('id', $id)->update(['status' => 'sent', 'sent_at' => now()]);

        $etag = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))->assertStatus(200)->json('data.etag');
        self::assertIsString($etag);

        return [$id, $etag];
    }

    private function currency(string $code): void
    {
        DB::table('currencies')->insert([
            'id' => Uuid::uuid4()->toString(),
            'code' => $code,
            'rounding_unit' => '1',
            'rounding_enabled' => false,
            'is_base' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    /** A deal already at `quotation_sent` — where a `sent` quotation leaves it (1.3). */
    private function deal(?string $ownerId): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('deals')->insert([
            'id' => $id,
            'code' => 'DL-'.now()->format('Y').'-'.substr($id, 0, 4),
            'customer_id' => $this->customerId,
            'owner_id' => $ownerId,
            'status' => 'quotation_sent',
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** Seeds §4.1's chain in EGP and returns the `supplier_quotation_items` id a line points at. */
    private function supplierLine(): string
    {
        $supplierId = Uuid::uuid4()->toString();
        $catalogItemId = Uuid::uuid4()->toString();
        $offerId = Uuid::uuid4()->toString();
        $lineId = Uuid::uuid4()->toString();
        $egp = DB::table('currencies')->where('code', 'EGP')->value('id');

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
            'currency_id' => $egp,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supplier_quotation_items')->insert([
            'id' => $lineId,
            'supplier_quotation_id' => $offerId,
            'catalog_item_id' => $catalogItemId,
            'unit_price' => '10',
            'quantity' => '5',
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
