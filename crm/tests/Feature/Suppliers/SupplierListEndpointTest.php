<?php

declare(strict_types=1);

namespace Tests\Feature\Suppliers;

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
 * Module 4, Point 2.1 — `GET /suppliers` and `GET /suppliers/{id}`.
 *
 * ── §3.7 is not §3.3, and that is the whole shape of this endpoint ─────────
 *
 * §3.3 gives customers five different scopes and Module 3 needed a row scope to
 * express them. §3.7 has **two columns** — "All operational roles" and CEO —
 * and the seeded matrix backs that with `Scope::All` for every one of them
 * (`PermissionMatrix`, §3.7). So there is **no row scope here at all**: every
 * role that can see the screen sees every supplier. The test below proves that
 * for all seven roles rather than asserting it once for a Manager, because
 * "everyone sees everything" is the kind of claim that is only worth as much as
 * its worst role.
 *
 * The CEO's ✅ is annotated "read-only" in §3.7, which is the absence of the
 * `manage` grant rather than a scope — so the CEO reads through this endpoint
 * exactly like everybody else. Writing is Point 2.2's, and this point publishes
 * no write route at all.
 *
 * ── Deactivated suppliers are listed by default, unlike archived customers ──
 *
 * A deliberate difference, not an oversight. §9 Flow 7 says a customer is
 * archived to take it *out of the working list*, so Module 3 defaults
 * `filter[is_archived]` to false. Nothing says that of a supplier: §10.4's
 * hiding rule is about **selection lists** on a quotation, which are Modules
 * 6/7 and not this screen. A management list that hid deactivated suppliers by
 * default would also be a list from which nobody could ever reactivate one.
 * `filter[is_active]` is therefore tri-state and unset means both.
 */
final class SupplierListEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/suppliers';

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
    public function test_that_every_role_section_3_7_grants_view_to_sees_every_supplier(RoleName $role): void
    {
        $this->supplier('Alpha Supply');
        $this->supplier('Beta Distribution');

        $body = $this->getJson(self::ENDPOINT, $this->bearerFor($role))
            ->assertStatus(200)
            ->json('data');

        self::assertIsArray($body);
        self::assertCount(2, $body, $role->value.' cannot see both suppliers, and §3.7 grants All.');
    }

    /** §3.1's unconditional access answers before the grant list is consulted. */
    public function test_that_the_super_admin_sees_the_list(): void
    {
        $this->supplier('Alpha Supply');

        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::SuperAdmin))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    /**
     * The permission is a database row, and removing it refuses the call.
     *
     * This is the negative test `CLAUDE.md` requires of every endpoint, and it
     * is written by **withdrawing the seeded grant** rather than by finding a
     * role that lacks it — because under §3.7 no role lacks it. It therefore
     * proves the thing worth proving: enforcement reads the matrix from the
     * database (§3.12 rule 5, `SEC-07`) instead of hard-coding it.
     */
    public function test_that_a_role_whose_grant_is_withdrawn_is_refused(): void
    {
        $this->supplier('Alpha Supply');

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
        $this->supplier('Alpha Supply');

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

    /** §7.1's fields, and the colour the chip is drawn from. */
    public function test_that_a_supplier_serialises_the_fields_section_7_1_publishes(): void
    {
        $this->supplier('Alpha Supply', colour: 'red', type: 'distributor', openAccount: true);

        $row = $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->json('data.0');

        self::assertIsArray($row);

        foreach (['id', 'name', 'type', 'color_rating', 'phone', 'contact_person',
            'has_open_account', 'is_active', 'created_at', 'updated_at'] as $field) {
            self::assertArrayHasKey($field, $row, "§7.1 publishes `{$field}`.");
        }

        self::assertSame('red', $row['color_rating']);
        self::assertSame('distributor', $row['type']);
        self::assertTrue($row['has_open_account']);
    }

    /** `D-21`: the catalog and its suppliers hold no price, so none can be serialised. */
    public function test_that_no_price_reaches_the_wire(): void
    {
        $this->supplier('Alpha Supply');

        $row = $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->json('data.0');

        self::assertIsArray($row);

        foreach (array_keys($row) as $field) {
            self::assertIsString($field);
            self::assertDoesNotMatchRegularExpression('/price|cost|margin/i', $field, 'D-21 keeps prices on the offer.');
        }
    }

    // ───────────────────────────────────────────────────────────── the detail

    public function test_that_the_detail_route_answers_with_the_record(): void
    {
        $id = $this->supplier('Alpha Supply');

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.name', 'Alpha Supply');
    }

    public function test_that_an_unknown_supplier_is_not_found(): void
    {
        $this->getJson(self::ENDPOINT.'/'.Str::uuid7()->toString(), $this->bearerFor(RoleName::Manager))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    /** `DB-01`: a soft-deleted row is gone as far as every read is concerned. */
    public function test_that_a_soft_deleted_supplier_is_absent_from_both_routes(): void
    {
        $id = $this->supplier('Alpha Supply');

        DB::table('suppliers')->where('id', $id)->update(['deleted_at' => now()]);

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
        $this->getJson(self::ENDPOINT.'?sort=phone', $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->assertJsonPath('error.details.0.code', 'unknown_sort_field');
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

    // ────────────────────────────────────────────────────────────── filtering

    public function test_that_the_colour_filter_narrows_to_that_rating(): void
    {
        $this->supplier('Red One', colour: 'red');
        $this->supplier('Green One', colour: 'green');

        $names = $this->names('?filter[color_rating]=red');

        self::assertSame(['Red One'], $names);
    }

    public function test_that_the_type_filter_narrows(): void
    {
        $this->supplier('A Distributor', type: 'distributor');
        $this->supplier('A Supplier', type: 'supplier');

        self::assertSame(['A Distributor'], $this->names('?filter[type]=distributor'));
    }

    public function test_that_the_open_account_filter_narrows(): void
    {
        $this->supplier('On Account', openAccount: true);
        $this->supplier('Cash Only', openAccount: false);

        self::assertSame(['On Account'], $this->names('?filter[has_open_account]=true'));
    }

    public function test_that_the_active_filter_narrows_to_the_deactivated_when_asked(): void
    {
        $this->supplier('Still Trading', active: true);
        $this->supplier('Retired', active: false);

        self::assertSame(['Retired'], $this->names('?filter[is_active]=false'));
    }

    /**
     * The deliberate difference from Module 3's archived customers.
     *
     * §10.4 hides a deactivated supplier from **selection lists**, which are
     * Modules 6/7. This is the management screen, and a screen that hid them by
     * default would be one nobody could reactivate from.
     */
    public function test_that_a_deactivated_supplier_is_listed_when_no_filter_asks_otherwise(): void
    {
        $this->supplier('Still Trading', active: true);
        $this->supplier('Retired', active: false);

        self::assertSame(['Retired', 'Still Trading'], $this->names(''));
    }

    // ─────────────────────────────────────────────────────── search and sorting

    /** `D-48` and `OpenAPI §6.2`: `q` always passes through `SearchService`. */
    public function test_that_the_free_text_search_narrows_by_name(): void
    {
        $this->supplier('Alpha Supply');
        $this->supplier('Beta Distribution');

        self::assertSame(['Alpha Supply'], $this->names('?q=alpha'));
    }

    /** §14.2's Arabic folding, through the same service. */
    public function test_that_the_search_folds_arabic_orthography(): void
    {
        $this->supplier('أحمد للتوريدات');

        self::assertSame(['أحمد للتوريدات'], $this->names('?q=احمد'));
    }

    public function test_that_the_default_order_is_by_name(): void
    {
        $this->supplier('Zeta');
        $this->supplier('Alpha');

        self::assertSame(['Alpha', 'Zeta'], $this->names(''));
    }

    public function test_that_a_leading_minus_reverses_the_order(): void
    {
        $this->supplier('Alpha');
        $this->supplier('Zeta');

        self::assertSame(['Zeta', 'Alpha'], $this->names('?sort=-name'));
    }

    public function test_that_the_page_size_and_page_number_are_honoured(): void
    {
        $this->supplier('Alpha');
        $this->supplier('Beta');
        $this->supplier('Gamma');

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

    /** Inserted directly: 2.1 is the read side, and there is no create endpoint until 2.2. */
    private function supplier(
        string $name,
        string $colour = 'white',
        ?string $type = null,
        bool $openAccount = false,
        bool $active = true,
    ): string {
        $id = (string) Str::uuid7();

        DB::table('suppliers')->insert([
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'color_rating' => $colour,
            'has_open_account' => $openAccount,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
