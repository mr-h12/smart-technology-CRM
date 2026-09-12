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
 * `DELETE /api/v1/quotations/{id}` — Module 7 Point 4.4.
 *
 * `D-46` / §3.5's `delete (Draft only)` row: Manager All, Team Leader Team,
 * Outdoor and Indoor Sales Own, nothing for Procurement and the CEO. Recorded
 * on #103 (Q5) as a contract addition to `OpenAPI §7.1`, answering `204`.
 * `If-Match` on 3.6's terms (`DB-12`, `API-12`); outside `draft` it is 3.6's
 * `quotation_not_draft` — the same rule, not a transition. `DB-01`: the row
 * and both child tables are soft-deleted in one transaction, never
 * `forceDelete`d; `QUOTATION_DELETED` is audited (`AUD-01`).
 */
final class QuotationDeleteEndpointTest extends TestCase
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

    public function test_that_an_unauthenticated_caller_cannot_delete(): void
    {
        $this->deleteJson(self::ENDPOINT.'/'.Uuid::uuid4()->toString())->assertStatus(401);
    }

    public function test_that_the_manager_deletes_any_draft(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->remove($id, $etag, RoleName::Manager)->assertStatus(204);
    }

    /** @return array<string, array{RoleName}> */
    public static function ownScoped(): array
    {
        return ['outdoor sales' => [RoleName::OutdoorSales], 'indoor sales' => [RoleName::IndoorSales]];
    }

    #[DataProvider('ownScoped')]
    public function test_that_an_own_scoped_role_deletes_its_own_deals_draft(RoleName $role): void
    {
        [$id, $etag] = $this->quotation($this->deal($this->userWith($role)->id));

        $this->remove($id, $etag, $role)->assertStatus(204);
    }

    /** §5.1: out of reach is a 404, indistinguishable from absent — and the row stays. */
    #[DataProvider('ownScoped')]
    public function test_that_an_own_scoped_role_cannot_delete_another_owners_draft(RoleName $role): void
    {
        [$id, $etag] = $this->quotation($this->deal($this->userWith(RoleName::Manager)->id));

        $this->remove($id, $etag, $role)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');

        self::assertNull(DB::table('quotations')->where('id', $id)->value('deleted_at'));
    }

    /** `team` is granted and unbacked — fails closed, as the read, the edit and the submit do. */
    public function test_that_the_team_leader_sees_nothing_until_teams_exist(): void
    {
        [$id, $etag] = $this->quotation($this->deal($this->userWith(RoleName::TeamLeader)->id));

        $this->remove($id, $etag, RoleName::TeamLeader)->assertStatus(404);
    }

    /**
     * §3.5's `delete` cell is `—` for Procurement and the CEO, and the Outdoor
     * Supervisor has no quotation row at all.
     *
     * @return array<string, array{RoleName}>
     */
    public static function nonDeleters(): array
    {
        return [
            'procurement' => [RoleName::Procurement],
            'ceo' => [RoleName::Ceo],
            'outdoor supervisor' => [RoleName::OutdoorSupervisor],
        ];
    }

    #[DataProvider('nonDeleters')]
    public function test_that_a_role_without_the_grant_cannot_delete(RoleName $role): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->remove($id, $etag, $role)->assertStatus(403);
    }

    /** `SEC-07`: the grant is a database row, and withdrawing it withdraws the route. */
    public function test_that_withdrawing_the_grant_refuses_a_role_that_had_it(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'delete')->delete();

        $this->remove($id, $etag, RoleName::Manager)->assertStatus(403);
    }

    // ──────────────────────────────────────────────────── optimistic locking

    public function test_that_a_missing_if_match_is_a_400(): void
    {
        [$id] = $this->quotation($this->deal(null));

        $this->deleteJson(self::ENDPOINT.'/'.$id, [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonPath('error.details.0.field', 'If-Match')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::IF_MATCH_REQUIRED);

        self::assertNull(DB::table('quotations')->where('id', $id)->value('deleted_at'));
    }

    /** `API-12`: a stale token is `409 concurrency_conflict` naming the current etag; the row stays. */
    public function test_that_a_stale_token_is_a_409_with_the_current_etag(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->remove($id, 'quotation:'.$id.':0', RoleName::Manager)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'concurrency_conflict')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::STALE_VERSION)
            ->assertJsonPath('error.details.0.current_etag', $etag);

        self::assertNull(DB::table('quotations')->where('id', $id)->value('deleted_at'));
    }

    // ───────────────────────────────────────────────────────── draft only

    /** `D-46` "delete (Draft only)": a `pending` quotation is 3.6's `422 quotation_not_draft`, row intact. */
    public function test_that_deleting_a_pending_quotation_is_refused(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));
        DB::table('quotations')->where('id', $id)->update(['status' => 'pending']);

        $this->remove($id, $etag, RoleName::Manager)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'business_rule_blocked')
            ->assertJsonPath('error.details.0.field', 'status')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::NOT_DRAFT);

        $row = DB::table('quotations')->where('id', $id)->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertNull($row->deleted_at);
        self::assertSame(1, $row->version_token, 'a refused delete must not move the token.');
        self::assertSame(1, DB::table('quotation_items')->where('quotation_id', $id)->whereNull('deleted_at')->count());
    }

    // ──────────────────────────────────────────────────── what the delete does

    /** `DB-01`: the row and both child tables carry `deleted_at`; nothing is physically gone. */
    public function test_that_a_delete_soft_deletes_the_row_and_its_children(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $response = $this->remove($id, $etag, RoleName::Manager)->assertStatus(204);
        self::assertSame('', $response->getContent());
        self::assertNotEmpty($response->headers->get('X-Request-Id'), '`OpenAPI §3.3`: the request id travels as a header, so a `204` still carries it.');

        $row = DB::table('quotations')->where('id', $id)->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertNotNull($row->deleted_at);

        foreach (['quotation_items', 'quotation_additional_items'] as $table) {
            $children = DB::table($table)->where('quotation_id', $id)->get();
            self::assertCount(1, $children, $table.' must still hold its row.');
            $child = $children->first();
            self::assertInstanceOf(stdClass::class, $child);
            self::assertNotNull($child->deleted_at, $table.'.deleted_at must be set.');
        }
    }

    /** A deleted quotation is absent from 3.5's read and cannot be deleted twice. */
    public function test_that_a_deleted_quotation_answers_404_afterwards(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));
        $this->remove($id, $etag, RoleName::Manager)->assertStatus(204);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');

        $this->remove($id, $etag, RoleName::Manager)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    // ────────────────────────────────────────────────────────────── audit

    /** `AUD-01`: `QUOTATION_DELETED` with what was removed and nothing after. */
    public function test_that_a_delete_is_written_to_the_audit_log(): void
    {
        [$id, $etag] = $this->quotation($this->deal(null));

        $this->remove($id, $etag, RoleName::Manager)->assertStatus(204);

        $row = DB::table('audit_log')->where('entity_type', 'quotation')->where('entity_id', $id)->where('event', 'QUOTATION_DELETED')->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertIsString($row->old_values);
        $old = json_decode($row->old_values, true);
        self::assertIsArray($old);
        self::assertSame('draft', $old['status'] ?? null);
        self::assertIsString($old['code'] ?? null);
        self::assertNull($row->new_values);
    }

    public function test_that_an_unknown_id_is_a_404(): void
    {
        $id = Uuid::uuid4()->toString();

        $this->remove($id, 'quotation:'.$id.':1', RoleName::Manager)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    // ──────────────────────────────────────────────────────────── fixtures

    /**
     * Named `remove`, not `delete`: `TestCase::delete()` is the HTTP helper.
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function remove(string $id, string $ifMatch, RoleName $role): TestResponse
    {
        return $this->deleteJson(self::ENDPOINT.'/'.$id, [], [...$this->bearerFor($role), 'If-Match' => $ifMatch]);
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
