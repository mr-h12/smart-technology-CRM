<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Idempotency\Domain\IdempotencyRefused;
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
 * `POST /api/v1/quotations/{id}/new-version` — Module 7 Point 4.3.
 *
 * §6.3 / `D-08`: "the system saves a full copy and the employee edits a new
 * version", linked by `parent_id` + `version`. The copy is the document the
 * customer answered — captured costs, FX rate and rounding included — and
 * the first `PATCH` on it re-prices at the edit (3.6). Accepted from the
 * owner's Q4 statuses (`partial`, `counter`, `expired`) only; the copy takes
 * the next `QT-` number (Q3); `Idempotency-Key` required (§9.1 "versions").
 * `quotations_version_unique_alive` refuses a second copy of one parent at
 * the database.
 */
final class QuotationNewVersionEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/quotations';

    private const PASSWORD = 'Passw0rd123';

    /** The keys that must differ, or may, between a parent and its copy — everything else is verbatim. */
    private const NOT_COPIED = ['id', 'code', 'version', 'parent_id', 'status', 'etag', 'rejection_reason', 'sent_at', 'submitted_at', 'is_self_approved', 'created_by', 'updated_by', 'created_at', 'updated_at', 'items', 'additional_items'];

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

    public function test_that_an_unauthenticated_caller_cannot_open_a_version(): void
    {
        $this->postJson(self::ENDPOINT.'/'.Uuid::uuid4()->toString().'/new-version')->assertStatus(401);
    }

    public function test_that_the_manager_opens_a_version_of_any_answered_quotation(): void
    {
        $id = $this->answered($this->deal(null), 'counter');

        $this->newVersion($id, RoleName::Manager)->assertStatus(201);
    }

    /** @return array<string, array{RoleName}> */
    public static function ownScoped(): array
    {
        return ['outdoor sales' => [RoleName::OutdoorSales], 'indoor sales' => [RoleName::IndoorSales]];
    }

    #[DataProvider('ownScoped')]
    public function test_that_an_own_scoped_role_opens_a_version_of_its_own_deals_quotation(RoleName $role): void
    {
        $id = $this->answered($this->deal($this->userWith($role)->id), 'counter');

        $this->newVersion($id, $role)->assertStatus(201);
    }

    /** §5.1: out of reach is a 404, indistinguishable from absent. */
    #[DataProvider('ownScoped')]
    public function test_that_an_own_scoped_role_cannot_open_a_version_of_another_owners_quotation(RoleName $role): void
    {
        $id = $this->answered($this->deal($this->userWith(RoleName::Manager)->id), 'counter');

        $this->newVersion($id, $role)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    /** `team` is granted and unbacked — fails closed, as every other quotation route does. */
    public function test_that_the_team_leader_sees_nothing_until_teams_exist(): void
    {
        $id = $this->answered($this->deal($this->userWith(RoleName::TeamLeader)->id), 'counter');

        $this->newVersion($id, RoleName::TeamLeader)->assertStatus(404);
    }

    /** §3.5's `edit` cell is `—` for Procurement, the CEO and the Outdoor Supervisor: whoever may edit the next draft may open it. */
    #[DataProvider('nonEditors')]
    public function test_that_a_role_without_the_edit_grant_cannot_open_a_version(RoleName $role): void
    {
        $id = $this->answered($this->deal(null), 'counter');

        $this->newVersion($id, $role)->assertStatus(403);
    }

    /** @return array<string, array{RoleName}> */
    public static function nonEditors(): array
    {
        return [
            'procurement' => [RoleName::Procurement],
            'ceo' => [RoleName::Ceo],
            'outdoor supervisor' => [RoleName::OutdoorSupervisor],
        ];
    }

    /** `SEC-07`: the grant is a database row, and withdrawing it withdraws the route. */
    public function test_that_withdrawing_the_grant_refuses_a_role_that_had_it(): void
    {
        $id = $this->answered($this->deal(null), 'counter');
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'edit')->delete();

        $this->newVersion($id, RoleName::Manager)->assertStatus(403);
    }

    // ───────────────────────────────────────────────────────── the copy

    /** `D-08`'s "full copy": the same document under a new number, `version + 1`, `draft`, pointing at its parent. */
    public function test_that_the_copy_is_the_parents_document_verbatim(): void
    {
        $parentId = $this->answered($this->deal(null), 'partial');
        $parent = $this->detail($parentId);

        $created = $this->newVersion($parentId, RoleName::Manager)
            ->assertStatus(201)
            ->assertJsonPath('data.version', 2);

        $copyId = $created->json('data.id');
        $copyCode = $created->json('data.code');
        self::assertIsString($copyId);
        self::assertIsString($copyCode);
        self::assertNotSame($parentId, $copyId);
        self::assertNotSame($parent['code'], $copyCode);
        self::assertMatchesRegularExpression('/^QT-\d{4}-\d{4}$/', $copyCode, '§4.7: the copy takes the next `QT-` number (Q3).');

        $copy = $this->detail($copyId);

        self::assertSame($parentId, $copy['parent_id']);
        self::assertSame(2, $copy['version']);
        self::assertSame('draft', $copy['status']);
        self::assertSame('quotation:'.$copyId.':1', $copy['etag']);
        self::assertNull($copy['rejection_reason'], 'the counter reason answers the parent, not the new draft.');
        self::assertNull($copy['submitted_at']);
        self::assertNull($copy['sent_at']);

        // Every header field — the customer's document — byte for byte.
        self::assertSame(
            array_diff_key($parent, array_flip(self::NOT_COPIED)),
            array_diff_key($copy, array_flip(self::NOT_COPIED)),
        );

        // Both child tables, every column but the row's own identity, in order.
        foreach (['items', 'additional_items'] as $table) {
            $parentLines = $parent[$table];
            $copyLines = $copy[$table];
            self::assertIsArray($parentLines);
            self::assertIsArray($copyLines);
            self::assertNotSame([], $parentLines, $table);
            self::assertCount(count($parentLines), $copyLines, $table);

            foreach ($parentLines as $index => $line) {
                self::assertIsArray($line);
                self::assertIsArray($copyLines[$index]);
                self::assertNotSame($line['id'], $copyLines[$index]['id'], "{$table}[{$index}] must be a new row");
                self::assertSame(array_diff_key($line, ['id' => true]), array_diff_key($copyLines[$index], ['id' => true]), "{$table}[{$index}]");
            }
        }
    }

    /** The source row is not touched: its status, its token and its lines stand. */
    public function test_that_the_parent_is_left_as_it_was(): void
    {
        $parentId = $this->answered($this->deal(null), 'counter');
        $before = DB::table('quotations')->where('id', $parentId)->first();

        $this->newVersion($parentId, RoleName::Manager)->assertStatus(201);

        $after = DB::table('quotations')->where('id', $parentId)->first();
        self::assertInstanceOf(stdClass::class, $before);
        self::assertInstanceOf(stdClass::class, $after);
        self::assertSame('counter', $after->status);
        self::assertSame($before->version_token, $after->version_token);
        self::assertSame($before->updated_at, $after->updated_at);
        self::assertSame(1, DB::table('quotation_items')->where('quotation_id', $parentId)->whereNull('deleted_at')->count());
    }

    /** §6.3's chain: a version of a version points at its own parent and counts on. */
    public function test_that_a_version_of_a_version_continues_the_chain(): void
    {
        $v1 = $this->answered($this->deal(null), 'counter');
        $v2 = $this->newVersion($v1, RoleName::Manager)->assertStatus(201)->json('data.id');
        self::assertIsString($v2);
        $this->markAs($v2, 'expired');

        $this->newVersion($v2, RoleName::Manager)
            ->assertStatus(201)
            ->assertJsonPath('data.version', 3);

        $v3 = DB::table('quotations')->where('version', 3)->first();
        self::assertInstanceOf(stdClass::class, $v3);
        self::assertSame($v2, $v3->parent_id);
    }

    // ─────────────────────────────────────────────────── which statuses

    /** @return array<string, array{string}> */
    public static function versionable(): array
    {
        return ['partial' => ['partial'], 'counter' => ['counter'], 'expired' => ['expired']];
    }

    /** The owner's Q4 ruling: the three where the document is finished with the customer and the deal continues. */
    #[DataProvider('versionable')]
    public function test_that_an_answered_quotation_opens_a_version(string $status): void
    {
        $id = $this->answered($this->deal(null), $status);

        $this->newVersion($id, RoleName::Manager)->assertStatus(201);
    }

    /** @return array<string, array{string}> */
    public static function notVersionable(): array
    {
        return [
            'draft' => ['draft'],
            'pending' => ['pending'],
            'approved' => ['approved'],
            'sent' => ['sent'],
            'accepted' => ['accepted'],
            'rejected' => ['rejected'],
        ];
    }

    /** `draft`/`pending` are still live, `approved`/`sent`/`accepted` are the customer's to answer, `rejected` archives (Module 10). */
    #[DataProvider('notVersionable')]
    public function test_that_a_quotation_not_answered_by_the_customer_is_refused(string $status): void
    {
        $id = $this->answered($this->deal(null), $status);

        $this->newVersion($id, RoleName::Manager)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'state_transition_invalid')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::INVALID_TRANSITION);

        self::assertSame(1, DB::table('quotations')->count());
    }

    /** `quotations_version_unique_alive` (Point 1.1): one v2 per parent, refused by the database, not by a read-then-write. */
    public function test_that_a_second_version_of_the_same_parent_is_refused(): void
    {
        $id = $this->answered($this->deal(null), 'counter');

        $this->newVersion($id, RoleName::Manager)->assertStatus(201);
        $this->newVersion($id, RoleName::Manager)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'state_transition_invalid')
            ->assertJsonPath('error.details.0.code', QuotationWriteRefused::VERSION_EXISTS);

        self::assertSame(2, DB::table('quotations')->count());
    }

    // ───────────────────────────────────────────────────── idempotency

    /** §9.1 names "versions" among the POSTs that require the key. */
    public function test_that_a_version_without_an_idempotency_key_is_refused(): void
    {
        $id = $this->answered($this->deal(null), 'counter');

        $this->postJson(self::ENDPOINT.'/'.$id.'/new-version', [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonFragment(['field' => 'Idempotency-Key', 'code' => IdempotencyRefused::KEY_REQUIRED]);

        self::assertSame(1, DB::table('quotations')->count());
    }

    /** §9.1: the same actor + route + key → the original response, and one copy. */
    public function test_that_replaying_a_key_returns_the_original_response_without_a_second_copy(): void
    {
        $id = $this->answered($this->deal(null), 'counter');
        $key = Uuid::uuid4()->toString();

        $first = $this->newVersion($id, RoleName::Manager, $key)->assertStatus(201);
        $replay = $this->newVersion($id, RoleName::Manager, $key)->assertStatus(201);

        self::assertSame($first->json(), $replay->json());
        self::assertSame(2, DB::table('quotations')->count());
    }

    // ────────────────────────────────────────────────────────────── audit

    /** `AUD-01`: `QUOTATION_VERSION_CREATED` on the copy, naming its parent. */
    public function test_that_a_version_is_written_to_the_audit_log(): void
    {
        $parentId = $this->answered($this->deal(null), 'counter');

        $copyId = $this->newVersion($parentId, RoleName::Manager)->assertStatus(201)->json('data.id');
        self::assertIsString($copyId);

        $row = DB::table('audit_log')->where('entity_type', 'quotation')->where('entity_id', $copyId)->where('event', 'QUOTATION_VERSION_CREATED')->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertIsString($row->new_values);
        $new = json_decode($row->new_values, true);
        self::assertIsArray($new);
        self::assertSame($parentId, $new['parent_id'] ?? null);
        self::assertSame(2, $new['version'] ?? null);
    }

    public function test_that_an_unknown_id_is_a_404(): void
    {
        $this->newVersion(Uuid::uuid4()->toString(), RoleName::Manager)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    // ──────────────────────────────────────────────────────────── fixtures

    /** @return TestResponse<\Symfony\Component\HttpFoundation\Response> */
    private function newVersion(string $id, RoleName $role, ?string $idempotencyKey = null): TestResponse
    {
        return $this->postJson(self::ENDPOINT.'/'.$id.'/new-version', [], [
            ...$this->bearerFor($role),
            'Idempotency-Key' => $idempotencyKey ?? Uuid::uuid4()->toString(),
        ]);
    }

    /** @return array<mixed> 3.5's body, as the Manager sees it (costs included) */
    private function detail(string $id): array
    {
        $data = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))->assertStatus(200)->json('data');
        self::assertIsArray($data);

        return $data;
    }

    /** A quotation created through 3.4 and moved to `$status` directly — the transitions that lead there are Modules 8 and 10. */
    private function answered(string $dealId, string $status): string
    {
        $id = $this->quotation($dealId);
        $this->markAs($id, $status);

        return $id;
    }

    private function markAs(string $id, string $status): void
    {
        DB::table('quotations')->where('id', $id)->update([
            'status' => $status,
            // §6.3: the reason is mandatory for these two, and the CHECK enforces it.
            'rejection_reason' => in_array($status, ['rejected', 'counter'], true) ? 'Customer wants a shorter delivery.' : null,
        ]);
    }

    /** Creates a quotation through Point 3.4's endpoint: one line of 2 × 10 at 20 % margin, plus 5 delivery. */
    private function quotation(string $dealId): string
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

        return $id;
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
