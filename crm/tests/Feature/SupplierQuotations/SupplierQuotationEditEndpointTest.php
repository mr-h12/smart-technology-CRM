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
use stdClass;
use Tests\TestCase;

/**
 * Module 6, Point 2.4 — `PATCH /api/v1/supplier-quotations/{id}`.
 *
 * ── §3.6's write row is one cell, so edit is the create permission ─────────
 *
 * "create / edit ✅" is a single column in §3.6, exactly as §3.7's write row is
 * a single cell for suppliers — which `SupplierController` already reads as one
 * permission covering all of it. So this route carries
 * `permission:supplier_quotation.create`, and the CEO, who holds a dash there
 * and `view` as `All`, can read an offer (Point 2.3) and cannot edit it.
 *
 * ── Which fields survive an edit, and why those ────────────────────────────
 *
 * All seven of `SupplierQuotationDraft::WRITABLE_ON_CREATE`. Two §7.2 rows stay
 * out and each has a source: `code` is "Automatic" (§7.2, §4.7) and stays
 * `prohibited`, and `entered_by` is `DB-02`'s `created_by` — a fact about who
 * was authenticated at creation, which an edit cannot rewrite. **Nothing else
 * is frozen, including `supplier_id`**, because no source freezes it: §3.6
 * grants "edit" over the screen, `D-51` makes the offer standalone and
 * reusable, and `D-36` has a supplier's price changing under an already-built
 * customer quotation — which that quotation survives by holding its own
 * snapshot. Inventing an immutability rule with no citation is the thing
 * `CLAUDE.md` forbids outright.
 *
 * ── The lines are replaced as a set — the owner's ruling, 2026-09-02 ───────
 *
 * `items` present replaces every line: the old rows are **soft-deleted**
 * (`DB-01` — business data is never physically deleted) and the submitted set
 * is inserted. `items` absent leaves the lines alone, which is what `PATCH`
 * means. Editing a line by its own identity was the alternative and was
 * rejected for this point: it needs a line `id` on the wire, which Point 2.3
 * deliberately withheld, and an ordering column the table does not have.
 *
 * ── No `If-Match`, no version column, no 409 — and that is a citation ──────
 *
 * `OpenAPI §9.2` scopes optimistic concurrency to *quotations* and closes with
 * "the same pattern may be adopted later for other high-contention resources
 * **only through a documented contract update**". §9.1, three lines above it,
 * lists "deals, quotations, **supplier quotations**" as separate resources, so
 * §9's "quotation" is Module 7's. `DB-12` reads the same way. Building it here
 * would adopt the pattern for a resource the contract does not name. Confirmed
 * by the owner on 2026-09-02 and recorded in `CHECKLIST.md`.
 */
final class SupplierQuotationEditEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/supplier-quotations';

    private const PASSWORD = 'Passw0rd123';

    /** @var array<string, User> */
    private array $users = [];

    private string $supplierId;

    private string $otherSupplierId;

    private string $currencyId;

    private string $catalogItemId;

    private string $otherCatalogItemId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->supplierId = Uuid::uuid4()->toString();
        $this->otherSupplierId = Uuid::uuid4()->toString();
        $this->currencyId = Uuid::uuid4()->toString();
        $this->catalogItemId = Uuid::uuid4()->toString();
        $this->otherCatalogItemId = Uuid::uuid4()->toString();

        DB::table('suppliers')->insert([
            ['id' => $this->supplierId, 'name' => 'Alpha Supplies', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->otherSupplierId, 'name' => 'Beta Trading', 'created_at' => now(), 'updated_at' => now()],
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
            ['id' => $this->catalogItemId, 'kind' => 'product', 'name' => 'Split unit 1.5HP', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $this->otherCatalogItemId, 'kind' => 'product', 'name' => 'Copper pipe 3m', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * §3.6's `create / edit` row — five ✅, and two dashes that are tested below.
     *
     * @return array<string, array{RoleName}>
     */
    public static function editors(): array
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

    /** The only request this method makes — see the read test for why. */
    public function test_that_an_unauthenticated_caller_cannot_edit(): void
    {
        $this->patchJson(self::ENDPOINT.'/'.Uuid::uuid4()->toString(), ['notes' => 'x'])
            ->assertStatus(401);
    }

    #[DataProvider('editors')]
    public function test_that_every_role_section_3_6_grants_edit_to_can_edit(RoleName $role): void
    {
        $id = $this->created();

        $this->patchJson(self::ENDPOINT.'/'.$id, ['notes' => 'Revised'], $this->bearerFor($role))
            ->assertStatus(200);
    }

    /** §3.6 gives the CEO `view` as `All` and a dash under `create / edit`. */
    public function test_that_the_read_only_ceo_cannot_edit(): void
    {
        $id = $this->created();

        $this->patchJson(self::ENDPOINT.'/'.$id, ['notes' => 'Revised'], $this->bearerFor(RoleName::Ceo))
            ->assertStatus(403);
    }

    /** §3.6 gives the Outdoor Supervisor a dash in every column. */
    public function test_that_the_outdoor_supervisor_cannot_edit(): void
    {
        $id = $this->created();

        $this->patchJson(self::ENDPOINT.'/'.$id, ['notes' => 'Revised'], $this->bearerFor(RoleName::OutdoorSupervisor))
            ->assertStatus(403);
    }

    /** `SEC-09`: the grant is data, so withdrawing it is what actually refuses. */
    public function test_that_withdrawing_the_grant_refuses_a_role_that_had_it(): void
    {
        $id = $this->created();

        DB::table('permissions')
            ->where('resource', 'supplier_quotation')
            ->where('action', 'create')
            ->delete();

        $this->patchJson(self::ENDPOINT.'/'.$id, ['notes' => 'Revised'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(403);
    }

    // ────────────────────────────────────────────────────────── header fields

    public function test_that_an_edit_answers_with_the_new_values(): void
    {
        $id = $this->created();

        $this->patchJson(self::ENDPOINT.'/'.$id, ['notes' => 'Revised'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.notes', 'Revised');
    }

    /** A `PATCH` touches what it names and nothing else. */
    public function test_that_an_untouched_field_keeps_its_value(): void
    {
        $id = $this->created();

        $this->patchJson(self::ENDPOINT.'/'.$id, ['notes' => 'Revised'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.total_price', '4500.000000')
            ->assertJsonPath('data.supplier_id', $this->supplierId);
    }

    /**
     * No source freezes `supplier_id`, so an edit may move the offer. Asserted
     * rather than assumed, because the draft's own docblock named this as the
     * question Point 2.4 had to answer.
     */
    public function test_that_an_offer_may_change_supplier(): void
    {
        $id = $this->created();

        $this->patchJson(self::ENDPOINT.'/'.$id, ['supplier_id' => $this->otherSupplierId], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.supplier_id', $this->otherSupplierId);
    }

    /** §7.2 marks `code` "Automatic"; refusing beats ignoring (Point 2.2's reading). */
    public function test_that_a_caller_supplied_code_is_refused(): void
    {
        $id = $this->created();

        $this->patchJson(self::ENDPOINT.'/'.$id, ['code' => 'SQ-2026-9999'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonFragment(['field' => 'code', 'code' => 'invalid']);
    }

    /** `DB-02`: the author is who created the row, and an edit cannot rewrite it. */
    public function test_that_an_edit_does_not_change_the_author(): void
    {
        $id = $this->created();
        $author = DB::table('supplier_quotations')->where('id', $id)->value('created_by');

        $this->patchJson(self::ENDPOINT.'/'.$id, ['notes' => 'Revised'], $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(200);

        $row = DB::table('supplier_quotations')->where('id', $id)->first();
        self::assertInstanceOf(stdClass::class, $row);
        self::assertSame($author, $row->created_by);
        self::assertNotSame($author, $row->updated_by);
    }

    /** The boundary still mirrors every constraint — a 422, never a 500. */
    public function test_that_an_unknown_supplier_is_refused(): void
    {
        $id = $this->created();

        $this->patchJson(self::ENDPOINT.'/'.$id, ['supplier_id' => Uuid::uuid4()->toString()], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonFragment(['field' => 'supplier_id', 'code' => 'invalid']);
    }

    // ─────────────────────────────────────────────────────────────── the lines

    /** The owner's ruling: `items` present replaces the whole set. */
    public function test_that_submitted_items_replace_the_whole_set(): void
    {
        $id = $this->created();

        $this->patchJson(self::ENDPOINT.'/'.$id, [
            'items' => [['catalog_item_id' => $this->otherCatalogItemId, 'unit_price' => '99', 'quantity' => '2']],
        ], $this->bearerFor(RoleName::Manager))->assertStatus(200);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.catalog_item_id', $this->otherCatalogItemId)
            ->assertJsonPath('data.items.0.unit_price', '99.000000');
    }

    /** `DB-01`: the replaced lines are soft-deleted, never physically removed. */
    public function test_that_replaced_lines_are_soft_deleted_and_not_destroyed(): void
    {
        $id = $this->created();

        $this->patchJson(self::ENDPOINT.'/'.$id, [
            'items' => [['catalog_item_id' => $this->otherCatalogItemId, 'unit_price' => '99', 'quantity' => '2']],
        ], $this->bearerFor(RoleName::Manager))->assertStatus(200);

        $rows = DB::table('supplier_quotation_items')->where('supplier_quotation_id', $id)->get();

        self::assertCount(3, $rows, 'the two replaced lines must still be on the table');
        self::assertCount(2, $rows->whereNotNull('deleted_at'));
        self::assertCount(1, $rows->whereNull('deleted_at'));
    }

    /** `PATCH` without `items` is not an instruction to delete the lines. */
    public function test_that_omitting_items_leaves_the_lines_alone(): void
    {
        $id = $this->created();

        $this->patchJson(self::ENDPOINT.'/'.$id, ['notes' => 'Revised'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertJsonCount(2, 'data.items');
    }

    /** An explicit empty list is an instruction, and it is obeyed. */
    public function test_that_an_empty_item_list_clears_the_lines(): void
    {
        $id = $this->created();

        $this->patchJson(self::ENDPOINT.'/'.$id, ['items' => []], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertJsonPath('data.items', []);
    }

    /**
     * The owner's ruling of 2026-09-02 survives an edit too: `total_price` is
     * entered, never summed. Replacing the lines with a set totalling 198 leaves
     * the stated total where it was.
     */
    public function test_that_replacing_the_lines_does_not_recompute_the_total(): void
    {
        $id = $this->created();

        $this->patchJson(self::ENDPOINT.'/'.$id, [
            'items' => [['catalog_item_id' => $this->otherCatalogItemId, 'unit_price' => '99', 'quantity' => '2']],
        ], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.total_price', '4500.000000');
    }

    /**
     * The boundary refuses a bad line and nothing on the offer moves.
     *
     * **This does not prove the transaction**, and the name used to claim it
     * did. Measured: with `UpdateSupplierQuotation`'s transaction removed, all
     * 26 tests here still passed — Point 2.2's Form Request rejects
     * `quantity: 0` with a 422 before a single write is attempted, so there is
     * nothing for a rollback to undo. `DB-11` is proved a layer down, in
     * `UpdateSupplierQuotationTest`, where the use case is called directly with
     * a `catalog_item_id` the foreign key refuses.
     */
    public function test_that_a_refused_line_leaves_the_offer_untouched(): void
    {
        $id = $this->created();

        $this->patchJson(self::ENDPOINT.'/'.$id, [
            'notes' => 'Should not survive',
            'items' => [['catalog_item_id' => $this->otherCatalogItemId, 'unit_price' => '99', 'quantity' => '0']],
        ], $this->bearerFor(RoleName::Manager))->assertStatus(422);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertJsonPath('data.notes', null)
            ->assertJsonCount(2, 'data.items');
    }

    // ───────────────────────────────────────────────────────────────── audit

    /** `AUD-01` names update explicitly; `AUD-02` wants the old value beside the new. */
    public function test_that_an_edit_is_written_to_the_audit_log(): void
    {
        $id = $this->created();

        $this->patchJson(self::ENDPOINT.'/'.$id, ['notes' => 'Revised'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);

        $row = DB::table('audit_log')
            ->where('entity_type', 'supplier_quotation')
            ->where('entity_id', $id)
            ->where('event', 'SUPPLIER_QUOTATION_UPDATED')
            ->first();

        self::assertInstanceOf(stdClass::class, $row);
        $new = $row->new_values;
        self::assertIsString($new);
        self::assertStringContainsString('Revised', $new);
    }

    /** An edit that changes nothing is not an event. */
    public function test_that_an_edit_changing_nothing_writes_no_audit_row(): void
    {
        $id = $this->created();

        $this->patchJson(self::ENDPOINT.'/'.$id, [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);

        self::assertSame(0, DB::table('audit_log')
            ->where('entity_id', $id)
            ->where('event', 'SUPPLIER_QUOTATION_UPDATED')
            ->count());
    }

    // ─────────────────────────────────────────────────────────────────── 404

    public function test_that_editing_an_unknown_offer_is_not_found(): void
    {
        $this->patchJson(self::ENDPOINT.'/'.Uuid::uuid4()->toString(), ['notes' => 'x'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    /** `DB-01` again, and `OpenAPI §5.1`'s "do not reveal which case applies". */
    public function test_that_editing_a_soft_deleted_offer_is_not_found(): void
    {
        $id = $this->created();

        DB::table('supplier_quotations')->where('id', $id)->update(['deleted_at' => now()]);

        $this->patchJson(self::ENDPOINT.'/'.$id, ['notes' => 'x'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    /** `OpenAPI §9.2` is not adopted here, so no route demands `If-Match`. */
    public function test_that_no_if_match_header_is_required(): void
    {
        $id = $this->created();

        $this->patchJson(self::ENDPOINT.'/'.$id, ['notes' => 'Revised'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);
    }

    // ───────────────────────────────────────────────────────────────── helpers

    /**
     * Point 3.5, end to end: the module's acceptance criterion answered by the
     * edit as well as the create — `PATCH` replaces the lines, and a name the
     * catalog does not carry becomes a product rather than a `42703`.
     */
    /**
     * §5.6 on the edit: the resulting offer, not the payload, must pair every
     * priced line with a currency. Blanking the pair under existing lines is
     * refused; naming only the lines is fine while the stored currency stands.
     */
    public function test_that_blanking_the_currency_under_priced_lines_is_refused(): void
    {
        $id = $this->created();

        $this->patchJson(self::ENDPOINT.'/'.$id, ['total_price' => null, 'currency_id' => null], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'currency_id');

        self::assertSame($this->currencyId, DB::table('supplier_quotations')->where('id', $id)->value('currency_id'));
    }

    public function test_that_adding_lines_to_an_offer_without_a_currency_is_refused(): void
    {
        $id = $this->postJson(self::ENDPOINT, $this->payload(['total_price' => null, 'currency_id' => null, 'items' => []]), $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->json('data.id');
        self::assertIsString($id);

        $this->patchJson(self::ENDPOINT.'/'.$id, ['items' => [
            ['catalog_item_id' => $this->catalogItemId, 'unit_price' => '10', 'quantity' => '2'],
        ]], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'currency_id');

        self::assertSame(0, DB::table('supplier_quotation_items')->where('supplier_quotation_id', $id)->whereNull('deleted_at')->count());
    }

    public function test_that_a_replacement_line_may_name_a_product_the_catalog_lacks(): void
    {
        $id = $this->created();

        $this->patchJson(self::ENDPOINT.'/'.$id, ['items' => [
            ['product_name' => 'Copper Cable 4mm', 'unit_price' => '10', 'quantity' => '2'],
        ]], $this->bearerFor(RoleName::Manager))->assertStatus(200);

        $product = DB::table('catalog_items')->where('name', 'Copper Cable 4mm')->first();

        self::assertNotNull($product, 'D-22: the catalog did not gain the product the edit named.');
        self::assertSame($product->id, DB::table('supplier_quotation_items')
            ->where('supplier_quotation_id', $id)
            ->whereNull('deleted_at')
            ->value('catalog_item_id'));
    }

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
