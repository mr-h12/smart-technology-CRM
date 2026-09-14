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
 * `PATCH /api/v1/quotations/{id}/return` — Module 8 Point 1.2.
 *
 * §6.4's `Pending ──return with note──► Draft` through Point 4.1's edge
 * table, on the owner's Q2 ruling (#131): the **same row** goes back to
 * `draft`, `returned_at` and `return_note` are written, `submitted_at` is
 * cleared so `D-11`'s "days waiting" restarts on resubmit. §3.5's `return
 * with note` row: Manager All, Team Leader Team (`D-a`), nothing else. The
 * note is mandatory and non-blank (§6.4 "return with note", §9 Flow 7's
 * "mandatory reason"). `If-Match` on 3.6's terms; `QUOTATION_RETURNED`
 * audited with the note (`AUD-01`).
 */
final class QuotationReturnEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/quotations';

    private const PASSWORD = 'Passw0rd123';

    private const NOTE = 'Margin too low on line 1 — raise to 25 %.';

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

    public function test_that_an_unauthenticated_caller_cannot_return(): void
    {
        $this->patchJson(self::ENDPOINT.'/'.Uuid::uuid4()->toString().'/return', ['note' => self::NOTE])->assertStatus(401);
    }

    public function test_that_the_manager_returns_any_pending_quotation(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);

        $this->return($id, $etag, RoleName::Manager)->assertStatus(200);
    }

    /** `D-a`: `team` is granted and unbacked — fails closed, as the approve does. */
    public function test_that_the_team_leader_sees_nothing_until_teams_exist(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);

        $this->return($id, $etag, RoleName::TeamLeader)->assertStatus(404);
    }

    /**
     * §3.5's `return with note` cell is `—` for every other role.
     *
     * @return array<string, array{RoleName}>
     */
    public static function nonReturners(): array
    {
        return [
            'indoor sales' => [RoleName::IndoorSales],
            'outdoor sales' => [RoleName::OutdoorSales],
            'procurement' => [RoleName::Procurement],
            'ceo' => [RoleName::Ceo],
            'outdoor supervisor' => [RoleName::OutdoorSupervisor],
        ];
    }

    #[DataProvider('nonReturners')]
    public function test_that_a_role_without_the_grant_cannot_return(RoleName $role): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);

        $this->return($id, $etag, $role)->assertStatus(403);

        self::assertSame('pending', DB::table('quotations')->where('id', $id)->value('status'));
    }

    /** `SEC-07`: the grant is a database row, and withdrawing it withdraws the route. */
    public function test_that_withdrawing_the_grant_refuses_a_role_that_had_it(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'return_with_note')->delete();

        $this->return($id, $etag, RoleName::Manager)->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────── the note

    /** @return array<string, array{array<string, mixed>}> */
    public static function missingNotes(): array
    {
        return ['absent' => [[]], 'empty' => [['note' => '']], 'blank' => [['note' => '   ']], 'not a string' => [['note' => 7]]];
    }

    /**
     * "Return **with note**": the note is mandatory, and `required` alone would accept spaces.
     *
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('missingNotes')]
    public function test_that_a_missing_or_blank_note_is_a_422(array $body): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);

        $this->return($id, $etag, RoleName::Manager, $body)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.details.0.field', 'note');

        self::assertSame('pending', DB::table('quotations')->where('id', $id)->value('status'));
    }

    // ──────────────────────────────────────────────────── optimistic locking

    public function test_that_a_missing_if_match_is_a_400(): void
    {
        [$id] = $this->pending(RoleName::IndoorSales);

        $this->patchJson(self::ENDPOINT.'/'.$id.'/return', ['note' => self::NOTE], $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonPath('error.details.0.field', 'If-Match')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::IF_MATCH_REQUIRED);
    }

    /** `API-12`: a stale token is `409 concurrency_conflict` and names the current etag; nothing moves. */
    public function test_that_a_stale_token_is_a_409_with_the_current_etag(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);

        $this->return($id, 'quotation:'.$id.':1', RoleName::Manager)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'concurrency_conflict')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::STALE_VERSION)
            ->assertJsonPath('error.details.0.current_etag', $etag);

        self::assertSame('pending', DB::table('quotations')->where('id', $id)->value('status'));
    }

    // ───────────────────────────────────────────────────── the transition

    /** §6.4 draws no return arrow from `draft`: an unsubmitted quotation is `409 state_transition_invalid`. */
    public function test_that_returning_a_draft_is_a_transition_error(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null), RoleName::Manager);

        $this->return($id, $etag, RoleName::Manager)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'state_transition_invalid')
            ->assertJsonPath('error.details.0.field', 'status')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::INVALID_TRANSITION);

        self::assertSame(1, DB::table('quotations')->where('id', $id)->value('version_token'), 'a refused transition must not move the token.');
    }

    /** Nor from `approved` (§6.4: approved goes to `sent`, never back). */
    public function test_that_returning_an_approved_quotation_is_a_transition_error(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);
        $this->patchJson(self::ENDPOINT.'/'.$id.'/approve', [], [...$this->bearerFor(RoleName::Manager), 'If-Match' => $etag])->assertStatus(200);

        $this->return($id, 'quotation:'.$id.':3', RoleName::Manager)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'state_transition_invalid');
    }

    /** Q2: the same row goes `Pending ──return──► Draft`, `submitted_at` cleared, `returned_at` and the note written, the token advanced, §6.3's version untouched. */
    public function test_that_a_return_moves_the_pending_quotation_back_to_draft(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);

        $response = $this->return($id, $etag, RoleName::Manager)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.etag', 'quotation:'.$id.':3')
            ->assertJsonPath('data.items.0.quantity', '2.0000');

        $data = $response->json('data');
        self::assertIsArray($data);
        self::assertArrayHasKey('submitted_at', $data);
        self::assertNull($data['submitted_at'], '`D-11`: a returned quotation is no longer waiting.');

        $row = DB::table('quotations')->where('id', $id)->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertSame('draft', $row->status);
        self::assertNull($row->submitted_at);
        self::assertNotNull($row->returned_at);
        self::assertSame(self::NOTE, $row->return_note);
        self::assertSame(0, DB::table('quotations')->where('parent_id', $id)->count(), 'Q2: no copy — the same row is the draft.');
    }

    /** Q2: "days waiting restarts on resubmit" — the returned draft submits again and `submitted_at` is stamped afresh. */
    public function test_that_a_returned_draft_can_be_resubmitted(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);
        $this->return($id, $etag, RoleName::Manager)->assertStatus(200);

        $this->patchJson(self::ENDPOINT.'/'.$id.'/submit-for-approval', [], [...$this->bearerFor(RoleName::IndoorSales), 'If-Match' => 'quotation:'.$id.':3'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'pending');

        $row = DB::table('quotations')->where('id', $id)->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertNotNull($row->submitted_at);
        self::assertNotNull($row->returned_at, 'the return is history, not undone by the resubmit.');
    }

    // ────────────────────────────────────────────────────────────── audit

    /** `AUD-01`: `QUOTATION_RETURNED` with the status pair, the cleared `submitted_at`, and the note. */
    public function test_that_a_return_is_written_to_the_audit_log(): void
    {
        [$id, $etag] = $this->pending(RoleName::IndoorSales);

        $this->return($id, $etag, RoleName::Manager)->assertStatus(200);

        $row = DB::table('audit_log')->where('entity_type', 'quotation')->where('entity_id', $id)->where('event', 'QUOTATION_RETURNED')->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertIsString($row->old_values);
        self::assertIsString($row->new_values);
        $old = json_decode($row->old_values, true);
        $new = json_decode($row->new_values, true);
        self::assertIsArray($old);
        self::assertIsArray($new);
        self::assertSame('pending', $old['status'] ?? null);
        self::assertIsString($old['submitted_at'] ?? null);
        self::assertNull($old['return_note'] ?? null);
        self::assertSame('draft', $new['status'] ?? null);
        self::assertArrayHasKey('submitted_at', $new);
        self::assertNull($new['submitted_at']);
        self::assertSame(self::NOTE, $new['return_note'] ?? null);
    }

    public function test_that_an_unknown_id_is_a_404(): void
    {
        $id = Uuid::uuid4()->toString();

        $this->return($id, 'quotation:'.$id.':1', RoleName::Manager)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    // ──────────────────────────────────────────────────────────── fixtures

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function return(string $id, string $ifMatch, RoleName $role, array $body = ['note' => self::NOTE]): TestResponse
    {
        return $this->patchJson(self::ENDPOINT.'/'.$id.'/return', $body, [...$this->bearerFor($role), 'If-Match' => $ifMatch]);
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
