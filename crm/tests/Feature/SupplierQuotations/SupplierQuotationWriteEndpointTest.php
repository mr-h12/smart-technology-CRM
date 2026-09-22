<?php

declare(strict_types=1);

namespace Tests\Feature\SupplierQuotations;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 6, Point 2.2 — `POST /api/v1/supplier-quotations`.
 *
 * ── §3.6 has two negative cases, and both are real roles ───────────────────
 *
 * §3.6's table grants `view` to seven roles including the CEO, and `create` to
 * five — the CEO's row is a dash and the Outdoor Supervisor's is a dash in
 * every column. So this endpoint has a caller who may *read* but not write
 * (the CEO, `SupplierWriteEndpointTest`'s situation) **and** one who may do
 * neither (the Outdoor Supervisor). Both are asserted; no seeded grant has to
 * be withdrawn to find them.
 *
 * ── The 201 carries the header, not the lines ──────────────────────────────
 *
 * `OpenAPI §4.1` asks for a single-resource envelope and says nothing about
 * nesting children in it. `GET /{id}` is Point 2.3 and is where the lines are
 * read back; a payload here that carried them would need a read path this
 * point does not own. The lines are asserted against the table instead.
 *
 * ── An unknown *id* is a 422; a *name* is `D-22`'s auto-add (Point 3.3) ────
 *
 * A line names its product by `catalog_item_id` **or** `product_name`, exactly
 * one. An id that is sent is still checked against the table — a caller naming
 * a row that is not there has a stale screen, not a new product — because the
 * alternative is the foreign key Point 1.2 added refusing instead, and a
 * constraint violation reaches the caller as a 500. Every rule below that
 * mirrors a database constraint is there for that reason.
 *
 * Point 3.4 closed the create half: a name-only line now resolves inside the
 * write transaction and the catalog gains the product. ⚠️ **`PATCH` is still
 * open** — `UpdateSupplierQuotation` does not resolve its replacement set until
 * Point 3.5, so a name sent there reaches the line insert and PostgreSQL
 * answers `42703`. Nothing asserts that state; it is a defect to close.
 */
final class SupplierQuotationWriteEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/supplier-quotations';

    private const PASSWORD = 'Passw0rd123';

    /** @var array<string, User> */
    private array $users = [];

    private string $supplierId;

    private string $currencyId;

    private string $catalogItemId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->supplierId = Uuid::uuid4()->toString();
        $this->currencyId = Uuid::uuid4()->toString();
        $this->catalogItemId = Uuid::uuid4()->toString();

        DB::table('suppliers')->insert([
            'id' => $this->supplierId,
            'name' => 'Alpha Supplies',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('currencies')->insert([
            'id' => $this->currencyId,
            'code' => 'EGP',
            'rounding_unit' => '1',
            'rounding_enabled' => true,
            'is_base' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('catalog_items')->insert([
            'id' => $this->catalogItemId,
            'kind' => 'product',
            'name' => 'Split unit 1.5HP',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * §3.6's `create / edit` row — five ✅ and two dashes.
     *
     * @return array<string, array{RoleName}>
     */
    public static function writers(): array
    {
        return [
            'manager' => [RoleName::Manager],
            'team leader' => [RoleName::TeamLeader],
            'outdoor sales' => [RoleName::OutdoorSales],
            'indoor sales' => [RoleName::IndoorSales],
            'procurement' => [RoleName::Procurement],
        ];
    }

    // ────────────────────────────────────────────────────────── authorisation

    public function test_that_an_unauthenticated_caller_cannot_create(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload())->assertStatus(401);
    }

    #[DataProvider('writers')]
    public function test_that_every_role_section_3_6_grants_create_to_can_create(RoleName $role): void
    {
        $this->postJson(self::ENDPOINT, $this->payload(), $this->bearerFor($role))->assertStatus(201);
    }

    /** §3.6 gives the CEO `view` and a dash under `create / edit`. */
    public function test_that_the_read_only_ceo_cannot_create(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload(), $this->bearerFor(RoleName::Ceo))->assertStatus(403);
    }

    /** §3.6 gives the Outdoor Supervisor a dash in every column. */
    public function test_that_the_outdoor_supervisor_cannot_create(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload(), $this->bearerFor(RoleName::OutdoorSupervisor))
            ->assertStatus(403);
    }

    /** `SEC-09`: the grant is data, so withdrawing it is what actually refuses. */
    public function test_that_withdrawing_the_grant_refuses_a_role_that_had_it(): void
    {
        DB::table('permissions')
            ->where('resource', 'supplier_quotation')
            ->where('action', 'create')
            ->delete();

        $this->postJson(self::ENDPOINT, $this->payload(), $this->bearerFor(RoleName::Manager))->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────── what is written

    public function test_that_a_created_offer_answers_with_section_7_2_fields(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload([
            'offer_date' => '2026-09-02',
            'valid_until' => '2026-10-02',
            'notes' => 'Two weeks lead time.',
        ]), $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->assertJsonPath('data.code', 'SQ-'.now()->format('Y').'-0001')
            ->assertJsonPath('data.supplier_id', $this->supplierId)
            ->assertJsonPath('data.deal_id', null)
            ->assertJsonPath('data.total_price', '4500.000000')
            ->assertJsonPath('data.currency_id', $this->currencyId)
            ->assertJsonPath('data.offer_date', '2026-09-02')
            ->assertJsonPath('data.valid_until', '2026-10-02')
            ->assertJsonPath('data.notes', 'Two weeks lead time.');
    }

    public function test_that_the_lines_are_written_even_though_the_201_does_not_carry_them(): void
    {
        $id = $this->created();

        self::assertSame(2, DB::table('supplier_quotation_items')->where('supplier_quotation_id', $id)->count());
    }

    /** `DB-02`: the actor is the signed-in caller, never a field in the body. */
    public function test_that_the_signed_in_caller_is_the_author(): void
    {
        $id = $this->created();

        self::assertSame(
            $this->userWith(RoleName::Manager)->getKey(),
            DB::table('supplier_quotations')->where('id', $id)->value('created_by'),
        );
    }

    /** `AUD-01`, through the route this time rather than through the use case. */
    public function test_that_a_create_is_written_to_the_audit_log(): void
    {
        $id = $this->created();

        $row = DB::table('audit_log')
            ->where('event', 'SUPPLIER_QUOTATION_CREATED')
            ->where('entity_id', $id)
            ->first();

        self::assertNotNull($row);
        self::assertSame($this->userWith(RoleName::Manager)->getKey(), $row->user_id);
    }

    // ─────────────────────────────────────────── the boundary, constraint by constraint

    public function test_that_an_offer_without_a_supplier_is_refused(): void
    {
        $this->post422(['supplier_id' => null], 'supplier_id');
    }

    public function test_that_an_unknown_supplier_is_refused(): void
    {
        $this->post422(['supplier_id' => Uuid::uuid4()->toString()], 'supplier_id');
    }

    public function test_that_an_unknown_deal_is_refused(): void
    {
        $this->post422(['deal_id' => Uuid::uuid4()->toString()], 'deal_id');
    }

    /** Point 1.1's CHECK: both money columns or neither. The boundary refuses first. */
    public function test_that_a_price_without_a_currency_is_refused(): void
    {
        $this->post422(['currency_id' => null], 'currency_id');
    }

    public function test_that_an_unknown_currency_is_refused(): void
    {
        $this->post422(['currency_id' => Uuid::uuid4()->toString()], 'currency_id');
    }

    /**
     * §5.6: "Every amount stores: amount · currency · …" — a line's `unit_price`
     * is an amount, and its currency is the header's. An offer that priced its
     * lines and named no currency is the dead end `D-80` described: Module 7's
     * `supplier_price_missing` refuses every quotation built on it.
     */
    public function test_that_priced_lines_without_a_currency_are_refused(): void
    {
        $this->post422(['total_price' => null, 'currency_id' => null], 'currency_id');
    }

    /** No lines, no amount, so §5.6 asks for no currency — the pair stays nullable. */
    public function test_that_an_offer_without_lines_needs_no_currency(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload(['total_price' => null, 'currency_id' => null, 'items' => []]), $this->bearerFor(RoleName::Manager))
            ->assertStatus(201);
    }

    /** §7.2 marks the code "Automatic"; a caller who sends one has a wrong idea. */
    public function test_that_a_caller_supplied_code_is_refused(): void
    {
        $this->post422(['code' => 'SQ-1999-9999'], 'code');
    }

    /** The whole reason this point exists rather than letting the foreign key answer. */
    public function test_that_an_unknown_product_is_refused_with_422_and_not_500(): void
    {
        $this->post422([
            'items' => [['catalog_item_id' => Uuid::uuid4()->toString(), 'unit_price' => '10', 'quantity' => '1']],
        ], 'items.0.catalog_item_id');
    }

    public function test_that_a_line_without_a_price_is_refused(): void
    {
        $this->post422([
            'items' => [['catalog_item_id' => $this->catalogItemId, 'quantity' => '1']],
        ], 'items.0.unit_price');
    }

    public function test_that_a_negative_line_price_is_refused(): void
    {
        $this->post422([
            'items' => [['catalog_item_id' => $this->catalogItemId, 'unit_price' => '-1', 'quantity' => '1']],
        ], 'items.0.unit_price');
    }

    /** Point 1.2's `quantity > 0` CHECK, mirrored so it never becomes a 500. */
    public function test_that_a_line_of_no_quantity_is_refused(): void
    {
        $this->post422([
            'items' => [['catalog_item_id' => $this->catalogItemId, 'unit_price' => '10', 'quantity' => '0']],
        ], 'items.0.quantity');
    }

    // ───────────────── `D-22`: a line names its product by id **or** by name

    /**
     * Point 3.3. §7.2's line is "Product · price · quantity", and `D-22` adds a
     * product the catalog does not have "automatically, without review" — so a
     * caller typing an offer out of a supplier's PDF may have a name and no id.
     * The line takes exactly one of the two: never neither, and never both.
     */
    public function test_that_a_line_naming_its_product_neither_way_is_refused(): void
    {
        $this->postJson(
            self::ENDPOINT,
            $this->payload(['items' => [['unit_price' => '10', 'quantity' => '1']]]),
            $this->bearerFor(RoleName::Manager),
        )
            ->assertStatus(422)
            ->assertJsonFragment(['field' => 'items.0.catalog_item_id', 'code' => 'invalid'])
            ->assertJsonFragment(['field' => 'items.0.product_name', 'code' => 'invalid']);
    }

    /**
     * Both is not a third way of asking. The two can disagree — an id for one
     * product and a name that is another's — and nothing in §7.2 or `D-22`
     * says which would win, so the boundary refuses rather than choose.
     */
    public function test_that_a_line_naming_its_product_both_ways_is_refused(): void
    {
        $this->post422([
            'items' => [[
                'catalog_item_id' => $this->catalogItemId,
                'product_name' => 'Copper Cable 4mm',
                'unit_price' => '10',
                'quantity' => '1',
            ]],
        ], 'items.0.catalog_item_id');
    }

    /** `catalog_items` carries `CHECK (name IS NULL OR btrim(name) <> '')` (Module 4 Point 1.2). */
    public function test_that_a_blank_product_name_is_refused(): void
    {
        $this->post422([
            'items' => [['product_name' => '   ', 'unit_price' => '10', 'quantity' => '1']],
        ], 'items.0.product_name');
    }

    /** `varchar(255)` — the column's own length, mirrored so the database never answers. */
    public function test_that_a_product_name_longer_than_the_column_is_refused(): void
    {
        $this->post422([
            'items' => [['product_name' => str_repeat('a', 256), 'unit_price' => '10', 'quantity' => '1']],
        ], 'items.0.product_name');
    }

    /**
     * The whitespace half of the owner's ruling — "a name already present is
     * the same product" (2026-09-03) — belongs to the boundary, and the
     * boundary already has it: Laravel's global `TrimStrings` runs before
     * validation and `TransformsRequest::cleanValue()` recurses into arrays, so
     * a line's `product_name` is trimmed exactly like this header field. Point
     * 3.1 normalises case only, deliberately, and re-trimming here would be the
     * second implementation of something the framework already does.
     *
     * That reliance is load-bearing for Point 3.4 — " Copper Cable " and
     * "Copper Cable" must reach `lower(name) = lower(?)` as one product — so it
     * is pinned rather than assumed. Removing the middleware reddens this.
     */
    public function test_that_the_boundary_trims_what_a_caller_typed(): void
    {
        $notes = $this->postJson(
            self::ENDPOINT,
            $this->payload(['notes' => '  from the supplier PDF  ']),
            $this->bearerFor(RoleName::Manager),
        )
            ->assertStatus(201)
            ->json('data.notes');

        self::assertSame('from the supplier PDF', $notes);
    }

    /**
     * Point 3.4, end to end: the module's acceptance criterion — "an offer
     * containing a product not in the catalog" — answered with a 201 rather
     * than the 422 Point 2.2 had to give, and with the product in the catalog.
     */
    public function test_that_an_offer_may_name_a_product_the_catalog_lacks(): void
    {
        $id = $this->postJson(self::ENDPOINT, $this->payload(['items' => [
            ['product_name' => 'Copper Cable 4mm', 'unit_price' => '10', 'quantity' => '2'],
        ]]), $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->json('data.id');

        self::assertIsString($id);

        $product = DB::table('catalog_items')->where('name', 'Copper Cable 4mm')->first();

        self::assertNotNull($product, 'D-22: the catalog did not gain the product the offer named.');
        self::assertSame(
            $product->id,
            DB::table('supplier_quotation_items')->where('supplier_quotation_id', $id)->value('catalog_item_id'),
        );
    }

    // ───────────────────────────────────────────────────────────────── helpers

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'supplier_id' => $this->supplierId,
            'total_price' => '4500.000000',
            'currency_id' => $this->currencyId,
            'items' => [
                ['catalog_item_id' => $this->catalogItemId, 'unit_price' => '1500', 'quantity' => '3'],
                ['catalog_item_id' => $this->catalogItemId, 'unit_price' => '250.5', 'quantity' => '1'],
            ],
        ], $overrides);
    }

    private function created(): string
    {
        $id = $this->postJson(self::ENDPOINT, $this->payload(), $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->json('data.id');

        self::assertIsString($id);

        return $id;
    }

    /** @param array<string, mixed> $overrides */
    private function post422(array $overrides, string $field): void
    {
        // `OpenAPI §5`'s `details` is a **list** of {field, code, message}, not a
        // map keyed by field — `ApiExceptionRenderer::validation()` flattens it
        // that way so one field with two failures is two entries.
        $this->postJson(self::ENDPOINT, $this->payload($overrides), $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonFragment(['field' => $field, 'code' => 'invalid']);
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
