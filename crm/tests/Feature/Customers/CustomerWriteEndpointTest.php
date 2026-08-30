<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Modules\Admin\Domain\Settings\SystemLimit;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Module 3, Point 3.3 — `POST /customers` and `PATCH /customers/{id}`.
 *
 * ── What a scope means when there is no row yet ────────────────────────────
 *
 * §3.3's `create` row reads `All · All · Out · Own · Own · — · —`, and a scope
 * on a create cannot be a `WHERE`: the row does not exist. It is read here as
 * the owner the caller may file the record under — `all` means anyone, `own`
 * means themselves and nobody else. The same {@see CustomerRowScope} answers
 * both questions, so `team`, `out` and `asgn` fail closed on create exactly as
 * they do on read (owner's deferral, 2026-08-29).
 *
 * ── The owner is not editable through `PATCH` ──────────────────────────────
 *
 * §3.3 lists `assign` as its own permission and `OpenAPI §7.2` gives it its own
 * route, `PATCH /customers/{id}/assign`. A generic update that accepted
 * `sales_owner_id` would hand every `edit` holder a permission the matrix grants
 * to two roles. Transfer arrives with Point 3.5.
 *
 * ── D-35: a warning, never a block ─────────────────────────────────────────
 *
 * `OD-08` leaves the fuzzy-match threshold open — *"Empirical, tuned after the
 * first 100 customers"* — so the limit is declared and **unseeded** (owner's
 * decision, 2026-08-30). With no value set there is no warning at all, which is
 * tested rather than assumed, and the tests that exercise the warning set the
 * limit themselves.
 *
 * The probe is **row-scoped**, and that costs something real: a caller cannot be
 * warned about a customer they are not allowed to see. Leaking another owner's
 * customer name through a warning would be `SEC-08` undone by a convenience.
 */
final class CustomerWriteEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/customers';

    private const PASSWORD = 'Passw0rd123';

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
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

    private function customer(string $name, ?string $ownerId = null): string
    {
        $id = (string) Str::uuid7();

        DB::table('customers')->insert([
            'id' => $id,
            'name' => $name,
            'customer_status' => 'prospect',
            'sales_owner_id' => $ownerId,
            'is_archived' => false,
            'is_incomplete' => false,
            'created_by' => $ownerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** `OD-08` has no value, so the warning is off until a test turns it on. */
    private function thresholdOf(string $value): void
    {
        DB::table('system_limits')->insert([
            'id' => (string) Str::uuid7(),
            'key' => SystemLimit::CustomerSimilarityThreshold->value,
            'value' => $value,
            'value_type' => 'decimal',
            'unit' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ─────────────────────────────── POST: the contract

    public function test_that_an_unauthenticated_caller_cannot_create(): void
    {
        $this->postJson(self::ENDPOINT, ['name' => 'Alpha'])->assertStatus(401);
    }

    public function test_that_a_created_customer_returns_201_and_the_single_envelope(): void
    {
        $body = $this->postJson(self::ENDPOINT, [
            'name' => 'Alpha Trading',
            'sector' => 'Commercial',
            'contact_person' => 'Sara',
            'email' => 'sara@alpha.test',
        ], $this->bearerFor(RoleName::Manager))->assertStatus(201)->json();

        self::assertIsArray($body);
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('meta', $body);

        $data = $body['data'];
        self::assertIsArray($data);
        self::assertSame('Alpha Trading', $data['name']);
        self::assertSame('Commercial', $data['sector']);

        self::assertIsString($data['id']);
        self::assertDatabaseHas('customers', ['id' => $data['id'], 'name' => 'Alpha Trading']);
    }

    public function test_that_a_name_is_required(): void
    {
        $this->postJson(self::ENDPOINT, ['sector' => 'Commercial'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    /** The table carries `CHECK (btrim(name) <> '')`; a constraint violation is a 500, so the boundary refuses first. */
    public function test_that_a_blank_name_is_refused_at_the_boundary(): void
    {
        $this->postJson(self::ENDPOINT, ['name' => '   '], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    /** §4.5 and `D-49`: the status is derived, never sent. */
    public function test_that_the_caller_cannot_set_the_customer_status(): void
    {
        $this->postJson(self::ENDPOINT, [
            'name' => 'Alpha Trading',
            'customer_status' => 'customer',
        ], $this->bearerFor(RoleName::Manager))->assertStatus(422);
    }

    public function test_that_a_created_customer_defaults_to_prospect(): void
    {
        $id = $this->postJson(self::ENDPOINT, ['name' => 'Alpha Trading'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->json('data.id');

        self::assertIsString($id);
        self::assertDatabaseHas('customers', ['id' => $id, 'customer_status' => 'prospect']);
    }

    // ─────────────────────────────── POST: §3.3's create scope

    public function test_that_procurement_may_not_create_a_customer(): void
    {
        $this->postJson(self::ENDPOINT, ['name' => 'Alpha'], $this->bearerFor(RoleName::Procurement))
            ->assertStatus(403);
    }

    public function test_that_the_ceo_may_not_create_a_customer(): void
    {
        $this->postJson(self::ENDPOINT, ['name' => 'Alpha'], $this->bearerFor(RoleName::Ceo))
            ->assertStatus(403);
    }

    public function test_that_indoor_sales_becomes_the_owner_of_what_they_create(): void
    {
        $id = $this->postJson(self::ENDPOINT, ['name' => 'Mine'], $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(201)
            ->json('data.id');

        self::assertIsString($id);
        self::assertDatabaseHas('customers', [
            'id' => $id,
            'sales_owner_id' => $this->userWith(RoleName::IndoorSales)->id,
        ]);
    }

    public function test_that_indoor_sales_cannot_file_a_customer_under_somebody_else(): void
    {
        $this->postJson(self::ENDPOINT, [
            'name' => 'Not Mine',
            'sales_owner_id' => $this->userWith(RoleName::OutdoorSales)->id,
        ], $this->bearerFor(RoleName::IndoorSales))->assertStatus(403);

        $this->assertDatabaseMissing('customers', ['name' => 'Not Mine']);
    }

    public function test_that_a_manager_may_file_a_customer_under_anybody(): void
    {
        $owner = $this->userWith(RoleName::IndoorSales)->id;

        $id = $this->postJson(self::ENDPOINT, [
            'name' => 'Theirs',
            'sales_owner_id' => $owner,
        ], $this->bearerFor(RoleName::Manager))->assertStatus(201)->json('data.id');

        self::assertIsString($id);
        self::assertDatabaseHas('customers', ['id' => $id, 'sales_owner_id' => $owner]);
    }

    /** §3.3 gives the Team Leader `All` on create, unlike the `Team` it has on view and edit. */
    public function test_that_a_team_leader_may_file_a_customer_under_anybody(): void
    {
        $owner = $this->userWith(RoleName::IndoorSales)->id;

        $this->postJson(self::ENDPOINT, [
            'name' => 'Team Leaders Entry',
            'sales_owner_id' => $owner,
        ], $this->bearerFor(RoleName::TeamLeader))->assertStatus(201);
    }

    /** The visible cost of the owner's deferral: `out` has no mechanism, so it permits nothing. */
    public function test_that_an_outdoor_supervisor_cannot_create_while_out_is_unbacked(): void
    {
        $this->postJson(self::ENDPOINT, ['name' => 'Alpha'], $this->bearerFor(RoleName::OutdoorSupervisor))
            ->assertStatus(403);
    }

    // ─────────────────────────────── PATCH

    public function test_that_a_manager_updates_a_customer(): void
    {
        $id = $this->customer('Alpha Trading');

        $this->patchJson(self::ENDPOINT.'/'.$id, ['region' => 'Riyadh'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.region', 'Riyadh');

        $this->assertDatabaseHas('customers', ['id' => $id, 'region' => 'Riyadh']);
    }

    public function test_that_a_row_outside_the_callers_scope_is_404_on_update(): void
    {
        $id = $this->customer('Somebody Elses', $this->userWith(RoleName::OutdoorSales)->id);

        $this->patchJson(self::ENDPOINT.'/'.$id, ['region' => 'Riyadh'], $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');

        $this->assertDatabaseMissing('customers', ['id' => $id, 'region' => 'Riyadh']);
    }

    public function test_that_an_unknown_id_is_404_on_update(): void
    {
        $this->patchJson(self::ENDPOINT.'/'.Str::uuid7(), ['region' => 'Riyadh'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(404);
    }

    /** §3.3 makes `assign` its own permission; `OpenAPI §7.2` gives it its own route. Point 3.5. */
    public function test_that_the_owner_cannot_be_transferred_through_a_generic_update(): void
    {
        $id = $this->customer('Alpha Trading', $this->userWith(RoleName::IndoorSales)->id);

        $this->patchJson(self::ENDPOINT.'/'.$id, [
            'sales_owner_id' => $this->userWith(RoleName::OutdoorSales)->id,
        ], $this->bearerFor(RoleName::Manager))->assertStatus(422);

        $this->assertDatabaseHas('customers', [
            'id' => $id,
            'sales_owner_id' => $this->userWith(RoleName::IndoorSales)->id,
        ]);
    }

    public function test_that_the_customer_status_cannot_be_edited(): void
    {
        $id = $this->customer('Alpha Trading');

        $this->patchJson(self::ENDPOINT.'/'.$id, ['customer_status' => 'customer'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422);

        $this->assertDatabaseHas('customers', ['id' => $id, 'customer_status' => 'prospect']);
    }

    public function test_that_procurement_may_not_edit_a_customer(): void
    {
        $id = $this->customer('Alpha Trading');

        $this->patchJson(self::ENDPOINT.'/'.$id, ['region' => 'Riyadh'], $this->bearerFor(RoleName::Procurement))
            ->assertStatus(403);
    }

    // ─────────────────────────────── D-35 · §10.2 duplicate warning

    /**
     * The **seeded** threshold, not an injected one — the difference between
     * "the mechanism works" and "the feature is on in a real deployment".
     *
     * Every other test in this section supplies its own number through
     * `thresholdOf()`, which proves the probe and would keep passing if the
     * seeder shipped nothing at all. That is exactly the state Module 3 was in
     * until 2026-08-30: built, tested, and never once able to fire.
     *
     * The two pairs are the measured extremes either side of `0.60`:
     * the `D-35` hamza/taa pair scores `1.00`, and two different firms sharing
     * a `مؤسسة` prefix score `0.50`. Asserting both halves is the point — a
     * threshold of `0` warns on everything and would pass the first assertion
     * alone.
     */
    public function test_that_the_seeded_threshold_warns_on_a_duplicate_and_stays_silent_otherwise(): void
    {
        $this->seed(SystemSettingsSeeder::class);
        $this->customer('أحمد للتجارة');
        $this->customer('مؤسسة الأمل');

        $warned = $this->postJson(self::ENDPOINT, ['name' => 'احمد للتجاره'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->json('meta');

        self::assertIsArray($warned);
        self::assertArrayHasKey('similar_customers', $warned, 'D-35 must fire on the documented §10.2 pair.');

        $silent = $this->postJson(self::ENDPOINT, ['name' => 'مؤسسة النور'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->json('meta');

        self::assertIsArray($silent);
        self::assertArrayNotHasKey('similar_customers', $silent, 'Two different firms sharing a prefix are not duplicates.');
    }

    public function test_that_no_warning_is_returned_while_the_threshold_is_unset(): void
    {
        $this->customer('أحمد للتجارة');

        $body = $this->postJson(self::ENDPOINT, ['name' => 'احمد للتجاره'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->json('meta');

        self::assertIsArray($body);
        self::assertArrayNotHasKey('similar_customers', $body);
    }

    /** §10.2's normalisation of hamza, taa marbuta and yaa, measured end to end. */
    public function test_that_a_similar_arabic_name_warns_and_does_not_block(): void
    {
        $this->thresholdOf('0.40');
        $existing = $this->customer('أحمد للتجارة');

        $body = $this->postJson(self::ENDPOINT, ['name' => 'احمد للتجاره'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->json();

        self::assertIsArray($body);
        $meta = $body['meta'];
        self::assertIsArray($meta);

        $similar = $meta['similar_customers'];
        self::assertIsArray($similar);
        self::assertCount(1, $similar);

        $first = $similar[0];
        self::assertIsArray($first);
        self::assertSame($existing, $first['id']);
        self::assertSame('أحمد للتجارة', $first['name']);

        // D-35: the employee decides. The record is written either way.
        self::assertDatabaseHas('customers', ['name' => 'احمد للتجاره']);
    }

    public function test_that_an_unrelated_name_does_not_warn(): void
    {
        $this->thresholdOf('0.40');
        $this->customer('أحمد للتجارة');

        $meta = $this->postJson(self::ENDPOINT, ['name' => 'مؤسسة الوفاء'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->json('meta');

        self::assertIsArray($meta);
        self::assertArrayNotHasKey('similar_customers', $meta);
    }

    /** `SEC-08` outranks the convenience: a warning may not name a row the caller cannot read. */
    public function test_that_the_warning_never_names_a_row_outside_the_callers_scope(): void
    {
        $this->thresholdOf('0.40');
        $this->customer('أحمد للتجارة', $this->userWith(RoleName::OutdoorSales)->id);

        $meta = $this->postJson(self::ENDPOINT, ['name' => 'احمد للتجاره'], $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(201)
            ->json('meta');

        self::assertIsArray($meta);
        self::assertArrayNotHasKey('similar_customers', $meta);
    }

    public function test_that_the_warning_also_applies_on_update(): void
    {
        $this->thresholdOf('0.40');
        $existing = $this->customer('أحمد للتجارة');
        $edited = $this->customer('شركة مختلفة تماما');

        $meta = $this->patchJson(self::ENDPOINT.'/'.$edited, ['name' => 'احمد للتجاره'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->json('meta');

        self::assertIsArray($meta);
        $similar = $meta['similar_customers'];
        self::assertIsArray($similar);
        self::assertCount(1, $similar);

        $first = $similar[0];
        self::assertIsArray($first);
        self::assertSame($existing, $first['id']);
    }

    /** A record is never its own duplicate. */
    public function test_that_an_update_does_not_warn_about_the_row_being_edited(): void
    {
        $this->thresholdOf('0.40');
        $id = $this->customer('أحمد للتجارة');

        $meta = $this->patchJson(self::ENDPOINT.'/'.$id, ['region' => 'Riyadh'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->json('meta');

        self::assertIsArray($meta);
        self::assertArrayNotHasKey('similar_customers', $meta);
    }

    // ─────────────────────────────── AUD-01

    public function test_that_creating_a_customer_writes_an_audit_entry(): void
    {
        $id = $this->postJson(self::ENDPOINT, ['name' => 'Alpha Trading'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(201)
            ->json('data.id');

        self::assertIsString($id);
        $this->assertDatabaseHas('audit_log', [
            'event' => 'CUSTOMER_CREATED',
            'entity_type' => 'customer',
            'entity_id' => $id,
        ]);
    }

    public function test_that_updating_a_customer_writes_an_audit_entry_with_the_old_and_new_value(): void
    {
        $id = $this->customer('Alpha Trading');

        $this->patchJson(self::ENDPOINT.'/'.$id, ['region' => 'Riyadh'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);

        $row = DB::table('audit_log')
            ->where('event', 'CUSTOMER_UPDATED')
            ->where('entity_id', $id)
            ->first();

        // `first()` already types as stdClass|null, so assertNotNull is the
        // whole narrowing — a second assertIsObject is an assertion PHPStan
        // proves can never fail.
        self::assertNotNull($row);

        $new = $row->new_values;
        self::assertIsString($new);
        self::assertStringContainsString('Riyadh', $new);
    }
}
