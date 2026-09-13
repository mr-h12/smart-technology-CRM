<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Idempotency\Domain\IdempotencyRefused;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Quotations\Application\Writing\CreateQuotation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Tests\TestCase;

/**
 * `POST /api/v1/quotations` — Module 7 Point 3.4.
 *
 * `CreateQuotationTest` proves §5's arithmetic; this file proves the boundary
 * around it: §3.5's `create` row at the API (`SEC-07`, `SEC-08`), the Form
 * Request's mirror of the table's CHECKs (so the database never answers 500),
 * `OpenAPI §4.1`'s `201`, the `meta.warnings` a §5.6 over-quantity produces,
 * and the `422 business_rule_blocked` mapping of `QuotationNotPriceable`.
 */
final class QuotationWriteEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/quotations';

    private const PASSWORD = 'Passw0rd123';

    /** @var array<string, User> */
    private array $users = [];

    private string $egpId;

    private string $usdId;

    private string $customerId;

    /** An unowned deal — `owner_id` null — that only an `all` scope may quote. */
    private string $dealId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->egpId = $this->currency('EGP', '1', false, true);
        $this->usdId = $this->currency('USD', '0.01', true, false);
        $this->customerId = $this->customer();
        $this->dealId = $this->deal($this->customerId, null);
    }

    // ────────────────────────────────────────────────────────── authorisation

    public function test_that_an_unauthenticated_caller_cannot_create(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload())->assertStatus(401);
    }

    /** §3.5 `create` = `All` — the Manager quotes anybody's deal, an unowned one included. */
    public function test_that_the_manager_can_quote_any_deal(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload(), $this->bearerFor(RoleName::Manager))->assertStatus(201);
    }

    /**
     * §3.5 `create` = `Own` for both Sales roles, and the owner ruled that "own"
     * is the deal's `owner_id`: their own deal is quotable …
     *
     * @return array<string, array{RoleName}>
     */
    public static function ownScoped(): array
    {
        return [
            'outdoor sales' => [RoleName::OutdoorSales],
            'indoor sales' => [RoleName::IndoorSales],
        ];
    }

    #[DataProvider('ownScoped')]
    public function test_that_an_own_scoped_role_can_quote_its_own_deal(RoleName $role): void
    {
        $ownerId = $this->userWith($role)->getKey();
        self::assertIsString($ownerId);
        $ownDeal = $this->deal($this->customerId, $ownerId);

        $this->postJson(self::ENDPOINT, $this->payload(['deal_id' => $ownDeal]), $this->bearerFor($role))
            ->assertStatus(201);
    }

    /** … and somebody else's is a 403 that reveals nothing about the deal. */
    #[DataProvider('ownScoped')]
    public function test_that_an_own_scoped_role_cannot_quote_another_owners_deal(RoleName $role): void
    {
        $otherId = $this->userWith(RoleName::Manager)->getKey();
        self::assertIsString($otherId);
        $othersDeal = $this->deal($this->customerId, $otherId);

        $this->postJson(self::ENDPOINT, $this->payload(['deal_id' => $othersDeal]), $this->bearerFor($role))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'permission_denied');

        self::assertSame(0, DB::table('quotations')->count());
    }

    #[DataProvider('ownScoped')]
    public function test_that_an_own_scoped_role_cannot_quote_an_unowned_deal(RoleName $role): void
    {
        $this->postJson(self::ENDPOINT, $this->payload(), $this->bearerFor($role))->assertStatus(403);
    }

    /**
     * §3.5 `create` = `Team` for the Team Leader, and `team` still resolves to
     * nothing (no team entity). Fail-closed: 403 even on a deal they own
     * themselves, and asserted by name so the gap is not mistaken for coverage.
     */
    public function test_that_the_team_leader_is_refused_until_teams_exist(): void
    {
        $ownerId = $this->userWith(RoleName::TeamLeader)->getKey();
        self::assertIsString($ownerId);
        $ownDeal = $this->deal($this->customerId, $ownerId);

        $this->postJson(self::ENDPOINT, $this->payload(['deal_id' => $ownDeal]), $this->bearerFor(RoleName::TeamLeader))
            ->assertStatus(403);
    }

    /**
     * §3.5's dashes under `create`: the CEO (read-only), the Outdoor Supervisor
     * and Procurement.
     *
     * @return array<string, array{RoleName}>
     */
    public static function nonWriters(): array
    {
        return [
            'ceo' => [RoleName::Ceo],
            'outdoor supervisor' => [RoleName::OutdoorSupervisor],
            'procurement' => [RoleName::Procurement],
        ];
    }

    #[DataProvider('nonWriters')]
    public function test_that_a_role_without_the_grant_cannot_create(RoleName $role): void
    {
        $this->postJson(self::ENDPOINT, $this->payload(), $this->bearerFor($role))->assertStatus(403);
    }

    /** `SEC-07`: the grant is a database row, and withdrawing it withdraws the route. */
    public function test_that_withdrawing_the_grant_refuses_a_role_that_had_it(): void
    {
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'create')->delete();

        $this->postJson(self::ENDPOINT, $this->payload(), $this->bearerFor(RoleName::Manager))->assertStatus(403);
    }

    // ────────────────────────────────────────────────── what a 201 carries

    /** `OpenAPI §4.1`'s example, to the field: `{id, code}` and `meta.request_id`. */
    public function test_that_a_created_quotation_answers_with_its_id_and_code(): void
    {
        $response = $this->postJson(self::ENDPOINT, $this->payload(), $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'code'], 'meta' => ['request_id']]);

        $code = $response->json('data.code');
        self::assertIsString($code);
        self::assertMatchesRegularExpression('/^QT-\d{4}-\d{4}$/', $code);
        self::assertSame(['id', 'code'], array_keys((array) $response->json('data')));
    }

    /** §5.6: a quantity above the supplier's recorded amount warns — in `meta`, per line, and does not block. */
    public function test_that_an_over_quantity_line_warns_in_meta_without_blocking(): void
    {
        $fine = $this->supplierLine('100', '5', $this->egpId);
        $over = $this->supplierLine('100', '5', $this->egpId);

        $this->postJson(self::ENDPOINT, $this->payload(['lines' => [
            ['supplier_quotation_item_id' => $fine, 'quantity' => '5'],
            ['supplier_quotation_item_id' => $over, 'quantity' => '6'],
        ]]), $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->assertJsonCount(1, 'meta.warnings')
            ->assertJsonPath('meta.warnings.0.code', 'quantity_exceeds_recorded')
            ->assertJsonPath('meta.warnings.0.field', 'lines.1.quantity')
            ->assertJsonPath('meta.warnings.0.message', __('quotations.warnings.quantity_exceeds_recorded'));
    }

    /** No warning, no key — a client testing `meta.warnings` for truthiness and for presence agree. */
    public function test_that_a_quotation_without_warnings_carries_no_warnings_key(): void
    {
        $line = $this->supplierLine('100', '5', $this->egpId);

        $this->postJson(self::ENDPOINT, $this->payload(['lines' => [
            ['supplier_quotation_item_id' => $line, 'quantity' => '5'],
        ]]), $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->assertJsonMissingPath('meta.warnings');
    }

    public function test_that_a_create_is_written_to_the_audit_log(): void
    {
        $id = $this->postJson(self::ENDPOINT, $this->payload(), $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->json('data.id');

        $row = DB::table('audit_log')->where('event', 'QUOTATION_CREATED')->where('entity_id', $id)->first();

        self::assertNotNull($row);
        self::assertSame($this->userWith(RoleName::Manager)->getKey(), $row->user_id);
    }

    // ─────────────────────────────────── §5.6 blocks → 422 business_rule_blocked

    public function test_that_a_line_without_a_usable_price_is_blocked_by_code_and_line(): void
    {
        $line = $this->supplierLine('100', '1', $this->egpId);
        DB::table('supplier_quotation_items')->where('id', $line)->update(['deleted_at' => now()]);

        $this->postJson(self::ENDPOINT, $this->payload(['lines' => [
            ['supplier_quotation_item_id' => $line, 'quantity' => '1'],
        ]]), $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'business_rule_blocked')
            ->assertJsonPath('error.details.0.code', 'supplier_price_missing')
            ->assertJsonPath('error.details.0.field', 'lines.0.supplier_quotation_item_id')
            ->assertJsonPath('error.details.0.message', __('quotations.errors.supplier_price_missing'));

        self::assertSame(0, DB::table('quotations')->count());
    }

    /** The owner's 2026-09-11 ruling: a missing FX rate is its own code, so the fix named is "record a rate". */
    public function test_that_a_line_with_no_fx_rate_is_blocked_with_its_own_code(): void
    {
        $usdLine = $this->supplierLine('1000', '1', $this->usdId);   // USD line, EGP quotation, no rate

        $this->postJson(self::ENDPOINT, $this->payload(['lines' => [
            ['supplier_quotation_item_id' => $this->supplierLine('100', '1', $this->egpId), 'quantity' => '1'],
            ['supplier_quotation_item_id' => $usdLine, 'quantity' => '1'],
        ]]), $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'business_rule_blocked')
            ->assertJsonPath('error.details.0.code', 'fx_rate_missing')
            ->assertJsonPath('error.details.0.field', 'lines.1.supplier_quotation_item_id');

        self::assertSame(0, DB::table('quotations')->count());
        self::assertSame(0, DB::table('quotation_items')->count());
    }

    // ──────────────────────────────── the boundary mirrors the table's CHECKs

    /** `DB-07`: a JSON number is a float by the time PHP sees it; only a decimal string passes. */
    public function test_that_a_json_number_is_refused_where_a_decimal_string_is_required(): void
    {
        $line = $this->supplierLine('100', '5', $this->egpId);

        $this->post422(['lines' => [['supplier_quotation_item_id' => $line, 'quantity' => 2.5]]], 'lines.0.quantity');
        $this->post422(['default_margin' => 20], 'default_margin');
        $this->post422(['discount_percent' => 0], 'discount_percent');
    }

    /** `D-63`: no tax is `null`; zero is not a tax rate, and the CHECK refuses it. */
    public function test_that_a_zero_tax_percent_is_refused(): void
    {
        $this->post422(['tax_percent' => '0'], 'tax_percent');
    }

    public function test_that_a_null_tax_percent_is_accepted(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload(['tax_percent' => null]), $this->bearerFor(RoleName::Manager))
            ->assertStatus(201);
    }

    /** CHECK `discount_percent >= 0 AND < 100`. */
    public function test_that_a_full_discount_is_refused(): void
    {
        $this->post422(['discount_percent' => '100'], 'discount_percent');
    }

    /** CHECK `valid_until >= quotation_date`. */
    public function test_that_an_expiry_before_the_quotation_date_is_refused(): void
    {
        $this->post422(['quotation_date' => '2026-09-11', 'valid_until' => '2026-09-10'], 'valid_until');
    }

    public function test_that_an_unoffered_currency_code_is_refused(): void
    {
        $this->post422(['currency' => 'GBP'], 'currency');
    }

    public function test_that_a_caller_supplied_code_is_refused(): void
    {
        $this->post422(['code' => 'QT-1999-9999'], 'code');
    }

    public function test_that_a_line_of_no_quantity_is_refused(): void
    {
        $line = $this->supplierLine('100', '5', $this->egpId);

        $this->post422(['lines' => [['supplier_quotation_item_id' => $line, 'quantity' => '0']]], 'lines.0.quantity');
    }

    public function test_that_a_blank_additional_item_is_refused(): void
    {
        $this->post422(['additional_items' => [['description' => '   ', 'amount' => '10']]], 'additional_items.0.description');
    }

    public function test_that_a_negative_additional_amount_is_refused(): void
    {
        $this->post422(['additional_items' => [['description' => 'Delivery', 'amount' => '-1']]], 'additional_items.0.amount');
    }

    // ───────────────────────────────────── the deal, at the boundary

    public function test_that_an_unknown_deal_is_refused_on_its_field(): void
    {
        $this->post422(['deal_id' => Uuid::uuid4()->toString()], 'deal_id');
    }

    /** The owner's 2026-09-11 ruling: the quotation's customer is its deal's, or the request is wrong. */
    public function test_that_a_customer_other_than_the_deals_is_refused_on_its_field(): void
    {
        $this->post422(['customer_id' => $this->customer()], 'customer_id');
    }

    // ──────────────────────────────── `OpenAPI §9.1` — Idempotency-Key (Point 3.7)

    /** §9.1 requires the header on a critical create; §5.1's 400 is "invalid header". */
    public function test_that_a_create_without_an_idempotency_key_is_refused(): void
    {
        $headers = $this->bearerFor(RoleName::Manager);
        unset($headers['Idempotency-Key']);

        $this->postJson(self::ENDPOINT, $this->payload(), $headers)
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonFragment(['field' => 'Idempotency-Key', 'code' => IdempotencyRefused::KEY_REQUIRED]);

        self::assertSame(0, DB::table('quotations')->count());
    }

    /** §9.1: same actor + route + key + payload → the original response, and no second quotation. */
    public function test_that_replaying_a_key_returns_the_original_response_without_a_second_quotation(): void
    {
        $headers = $this->bearerFor(RoleName::Manager);

        $first = $this->postJson(self::ENDPOINT, $this->payload(), $headers)->assertStatus(201);
        $replay = $this->postJson(self::ENDPOINT, $this->payload(), $headers)->assertStatus(201);

        self::assertSame($first->json(), $replay->json());
        self::assertSame(1, DB::table('quotations')->count());
        self::assertSame(1, DB::table('idempotency_keys')->count());
    }

    /** §9.1: same key, changed payload → `409 idempotency_conflict`, and the first quotation stands alone. */
    public function test_that_reusing_a_key_with_a_different_payload_is_a_conflict(): void
    {
        $headers = $this->bearerFor(RoleName::Manager);

        $this->postJson(self::ENDPOINT, $this->payload(), $headers)->assertStatus(201);
        $this->postJson(self::ENDPOINT, $this->payload(['default_margin' => '25']), $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', IdempotencyRefused::CONFLICT);

        self::assertSame(1, DB::table('quotations')->count());
    }

    /** §9.1: "every replay is checked against … current permission" — the grant goes, the replay goes with it. */
    public function test_that_a_replay_is_refused_once_the_grant_is_withdrawn(): void
    {
        $headers = $this->bearerFor(RoleName::Manager);

        $this->postJson(self::ENDPOINT, $this->payload(), $headers)->assertStatus(201);
        DB::table('permissions')->where('resource', 'quotation')->where('action', 'create')->delete();

        $this->postJson(self::ENDPOINT, $this->payload(), $headers)->assertStatus(403);
    }

    /** §9.1 keys on the actor: two people may pick the same key and each gets their own quotation. */
    public function test_that_another_actor_may_use_the_same_key(): void
    {
        $key = Uuid::uuid4()->toString();
        $manager = ['Idempotency-Key' => $key] + $this->bearerFor(RoleName::Manager);
        $ownerId = $this->userWith(RoleName::IndoorSales)->getKey();
        self::assertIsString($ownerId);
        $sales = ['Idempotency-Key' => $key] + $this->bearerFor(RoleName::IndoorSales);

        $this->postJson(self::ENDPOINT, $this->payload(), $manager)->assertStatus(201);
        $this->postJson(self::ENDPOINT, $this->payload(['deal_id' => $this->deal($this->customerId, $ownerId)]), $sales)
            ->assertStatus(201);

        self::assertSame(2, DB::table('quotations')->count());
    }

    /** §9.1 stores a "final status"; a 5xx is not one, so the key is given back and the same retry succeeds. */
    public function test_that_a_failed_create_releases_the_key_for_a_retry(): void
    {
        $headers = $this->bearerFor(RoleName::Manager);

        $this->app->bind(CreateQuotation::class, function (): never {
            throw new RuntimeException('simulated failure inside the use case');
        });
        $this->postJson(self::ENDPOINT, $this->payload(), $headers)->assertStatus(500);
        $this->app->offsetUnset(CreateQuotation::class);

        self::assertSame(0, DB::table('idempotency_keys')->count());

        $this->postJson(self::ENDPOINT, $this->payload(), $headers)->assertStatus(201);
        self::assertSame(1, DB::table('quotations')->count());
    }

    /**
     * A key whose first request has not finished — the row is claimed and
     * carries no response yet — is a reuse, not a replay: 409, and no second
     * create runs beside the first.
     */
    public function test_that_a_key_still_in_flight_is_a_conflict(): void
    {
        $headers = $this->bearerFor(RoleName::Manager);
        $userId = $this->userWith(RoleName::Manager)->getKey();
        self::assertIsString($userId);

        DB::table('idempotency_keys')->insert([
            'id' => Uuid::uuid4()->toString(),
            'user_id' => $userId,
            'route' => 'POST api/v1/quotations',
            'key' => $headers['Idempotency-Key'],
            'request_hash' => hash('sha256', (string) json_encode($this->payload())),
            'created_at' => now(),
        ]);

        $this->postJson(self::ENDPOINT, $this->payload(), $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', IdempotencyRefused::CONFLICT);

        self::assertSame(0, DB::table('quotations')->count());
    }

    // ────────────────────────────────────────────────────────────── fixtures

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'deal_id' => $this->dealId,
            'customer_id' => $this->customerId,
            'currency' => 'EGP',
            'default_margin' => '20',
            'discount_percent' => '0',
            'lines' => [],
            'additional_items' => [],
        ], $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    private function post422(array $overrides, string $field): void
    {
        $this->postJson(self::ENDPOINT, $this->payload($overrides), $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonFragment(['field' => $field, 'code' => 'invalid']);
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

    private function deal(string $customerId, ?string $ownerId): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('deals')->insert([
            'id' => $id,
            'code' => 'DL-'.now()->format('Y').'-'.substr($id, 0, 4),
            'customer_id' => $customerId,
            'owner_id' => $ownerId,
            'last_activity_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** Seeds §4.1's chain and returns the `supplier_quotation_items` id a line points at. */
    private function supplierLine(string $unitPrice, string $quantity, string $currencyId): string
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
            'currency_id' => $currencyId,
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

        // §9.1: every create here carries a fresh key unless a test pins one.
        return ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => Uuid::uuid4()->toString()];
    }
}
