<?php

declare(strict_types=1);

namespace Tests\Feature\Suppliers;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Suppliers\Domain\Writing\SupplierDraft;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Module 4, Point 2.2 — `POST /suppliers` and `PATCH /suppliers/{id}`.
 *
 * ── One permission, four verbs ─────────────────────────────────────────────
 *
 * §3.7's write row is a single cell: "create · edit · deactivate · set colour
 * ✅". So there is one permission, `catalog.manage`, and no action suffix for
 * either deactivation or the colour — `OpenAPI §7.2` reserves those for actions
 * that are "not a normal resource update", and both of these are a field on the
 * row. Module 3 needed `/archive` and `/assign` because §3.3 made each of them
 * a *separate permission* with its own grants; §3.7 does not.
 *
 * ── The negative test has a real role this time ────────────────────────────
 *
 * Point 2.1 had to withdraw a seeded grant to find a caller who could not read,
 * because §3.7 grants `view` to everyone. Writing is different: the CEO's ✅ is
 * annotated **"read-only"**, which is the absence of the `manage` grant. So the
 * CEO is the documented negative case and is used as one.
 *
 * ── No delete route exists, and that is asserted ───────────────────────────
 *
 * §3.12 rule 3 forbids hard-deleting a supplier, and `catalog.delete` is seeded
 * with an empty grant array so no role can ever hold it. The absence of the
 * route is checked rather than assumed.
 */
final class SupplierWriteEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/suppliers';

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
     * §7.1's colour meanings, from the one place the write vocabulary declares them.
     *
     * @return list<array{string}>
     */
    public static function ratings(): array
    {
        return array_map(static fn (string $c): array => [$c], SupplierDraft::RATINGS);
    }

    // ──────────────────────────────────────────────────────────── the create

    public function test_that_an_unauthenticated_caller_cannot_create(): void
    {
        $this->postJson(self::ENDPOINT, ['name' => 'Alpha'])->assertStatus(401);
    }

    #[DataProvider('writers')]
    public function test_that_every_role_section_3_7_grants_manage_to_can_create(RoleName $role): void
    {
        $this->postJson(self::ENDPOINT, ['name' => 'Alpha Supply'], $this->bearerFor($role))
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'Alpha Supply');
    }

    /** §3.7 annotates the CEO's view "read-only" — the absence of the `manage` grant. */
    public function test_that_the_read_only_ceo_cannot_create(): void
    {
        $this->postJson(self::ENDPOINT, ['name' => 'Alpha Supply'], $this->bearerFor(RoleName::Ceo))
            ->assertStatus(403);

        self::assertSame(0, DB::table('suppliers')->count());
    }

    public function test_that_a_created_supplier_takes_section_7_1_defaults(): void
    {
        $body = $this->postJson(self::ENDPOINT, ['name' => 'Alpha Supply'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->json('data');

        self::assertIsArray($body);
        self::assertSame('white', $body['color_rating'], '§7.1: an unrated supplier is white.');
        self::assertTrue($body['is_active']);
        self::assertFalse($body['has_open_account']);
        self::assertNull($body['type']);
    }

    /** `AUD-01` names create among what must be recorded. */
    public function test_that_a_create_is_written_to_the_audit_log(): void
    {
        $id = $this->created(['name' => 'Alpha Supply', 'color_rating' => 'green']);

        $row = DB::table('audit_log')
            ->where('event', 'SUPPLIER_CREATED')
            ->where('entity_id', $id)
            ->first();

        self::assertNotNull($row, 'AUD-01 requires a record of the create.');
        self::assertSame('supplier', $row->entity_type);

        // `AuditRecorderInterface` documents old values as "absent on a create";
        // an empty array would read as "it used to be nothing".
        self::assertNull($row->old_values);

        $new = json_decode(self::jsonColumn($row->new_values), true);
        self::assertIsArray($new);
        self::assertSame('Alpha Supply', $new['name']);
        self::assertSame('green', $new['color_rating']);
    }

    // ──────────────────────────────────────────────── the boundary refusals

    public function test_that_a_supplier_without_a_name_is_refused(): void
    {
        $this->postJson(self::ENDPOINT, [], $this->bearerFor(RoleName::Manager))->assertStatus(422);
    }

    /** `required` accepts "   ", and the table's CHECK would answer with a 500. */
    public function test_that_a_blank_name_is_refused_at_the_boundary(): void
    {
        $this->postJson(self::ENDPOINT, ['name' => '   '], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422);
    }

    #[DataProvider('ratings')]
    public function test_that_every_colour_section_7_1_defines_is_accepted(string $rating): void
    {
        $this->postJson(self::ENDPOINT, ['name' => 'Alpha', 'color_rating' => $rating], $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->assertJsonPath('data.color_rating', $rating);
    }

    public function test_that_a_colour_section_7_1_does_not_define_is_refused(): void
    {
        $this->postJson(self::ENDPOINT, ['name' => 'Alpha', 'color_rating' => 'blue'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422);
    }

    public function test_that_a_type_section_7_1_does_not_name_is_refused(): void
    {
        $this->postJson(self::ENDPOINT, ['name' => 'Alpha', 'type' => 'wholesaler'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422);
    }

    /**
     * `D-85` (F-09 · 1.2): `D-31`'s flag belongs to the importer. A client that
     * sends it is refused rather than silently dropped, as `SaveCustomerRequest`
     * refuses the customers' flag — and nothing is written.
     */
    public function test_that_a_create_sending_is_incomplete_is_refused(): void
    {
        $this->postJson(self::ENDPOINT, ['name' => 'Alpha', 'is_incomplete' => false], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422);

        self::assertSame(0, DB::table('suppliers')->count(), 'A refused create wrote a supplier.');
    }

    public function test_that_an_edit_sending_is_incomplete_is_refused(): void
    {
        $id = $this->created(['name' => 'Alpha Supply']);

        $this->patchJson(
            self::ENDPOINT.'/'.$id,
            ['name' => 'Renamed', 'is_incomplete' => true],
            $this->bearerFor(RoleName::Manager),
        )->assertStatus(422);

        self::assertSame('Alpha Supply', DB::table('suppliers')->where('id', $id)->value('name'));
    }

    // ──────────────────────────────────────────────────────────── the update

    public function test_that_an_edit_changes_only_what_it_names(): void
    {
        $id = $this->created(['name' => 'Alpha Supply', 'phone' => '0100', 'contact_person' => 'Sara']);

        $this->patchJson(self::ENDPOINT.'/'.$id, ['phone' => '0111'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.phone', '0111')
            ->assertJsonPath('data.name', 'Alpha Supply')
            ->assertJsonPath('data.contact_person', 'Sara');
    }

    /** `D-19`: the colour is "set manually by any employee". */
    public function test_that_any_operational_role_may_set_the_colour(): void
    {
        $id = $this->created(['name' => 'Alpha Supply']);

        $this->patchJson(self::ENDPOINT.'/'.$id, ['color_rating' => 'red'], $this->bearerFor(RoleName::OutdoorSales))
            ->assertStatus(200)
            ->assertJsonPath('data.color_rating', 'red');
    }

    /** §3.7's "deactivate" is a field on the row, not an action suffix. */
    public function test_that_deactivation_is_an_edit_and_never_a_deletion(): void
    {
        $id = $this->created(['name' => 'Alpha Supply']);

        $this->patchJson(self::ENDPOINT.'/'.$id, ['is_active' => false], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false);

        $row = DB::table('suppliers')->where('id', $id)->first();

        self::assertNotNull($row, '§3.12 rule 3 forbids deleting a supplier.');
        self::assertNull($row->deleted_at, 'Deactivation must not touch DB-01\'s soft delete.');
    }

    public function test_that_an_edit_is_written_to_the_audit_log_with_both_values(): void
    {
        $id = $this->created(['name' => 'Alpha Supply', 'color_rating' => 'white']);

        $this->patchJson(self::ENDPOINT.'/'.$id, ['color_rating' => 'red'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);

        $row = DB::table('audit_log')
            ->where('event', 'SUPPLIER_UPDATED')
            ->where('entity_id', $id)
            ->first();

        self::assertNotNull($row, 'AUD-01 names update among what must be recorded.');

        // `AUD-02` wants the old value beside the new one, and limited to what
        // this write touched — not the whole row.
        $old = json_decode(self::jsonColumn($row->old_values), true);
        $new = json_decode(self::jsonColumn($row->new_values), true);

        self::assertIsArray($old);
        self::assertIsArray($new);
        self::assertSame('white', $old['color_rating']);
        self::assertSame('red', $new['color_rating']);
        self::assertArrayNotHasKey('name', $old, 'AUD-02: only the fields this write replaced.');
    }

    /** A PATCH naming no writable field changed nothing, and must not claim it did. */
    public function test_that_an_edit_changing_nothing_writes_no_audit_row(): void
    {
        $id = $this->created(['name' => 'Alpha Supply']);

        $this->patchJson(self::ENDPOINT.'/'.$id, [], $this->bearerFor(RoleName::Manager))->assertStatus(200);

        self::assertSame(
            0,
            DB::table('audit_log')->where('event', 'SUPPLIER_UPDATED')->where('entity_id', $id)->count(),
        );
    }

    public function test_that_the_read_only_ceo_cannot_edit(): void
    {
        $id = $this->created(['name' => 'Alpha Supply']);

        $this->patchJson(self::ENDPOINT.'/'.$id, ['name' => 'Renamed'], $this->bearerFor(RoleName::Ceo))
            ->assertStatus(403);

        self::assertSame('Alpha Supply', DB::table('suppliers')->where('id', $id)->value('name'));
    }

    public function test_that_editing_an_unknown_supplier_is_not_found(): void
    {
        $this->patchJson(self::ENDPOINT.'/'.Str::uuid7()->toString(), ['name' => 'X'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(404);
    }

    // ─────────────────────────────────────────────── §3.12 rule 3 — no delete

    public function test_that_no_delete_route_exists(): void
    {
        $id = $this->created(['name' => 'Alpha Supply']);

        $this->deleteJson(self::ENDPOINT.'/'.$id, [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(405);

        self::assertSame(1, DB::table('suppliers')->count());
    }

    // ───────────────────────────────────────────────────────────── helpers

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
