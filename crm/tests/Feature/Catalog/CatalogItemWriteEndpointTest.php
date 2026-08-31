<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Writing\CatalogItemDraft;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Module 4, Point 3.2 — `POST /catalog-items` and `PATCH /catalog-items/{id}`.
 *
 * ── The same permission as the supplier writes, because §3.7 is one table ──
 *
 * There is no `catalog_item.*` resource in the matrix: §3.7 covers the catalog
 * **and** its suppliers, and its write row is a single cell — "create · edit ·
 * deactivate · set colour ✅". So this endpoint carries `catalog.manage`, has
 * no `/deactivate` action suffix, and the CEO — whose ✅ is annotated
 * "read-only", the absence of the `manage` grant — is the documented negative
 * case.
 *
 * ── What is specific to the catalog ────────────────────────────────────────
 *
 * §7.3's two tabs are one table split by `kind`, so the conditional rules land
 * here rather than in the migration, which deliberately carries no cross-field
 * CHECK: a product needs a name and a unit, a service needs a service type.
 * And §7.3 opens "Descriptive data only — **no prices**" (`D-21`), which this
 * file checks on the wire rather than only in the schema.
 *
 * ── The audit is the point, not a side effect ──────────────────────────────
 *
 * `D-45` opened catalog editing to every employee and named the mitigation:
 * "every edit is written to the audit log". That makes `AUD-01`'s record the
 * control this module rests on, and the build plan's acceptance criterion
 * *"Every catalog edit is written to the audit log"* is earned here.
 */
final class CatalogItemWriteEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/catalog-items';

    private const PASSWORD = 'Passw0rd123';

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * §3.7's "All operational roles" — the CEO is deliberately absent.
     *
     * @return array<string, array{RoleName}>
     */
    public static function writers(): array
    {
        return [
            'manager' => [RoleName::Manager],
            'team leader' => [RoleName::TeamLeader],
            'outdoor supervisor' => [RoleName::OutdoorSupervisor],
            'outdoor sales' => [RoleName::OutdoorSales],
            'indoor sales' => [RoleName::IndoorSales],
            'procurement' => [RoleName::Procurement],
        ];
    }

    /**
     * §7.3's two tabs, from the one place the write vocabulary declares them.
     *
     * @return list<array{string}>
     */
    public static function kinds(): array
    {
        return array_map(static fn (string $k): array => [$k], CatalogItemDraft::KINDS);
    }

    /**
     * §7.3 is "descriptive data only" and `D-21` puts all three on the offer.
     *
     * @return array<string, array{string}>
     */
    public static function priceFields(): array
    {
        return ['price' => ['price'], 'cost' => ['cost'], 'margin' => ['margin']];
    }

    // ──────────────────────────────────────────────────────────── the create

    public function test_that_an_unauthenticated_caller_cannot_create(): void
    {
        $this->postJson(self::ENDPOINT, self::product())->assertStatus(401);
    }

    #[DataProvider('writers')]
    public function test_that_every_role_section_3_7_grants_manage_to_can_create(RoleName $role): void
    {
        $this->postJson(self::ENDPOINT, self::product(), $this->bearerFor($role))
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Copper Cable');
    }

    /** §3.7 annotates the CEO's view "read-only" — the absence of the `manage` grant. */
    public function test_that_the_read_only_ceo_cannot_create(): void
    {
        $this->postJson(self::ENDPOINT, self::product(), $this->bearerFor(RoleName::Ceo))
            ->assertStatus(403);

        self::assertSame(0, DB::table('catalog_items')->count());
    }

    #[DataProvider('kinds')]
    public function test_that_both_tabs_section_7_3_defines_can_be_created(string $kind): void
    {
        $payload = $kind === 'product' ? self::product() : self::service();

        $this->postJson(self::ENDPOINT, $payload, $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->assertJsonPath('data.kind', $kind);
    }

    public function test_that_a_created_item_takes_section_7_3_defaults(): void
    {
        $body = $this->postJson(self::ENDPOINT, self::product(), $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->json('data');

        self::assertIsArray($body);
        self::assertTrue($body['is_active'], '§7.3: a newly added product is active.');
        self::assertNull($body['service_type'], 'A product carries no service type.');
        self::assertNull($body['notes']);
    }

    /** `AUD-01` names create, and `D-45` makes this record the mitigation. */
    public function test_that_a_create_is_written_to_the_audit_log(): void
    {
        $id = $this->created(self::product());

        $row = DB::table('audit_log')
            ->where('event', 'CATALOG_ITEM_CREATED')
            ->where('entity_id', $id)
            ->first();

        self::assertNotNull($row, 'AUD-01 requires a record of the create.');
        self::assertSame('catalog_item', $row->entity_type);

        // `AuditRecorderInterface` documents old values as "absent on a create";
        // an empty array would read as "it used to be nothing".
        self::assertNull($row->old_values);

        $new = json_decode(self::jsonColumn($row->new_values), true);
        self::assertIsArray($new);
        self::assertSame('Copper Cable', $new['name']);
        self::assertSame('product', $new['kind']);
    }

    // ──────────────────────────────────────────────── the boundary refusals

    public function test_that_an_item_in_neither_tab_is_refused(): void
    {
        $this->postJson(self::ENDPOINT, ['name' => 'Orphan', 'company' => 'Alpha Co'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422);
    }

    public function test_that_a_kind_section_7_3_does_not_define_is_refused(): void
    {
        $this->postJson(self::ENDPOINT, ['kind' => 'bundle', 'name' => 'X', 'company' => 'Alpha Co'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422);
    }

    /** §7.3 names the product by its name; the migration left the column nullable for a service. */
    public function test_that_a_product_without_a_name_is_refused(): void
    {
        $payload = self::product();
        unset($payload['name']);

        $this->postJson(self::ENDPOINT, $payload, $this->bearerFor(RoleName::Manager))->assertStatus(422);
    }

    /** `required` accepts "   ", and the table's CHECK would answer with a 500. */
    public function test_that_a_blank_name_is_refused_at_the_boundary(): void
    {
        $this->postJson(self::ENDPOINT, ['kind' => 'product', 'name' => '   ', 'unit' => 'piece', 'company' => 'Alpha Co'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422);
    }

    public function test_that_a_product_without_a_unit_is_refused(): void
    {
        $payload = self::product();
        unset($payload['unit']);

        $this->postJson(self::ENDPOINT, $payload, $this->bearerFor(RoleName::Manager))->assertStatus(422);
    }

    public function test_that_a_service_without_a_service_type_is_refused(): void
    {
        $payload = self::service();
        unset($payload['service_type']);

        $this->postJson(self::ENDPOINT, $payload, $this->bearerFor(RoleName::Manager))->assertStatus(422);
    }

    /**
     * §7.3 lists "Providing team / company" in the Service column and marks
     * nothing required. **Owner's ruling, 2026-08-31: it is required for both
     * tabs**, because §7.3 also opens with "grouped by company/team name" and a
     * row with no company falls out of the only grouping the screen has. The
     * column stays nullable — a `NOT NULL` migration would fail on the rows
     * that already have none — so the rule lives at the boundary, the same
     * shape as `name`. Awaiting a `D-xx`; recorded in `CHECKLIST.md`.
     */
    #[DataProvider('kinds')]
    public function test_that_an_item_without_a_company_is_refused(string $kind): void
    {
        $payload = $kind === 'product' ? self::product() : self::service();
        unset($payload['company']);

        $this->postJson(self::ENDPOINT, $payload, $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'company');
    }

    /** §7.3's Service row is identified by its type; the name is the product tab's requirement. */
    public function test_that_a_service_without_a_name_is_accepted(): void
    {
        $payload = self::service();
        unset($payload['name']);

        $this->postJson(self::ENDPOINT, $payload, $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->assertJsonPath('data.name', null);
    }

    /** §7.3: "descriptive data only — no prices". `D-21` puts every price on the supplier quotation. */
    #[DataProvider('priceFields')]
    public function test_that_a_price_bearing_field_is_refused_rather_than_ignored(string $field): void
    {
        $this->postJson(self::ENDPOINT, self::product() + [$field => 10], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422);

        self::assertSame(0, DB::table('catalog_items')->count());
    }

    // ──────────────────────────────────────────────────────────── the update

    public function test_that_an_edit_changes_only_what_it_names(): void
    {
        $id = $this->created(self::product());

        $this->patchJson(self::ENDPOINT.'/'.$id, ['category' => 'Wiring'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.category', 'Wiring')
            ->assertJsonPath('data.name', 'Copper Cable')
            ->assertJsonPath('data.unit', 'piece');
    }

    /** §3.7's "deactivate" is a field on the row, not an action suffix. */
    public function test_that_deactivation_is_an_edit_and_never_a_deletion(): void
    {
        $id = $this->created(self::product());

        $this->patchJson(self::ENDPOINT.'/'.$id, ['is_active' => false], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        $row = DB::table('catalog_items')->where('id', $id)->first();

        self::assertNotNull($row, '§3.12 rule 3 forbids deleting catalog data.');
        self::assertNull($row->deleted_at, 'Deactivation must not touch DB-01\'s soft delete.');
    }

    public function test_that_an_edit_is_written_to_the_audit_log_with_both_values(): void
    {
        $id = $this->created(self::product());

        $this->patchJson(self::ENDPOINT.'/'.$id, ['unit' => 'metre'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);

        $row = DB::table('audit_log')
            ->where('event', 'CATALOG_ITEM_UPDATED')
            ->where('entity_id', $id)
            ->first();

        self::assertNotNull($row, 'AUD-01 names update, and D-45 makes it the mitigation.');

        // `AUD-02` wants the old value beside the new one, and limited to what
        // this write touched — not the whole row.
        $old = json_decode(self::jsonColumn($row->old_values), true);
        $new = json_decode(self::jsonColumn($row->new_values), true);

        self::assertIsArray($old);
        self::assertIsArray($new);
        self::assertSame('piece', $old['unit']);
        self::assertSame('metre', $new['unit']);
        self::assertArrayNotHasKey('name', $old, 'AUD-02: only the fields this write replaced.');
    }

    /** A PATCH naming no writable field changed nothing, and must not claim it did. */
    public function test_that_an_edit_changing_nothing_writes_no_audit_row(): void
    {
        $id = $this->created(self::product());

        $this->patchJson(self::ENDPOINT.'/'.$id, [], $this->bearerFor(RoleName::Manager))->assertStatus(200);

        self::assertSame(
            0,
            DB::table('audit_log')->where('event', 'CATALOG_ITEM_UPDATED')->where('entity_id', $id)->count(),
        );
    }

    public function test_that_the_read_only_ceo_cannot_edit(): void
    {
        $id = $this->created(self::product());

        $this->patchJson(self::ENDPOINT.'/'.$id, ['name' => 'Renamed'], $this->bearerFor(RoleName::Ceo))
            ->assertStatus(403);

        self::assertSame('Copper Cable', DB::table('catalog_items')->where('id', $id)->value('name'));
    }

    public function test_that_editing_an_unknown_item_is_not_found(): void
    {
        $this->patchJson(self::ENDPOINT.'/'.Str::uuid7()->toString(), ['name' => 'X'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(404);
    }

    // ─────────────────────────────────────────────── §3.12 rule 3 — no delete

    public function test_that_no_delete_route_exists(): void
    {
        $id = $this->created(self::product());

        $this->deleteJson(self::ENDPOINT.'/'.$id, [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(405);

        self::assertSame(1, DB::table('catalog_items')->count());
    }

    // ───────────────────────────────────────────────────────────── helpers

    /** @return array<string, mixed> */
    private static function product(): array
    {
        return ['kind' => 'product', 'name' => 'Copper Cable', 'unit' => 'piece', 'company' => 'Alpha Co'];
    }

    /** @return array<string, mixed> */
    private static function service(): array
    {
        return ['kind' => 'service', 'name' => 'Rack Install', 'service_type' => 'installation', 'company' => 'Alpha Co'];
    }

    /**
     * `DB::table()->first()` answers untyped objects, so a column is `mixed`.
     * Narrowed by assertion rather than by a cast, which is the escape hatch
     * Coding Standards §5 forbids.
     */
    private static function jsonColumn(mixed $value): string
    {
        self::assertIsString($value, 'The audit column should hold a JSON document.');

        return $value;
    }

    /** @param array<string, mixed> $attributes */
    private function created(array $attributes): string
    {
        $id = $this->postJson(self::ENDPOINT, $attributes, $this->bearerFor(RoleName::Manager))
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
