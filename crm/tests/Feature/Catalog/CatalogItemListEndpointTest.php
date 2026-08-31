<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Module 4, Point 3.1 — `GET /catalog-items` and `GET /catalog-items/{id}`.
 *
 * ── One table, two tabs, and `filter[kind]` is the tab ─────────────────────
 *
 * §7.3 publishes "Two tabs: Product · Service", and Point 1.2 put both in one
 * table behind `kind`. The build plan asks for two separate outcomes — a
 * product "appears under the Product tab" and a service "appears under the
 * Service tab, **separate from products**" — so the separation is a filter this
 * endpoint declares rather than a second route.
 *
 * ── `group_by=company`, and the shape it does *not* change ─────────────────
 *
 * `API-06` requires server-side grouping "by employee / company" and §7.3 says
 * the catalog is "grouped by company/team name". `OpenAPI §6.2` adds that each
 * resource declares its allowed groups and that an undeclared one is a 400.
 *
 * What none of them specifies is the **response shape** of a grouped
 * collection, and §4.2 shows exactly one collection envelope. So `group_by`
 * changes the **order** — every row of a company adjacent, companies
 * alphabetical, the caller's `sort` applied inside each — and leaves `data` a
 * flat paginated list. That keeps §4.2's envelope and `API-04`'s row-based
 * pagination intact, and it is still server-side grouping: the client names a
 * declared group and cannot ask for an arbitrary one.
 *
 * ── The permission is `catalog.view`, and there is no supplier of it ───────
 *
 * §3.7 is one table covering the catalog *and* its suppliers, so these routes
 * carry the same two permissions the supplier routes carry. Every grant in it
 * is `Scope::All`, so there is no row scope to test — and no role lacks
 * `catalog.view`, which is why the negative test withdraws the seeded grant.
 */
final class CatalogItemListEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/catalog-items';

    private const PASSWORD = 'Passw0rd123';

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        // §3.12 rule 5 puts the matrix in the database, so the seeded §3.7 rows
        // are the authority — not a fixture written here that agrees with itself.
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * §3.7's "All operational roles", plus the CEO's read-only column.
     *
     * @return array<string, array{RoleName}>
     */
    public static function readers(): array
    {
        return [
            'manager' => [RoleName::Manager],
            'team leader' => [RoleName::TeamLeader],
            'outdoor supervisor' => [RoleName::OutdoorSupervisor],
            'outdoor sales' => [RoleName::OutdoorSales],
            'indoor sales' => [RoleName::IndoorSales],
            'procurement' => [RoleName::Procurement],
            'ceo' => [RoleName::Ceo],
        ];
    }

    // ─────────────────────────────────────────────── the envelope and access

    public function test_that_an_unauthenticated_caller_is_refused(): void
    {
        $this->getJson(self::ENDPOINT)->assertStatus(401);
    }

    /**
     * §3.7 grants `catalog.view` to every operational role and to the CEO.
     *
     * All seven, because a table with two columns is exactly the kind that gets
     * transcribed with one row missing.
     */
    #[DataProvider('readers')]
    public function test_that_every_role_section_3_7_grants_view_to_sees_every_item(RoleName $role): void
    {
        $this->item('Drill');
        $this->item('Installation', kind: 'service');

        $body = $this->getJson(self::ENDPOINT, $this->bearerFor($role))
            ->assertStatus(200)
            ->json('data');

        self::assertIsArray($body);
        self::assertCount(2, $body, $role->value.' cannot see both items, and §3.7 grants All.');
    }

    /** §3.1's unconditional access answers before the grant list is consulted. */
    public function test_that_the_super_admin_sees_the_list(): void
    {
        $this->item('Drill');

        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    /**
     * The permission is a database row, and removing it refuses the call.
     *
     * The negative test `CLAUDE.md` requires, written by **withdrawing the
     * seeded grant** rather than by finding a role that lacks it — because
     * under §3.7 no role lacks it. It proves the thing worth proving:
     * enforcement reads the matrix from the database (§3.12 rule 5, `SEC-07`).
     */
    public function test_that_a_role_whose_grant_is_withdrawn_is_refused(): void
    {
        $this->item('Drill');

        $headers = $this->bearerFor(RoleName::IndoorSales);

        $this->getJson(self::ENDPOINT, $headers)->assertStatus(200);

        $role = Role::query()->where('slug', RoleName::IndoorSales->value)->firstOrFail();
        $permission = Permission::query()
            ->where('resource', 'catalog')
            ->where('action', 'view')
            ->firstOrFail();

        DB::table('role_permissions')
            ->where('role_id', $role->id)
            ->where('permission_id', $permission->id)
            ->update(['deleted_at' => now()]);

        $this->getJson(self::ENDPOINT, $headers)->assertStatus(403);
    }

    public function test_that_the_payload_is_the_documented_collection_envelope(): void
    {
        $this->item('Drill');

        $body = $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->json();

        self::assertIsArray($body);
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('meta', $body);

        $meta = $body['meta'];
        self::assertIsArray($meta);
        self::assertArrayHasKey('request_id', $meta);
        self::assertArrayHasKey('pagination', $meta);

        $pagination = $meta['pagination'];
        self::assertIsArray($pagination);

        foreach (['page', 'per_page', 'total', 'total_pages', 'has_next_page', 'has_previous_page'] as $key) {
            self::assertArrayHasKey($key, $pagination, "OpenAPI §4.2 requires `{$key}`.");
        }

        self::assertSame(25, $pagination['per_page'], 'OpenAPI §6.1 sets the default page size to 25.');
    }

    /** §7.3's fields, both tabs' columns on one row. */
    public function test_that_an_item_serialises_the_fields_section_7_3_publishes(): void
    {
        $this->item('Drill', code: 'DR-9', category: 'power tools', unit: 'piece', company: 'Alpha Co');

        $row = $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->json('data.0');

        self::assertIsArray($row);

        foreach (['id', 'kind', 'name', 'product_code', 'category', 'unit', 'service_type',
            'company', 'description', 'notes', 'is_active', 'created_at', 'updated_at'] as $field) {
            self::assertArrayHasKey($field, $row, "§7.3 publishes `{$field}`.");
        }

        self::assertSame('product', $row['kind']);
        self::assertSame('DR-9', $row['product_code']);
        self::assertSame('piece', $row['unit']);
        self::assertSame('Alpha Co', $row['company']);
        self::assertNull($row['service_type'], 'A product has no service type.');
    }

    /**
     * §7.3 opens "Descriptive data only — **no prices**", and `D-21` keeps every
     * price on the supplier quotation.
     *
     * Point 1.2 asserted the absence in the schema; this asserts it on the wire,
     * which is the half a future join could break without touching a migration.
     */
    public function test_that_no_price_reaches_the_wire(): void
    {
        $this->item('Drill');

        $row = $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->json('data.0');

        self::assertIsArray($row);

        foreach (array_keys($row) as $field) {
            self::assertIsString($field);
            self::assertDoesNotMatchRegularExpression(
                '/price|cost|margin|amount|total/i',
                $field,
                'D-21 and §7.3 keep every price on the offer.',
            );
        }
    }

    // ───────────────────────────────────────────────────────────── the detail

    public function test_that_the_detail_route_answers_with_the_record(): void
    {
        $id = $this->item('Drill');

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.name', 'Drill');
    }

    public function test_that_an_unknown_item_is_not_found(): void
    {
        $this->getJson(self::ENDPOINT.'/'.Str::uuid7()->toString(), $this->bearerFor(RoleName::Manager))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    /** `DB-01`: a soft-deleted row is gone as far as every read is concerned. */
    public function test_that_a_soft_deleted_item_is_absent_from_both_routes(): void
    {
        $id = $this->item('Drill');

        DB::table('catalog_items')->where('id', $id)->update(['deleted_at' => now()]);

        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))->assertStatus(404);
    }

    // ─────────────────────────────────────────── OpenAPI §6.2 — the closed sets

    public function test_that_an_undeclared_filter_is_refused_rather_than_ignored(): void
    {
        $this->getJson(self::ENDPOINT.'?filter[secret]=1', $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonPath('error.details.0.code', 'unknown_filter');
    }

    public function test_that_an_undeclared_sort_is_refused(): void
    {
        $this->getJson(self::ENDPOINT.'?sort=notes', $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->assertJsonPath('error.details.0.code', 'unknown_sort_field');
    }

    /** §6.2: "Reject unknown filter, sort, **group**, or include values". */
    public function test_that_an_undeclared_group_is_refused(): void
    {
        $this->getJson(self::ENDPOINT.'?group_by=notes', $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonPath('error.details.0.field', 'group_by')
            ->assertJsonPath('error.details.0.code', 'unknown_group');
    }

    public function test_that_a_page_size_above_the_maximum_is_refused(): void
    {
        $this->getJson(self::ENDPOINT.'?per_page=101', $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->assertJsonPath('error.details.0.code', 'above_maximum');
    }

    public function test_that_a_page_that_is_not_a_whole_number_is_refused(): void
    {
        $this->getJson(self::ENDPOINT.'?page=1.5', $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->assertJsonPath('error.details.0.code', 'not_a_positive_integer');
    }

    /** `OpenAPI §5.1`'s `details` is an array of objects, never a map. */
    public function test_that_the_error_details_are_a_list(): void
    {
        $details = $this->getJson(self::ENDPOINT.'?filter[secret]=1', $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->json('error.details');

        self::assertIsArray($details);
        self::assertArrayHasKey(0, $details);
        self::assertIsArray($details[0]);
        self::assertArrayHasKey('field', $details[0]);
        self::assertArrayHasKey('code', $details[0]);
        self::assertArrayHasKey('message', $details[0]);
    }

    // ────────────────────────────────────────────────────── §7.3's two tabs

    public function test_that_the_kind_filter_shows_the_product_tab_alone(): void
    {
        $this->item('Drill');
        $this->item('Installation', kind: 'service');

        self::assertSame(['Drill'], $this->names('?filter[kind]=product'));
    }

    /** The build plan: a service "appears under the Service tab, separate from products". */
    public function test_that_the_kind_filter_shows_the_service_tab_separately(): void
    {
        $this->item('Drill');
        $this->item('Installation', kind: 'service');

        self::assertSame(['Installation'], $this->names('?filter[kind]=service'));
    }

    public function test_that_no_kind_filter_lists_both_tabs(): void
    {
        $this->item('Drill');
        $this->item('Installation', kind: 'service');

        self::assertSame(['Drill', 'Installation'], $this->names(''));
    }

    /** §7.3 annotates the column "Category (for search)". */
    public function test_that_the_category_filter_narrows(): void
    {
        $this->item('Drill', category: 'power tools');
        $this->item('Hammer', category: 'hand tools');

        self::assertSame(['Drill'], $this->names('?filter[category]=power tools'));
    }

    /**
     * §7.3 groups the catalog "by company/team name", and `group_by=company`
     * only orders the whole list — a screen that groups by a column needs to be
     * able to ask for one group. Matched as written, the way `category` is:
     * both are the name of a thing rather than a code.
     */
    public function test_that_the_company_filter_narrows(): void
    {
        $this->item('Drill', company: 'Alpha Co');
        $this->item('Hammer', company: 'Beta Co');

        self::assertSame(['Drill'], $this->names('?filter[company]=Alpha Co'));
    }

    public function test_that_the_active_filter_narrows_to_the_deactivated_when_asked(): void
    {
        $this->item('Drill', active: true);
        $this->item('Retired Drill', active: false);

        self::assertSame(['Retired Drill'], $this->names('?filter[is_active]=false'));
    }

    /**
     * §10.4 hides a deactivated item from **selection lists**, which are Modules
     * 6/7. This is the management screen, and one that hid them by default would
     * be a screen from which nobody could reactivate an item.
     */
    public function test_that_a_deactivated_item_is_listed_when_no_filter_asks_otherwise(): void
    {
        $this->item('Drill', active: true);
        $this->item('Retired Drill', active: false);

        self::assertSame(['Drill', 'Retired Drill'], $this->names(''));
    }

    // ────────────────────────────────────────────────── API-06 — the grouping

    /**
     * The build plan: a new product "appears under the Product tab, **grouped by
     * company**".
     *
     * The names are chosen so the default `name` order and the grouped order
     * disagree — otherwise the test would pass with no grouping at all.
     */
    public function test_that_grouping_by_company_orders_the_companies_together(): void
    {
        $this->item('Widget A', company: 'Beta Co');
        $this->item('Widget B', company: 'Alpha Co');

        self::assertSame(['Widget A', 'Widget B'], $this->names(''));
        self::assertSame(['Widget B', 'Widget A'], $this->names('?group_by=company'));
    }

    /** Inside a group the caller's `sort` still applies — grouping orders, it does not replace. */
    public function test_that_the_sort_still_applies_inside_each_group(): void
    {
        $this->item('Widget A', company: 'Alpha Co');
        $this->item('Widget B', company: 'Alpha Co');
        $this->item('Widget C', company: 'Beta Co');

        self::assertSame(
            ['Widget B', 'Widget A', 'Widget C'],
            $this->names('?group_by=company&sort=-name'),
        );
    }

    /** §7.3 leaves `company` optional, so the ungrouped rows need a defined place: last. */
    public function test_that_items_without_a_company_are_grouped_last(): void
    {
        $this->item('Alpha');
        $this->item('Zeta', company: 'Alpha Co');

        self::assertSame(['Zeta', 'Alpha'], $this->names('?group_by=company'));
    }

    // ─────────────────────────────────────────────────────── search and sorting

    /** `D-48` and `OpenAPI §6.2`: `q` always passes through `SearchService`. */
    public function test_that_the_free_text_search_narrows_by_name(): void
    {
        $this->item('Drill');
        $this->item('Hammer');

        self::assertSame(['Drill'], $this->names('?q=dril'));
    }

    /** §7.3 annotates the column "Category (for search)", so the search reads it too. */
    public function test_that_the_free_text_search_reads_the_category(): void
    {
        $this->item('Drill', category: 'power tools');
        $this->item('Hammer', category: 'hand tools');

        self::assertSame(['Drill'], $this->names('?q=power'));
    }

    /** §14.2's Arabic folding, through the same service. */
    public function test_that_the_search_folds_arabic_orthography(): void
    {
        $this->item('مثقاب أحمد');

        self::assertSame(['مثقاب أحمد'], $this->names('?q=احمد'));
    }

    /** The tab narrows the search itself, so a capped result cannot leak the other tab. */
    public function test_that_the_search_and_the_tab_narrow_together(): void
    {
        $this->item('Drill service', kind: 'service');
        $this->item('Drill', kind: 'product');

        self::assertSame(['Drill'], $this->names('?q=drill&filter[kind]=product'));
    }

    public function test_that_the_default_order_is_by_name(): void
    {
        $this->item('Zeta');
        $this->item('Alpha');

        self::assertSame(['Alpha', 'Zeta'], $this->names(''));
    }

    public function test_that_a_leading_minus_reverses_the_order(): void
    {
        $this->item('Alpha');
        $this->item('Zeta');

        self::assertSame(['Zeta', 'Alpha'], $this->names('?sort=-name'));
    }

    public function test_that_the_page_size_and_page_number_are_honoured(): void
    {
        $this->item('Alpha');
        $this->item('Beta');
        $this->item('Gamma');

        self::assertSame(['Beta'], $this->names('?per_page=1&page=2'));

        $pagination = $this->getJson(self::ENDPOINT.'?per_page=1&page=2', $this->bearerFor(RoleName::Manager))
            ->json('meta.pagination');

        self::assertIsArray($pagination);
        self::assertSame(3, $pagination['total']);
        self::assertSame(3, $pagination['total_pages']);
        self::assertTrue($pagination['has_next_page']);
        self::assertTrue($pagination['has_previous_page']);
    }

    // ───────────────────────────────────────────────────────────── helpers

    /** @return list<string> */
    private function names(string $query): array
    {
        $rows = $this->getJson(self::ENDPOINT.$query, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->json('data');

        self::assertIsArray($rows);

        $names = [];

        foreach ($rows as $row) {
            self::assertIsArray($row);
            $name = $row['name'] ?? null;
            self::assertIsString($name);
            $names[] = $name;
        }

        return $names;
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

    /** Inserted directly: 3.1 is the read side, and there is no create endpoint until 3.2. */
    private function item(
        string $name,
        string $kind = 'product',
        ?string $code = null,
        ?string $category = null,
        ?string $unit = null,
        ?string $company = null,
        bool $active = true,
    ): string {
        $id = (string) Str::uuid7();

        DB::table('catalog_items')->insert([
            'id' => $id,
            'kind' => $kind,
            'name' => $name,
            'product_code' => $code,
            'category' => $category,
            'unit' => $unit,
            'company' => $company,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
