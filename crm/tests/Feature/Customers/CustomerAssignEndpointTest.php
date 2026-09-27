<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

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
 * Module 3, Point 3.5 — Flow 10's reassignment.
 *
 * ── `assign` is its own permission ─────────────────────────────────────────
 *
 * §3.3 grants it `All · Team · — · — · — · — · —`, where `edit` reaches five
 * roles. `PermissionMatrix` already carries `customer.assign`, and
 * `OpenAPI §7.2` gives the suffix verbatim, so the route checks that ability
 * and never `customer.edit`.
 *
 * ── "The customer and their full history move" ─────────────────────────────
 *
 * In Module 3 the history *is* the customer row and the audit trail keyed to
 * its id, and neither is copied anywhere: the id does not change, so everything
 * hanging off it follows the owner change by construction. Deals, quotations
 * and visits belong to later modules and cannot be tested here.
 *
 * ── No notification, by the owner's ruling of 2026-08-30 ───────────────────
 *
 * Flow 10 says "both employees notified". §18.1 limits the MVP to badge
 * counters — Requests · Approvals · Reports · My Quotations, with customers not
 * among them — and §18.2's email list is closed at MAIL-01…MAIL-05, none of
 * which is a reassignment. §18.3 puts the notification centre post-MVP. The
 * narrowing is recorded in `CHECKLIST.md` awaiting a `D-xx`; nothing here
 * asserts a notification, and `test_that_assigning_notifies_nobody` pins that
 * as a decision rather than an omission.
 *
 * ── Idempotent, and silent when nothing changed ────────────────────────────
 *
 * Point 3.4's rule, for Point 3.4's reason: `AUD-03` keeps entries permanently
 * and immutably, so a row describing a change that did not happen is a
 * permanent false record.
 */
final class CustomerAssignEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/customers';

    /** F-19 · 1.2 (`D-92`): several customers, one owner, one request. */
    private const BULK = self::ENDPOINT.'/assign';

    private const PASSWORD = 'Passw0rd123';

    private const EVENT = 'CUSTOMER_REASSIGNED';

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function userWith(RoleName $role, string $suffix = ''): User
    {
        $key = $role->value.$suffix;

        if (isset($this->users[$key])) {
            return $this->users[$key];
        }

        $row = Role::query()->where('slug', $role->value)->firstOrFail();

        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label().$suffix,
            'email' => str_replace('_', '.', $role->value).$suffix.'@example.test',
            'password' => self::PASSWORD,
            'role_id' => $row->id,
            'is_active' => true,
            'is_hidden' => $role->isHidden(),
        ]);
        $user->save();

        return $this->users[$key] = $user;
    }

    /** @return array<string, string> */
    private function bearerFor(RoleName $role, string $suffix = ''): array
    {
        $user = $this->userWith($role, $suffix);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(201)->json('data.token');

        self::assertIsString($token);

        return ['Authorization' => 'Bearer '.$token];
    }

    private function customer(string $name, ?string $ownerId = null, bool $archived = false): string
    {
        $id = (string) Str::uuid7();

        DB::table('customers')->insert([
            'id' => $id,
            'name' => $name,
            'customer_status' => 'prospect',
            'sales_owner_id' => $ownerId,
            'is_archived' => $archived,
            'is_incomplete' => false,
            'created_by' => $ownerId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function assignUrl(string $id): string
    {
        return self::ENDPOINT.'/'.$id.'/assign';
    }

    private function auditCount(string $event, string $id): int
    {
        return DB::table('audit_log')->where('event', $event)->where('entity_id', $id)->count();
    }

    // ─────────────────────────────── §3.3's assign row

    public function test_that_an_unauthenticated_caller_cannot_assign(): void
    {
        $this->patchJson($this->assignUrl((string) Str::uuid7()))->assertStatus(401);
    }

    /** @return array<string, array{RoleName}> */
    public static function rolesWithoutAssign(): array
    {
        return [
            'indoor sales' => [RoleName::IndoorSales],
            'outdoor sales' => [RoleName::OutdoorSales],
            'outdoor supervisor' => [RoleName::OutdoorSupervisor],
            'procurement' => [RoleName::Procurement],
            'the CEO' => [RoleName::Ceo],
        ];
    }

    /**
     * §3.12 rule 1 — the refusal is at the API, not in a hidden button. The CEO
     * is the sharp case: `All` on `view` and a dash on `assign`.
     */
    #[DataProvider('rolesWithoutAssign')]
    public function test_that_a_role_without_the_permission_cannot_assign(RoleName $role): void
    {
        $owner = $this->userWith($role);
        $id = $this->customer('Alpha Trading', ownerId: $owner->id);
        $target = $this->userWith(RoleName::Manager);

        $this->patchJson($this->assignUrl($id), ['sales_owner_id' => $target->id], $this->bearerFor($role))
            ->assertStatus(403);

        $this->assertDatabaseHas('customers', ['id' => $id, 'sales_owner_id' => $owner->id]);
    }

    /** ⚠️ The visible cost of the unbacked `team` scope, not a decision about Flow 10. */
    public function test_that_a_team_leader_can_assign_nothing_while_team_is_unbacked(): void
    {
        $id = $this->customer('Alpha Trading');
        $target = $this->userWith(RoleName::IndoorSales);

        $this->patchJson($this->assignUrl($id), ['sales_owner_id' => $target->id], $this->bearerFor(RoleName::TeamLeader))
            ->assertStatus(404);

        $this->assertDatabaseHas('customers', ['id' => $id, 'sales_owner_id' => null]);
    }

    // ─────────────────────────────── the happy path

    public function test_that_a_manager_assigns_a_customer_to_another_employee(): void
    {
        $from = $this->userWith(RoleName::IndoorSales);
        $to = $this->userWith(RoleName::OutdoorSales);
        $id = $this->customer('Alpha Trading', ownerId: $from->id);

        $this->patchJson($this->assignUrl($id), ['sales_owner_id' => $to->id], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.sales_owner_id', $to->id);

        $this->assertDatabaseHas('customers', ['id' => $id, 'sales_owner_id' => $to->id]);
    }

    /** F-19 · 1.1a: the response names the new owner, so the page shows it without reading the record again. */
    public function test_that_the_answer_names_the_new_owner(): void
    {
        $from = $this->userWith(RoleName::IndoorSales);
        $to = $this->userWith(RoleName::OutdoorSales);
        $id = $this->customer('Alpha Trading', ownerId: $from->id);

        $this->patchJson($this->assignUrl($id), ['sales_owner_id' => $to->id], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.sales_owner_name', 'Test Outdoor Sales');
    }

    public function test_that_assigning_records_the_actor_on_the_row(): void
    {
        $to = $this->userWith(RoleName::IndoorSales);
        $id = $this->customer('Alpha Trading');
        $manager = $this->userWith(RoleName::Manager);

        $this->patchJson($this->assignUrl($id), ['sales_owner_id' => $to->id], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);

        // `DB-02` — the actor is the caller, never the new owner.
        $this->assertDatabaseHas('customers', ['id' => $id, 'updated_by' => $manager->id]);
    }

    /** An unowned customer is the ordinary case after `D-34`'s deactivation. */
    public function test_that_a_customer_with_no_owner_can_be_assigned_one(): void
    {
        $to = $this->userWith(RoleName::IndoorSales);
        $id = $this->customer('Alpha Trading');

        $this->patchJson($this->assignUrl($id), ['sales_owner_id' => $to->id], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.sales_owner_id', $to->id);
    }

    /** Flow 10 read literally: the customer *moves* — out of one reach and into the other. */
    public function test_that_the_customer_moves_between_the_two_employees_own_lists(): void
    {
        $from = $this->userWith(RoleName::IndoorSales, '.from');
        $to = $this->userWith(RoleName::IndoorSales, '.to');
        $id = $this->customer('Alpha Trading', ownerId: $from->id);

        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::IndoorSales, '.from'))
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $id);

        $this->patchJson($this->assignUrl($id), ['sales_owner_id' => $to->id], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);

        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::IndoorSales, '.from'))
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->getJson(self::ENDPOINT, $this->bearerFor(RoleName::IndoorSales, '.to'))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id);
    }

    /**
     * "…and their full history move". The id does not change, so the trail
     * keyed to it is still the same customer's after the transfer.
     */
    public function test_that_the_customers_earlier_audit_trail_stays_with_them(): void
    {
        $to = $this->userWith(RoleName::IndoorSales);
        $id = $this->customer('Alpha Trading', archived: true);

        $this->patchJson(self::ENDPOINT.'/'.$id.'/restore', [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);
        $this->patchJson($this->assignUrl($id), ['sales_owner_id' => $to->id], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);

        self::assertSame(1, $this->auditCount('ARCHIVE_RESTORED', $id));
        self::assertSame(1, $this->auditCount(self::EVENT, $id));
    }

    /** No source forbids it, and §10.1 puts archived-and-orphaned work in a Team Leader's hands. */
    public function test_that_an_archived_customer_can_still_be_assigned(): void
    {
        $to = $this->userWith(RoleName::IndoorSales);
        $id = $this->customer('Alpha Trading', archived: true);

        $this->patchJson($this->assignUrl($id), ['sales_owner_id' => $to->id], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.is_archived', true)
            ->assertJsonPath('data.sales_owner_id', $to->id);
    }

    // ─────────────────────────────── §3.12 rule 4's mandatory entry

    public function test_that_assigning_writes_the_mandatory_reassignment_entry(): void
    {
        $from = $this->userWith(RoleName::IndoorSales);
        $to = $this->userWith(RoleName::OutdoorSales);
        $id = $this->customer('Alpha Trading', ownerId: $from->id);

        $this->patchJson($this->assignUrl($id), ['sales_owner_id' => $to->id], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);

        /** @var object{old_values: string|null, new_values: string|null, user_id: string|null}|null $row */
        $row = DB::table('audit_log')->where('event', self::EVENT)->where('entity_id', $id)->first();

        self::assertNotNull($row);
        // `AUD-02` — the old value beside the new one, both as the write saw them.
        self::assertSame(['sales_owner_id' => $from->id], json_decode((string) $row->old_values, true));
        self::assertSame(['sales_owner_id' => $to->id], json_decode((string) $row->new_values, true));
        self::assertSame($this->userWith(RoleName::Manager)->id, $row->user_id);
    }

    public function test_that_assigning_to_the_current_owner_changes_nothing_and_records_nothing(): void
    {
        $owner = $this->userWith(RoleName::IndoorSales);
        $id = $this->customer('Alpha Trading', ownerId: $owner->id);

        $this->patchJson($this->assignUrl($id), ['sales_owner_id' => $owner->id], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.sales_owner_id', $owner->id);

        self::assertSame(0, $this->auditCount(self::EVENT, $id));
        $this->assertDatabaseHas('customers', ['id' => $id, 'updated_by' => null]);
    }

    /** The owner's ruling of 2026-08-30, pinned so it reads as a decision. */
    public function test_that_assigning_notifies_nobody(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $to = $this->userWith(RoleName::IndoorSales);
        $id = $this->customer('Alpha Trading');

        $this->patchJson($this->assignUrl($id), ['sales_owner_id' => $to->id], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);

        \Illuminate\Support\Facades\Notification::assertNothingSent();
    }

    // ─────────────────────────────── the refusals

    public function test_that_an_unknown_customer_is_404(): void
    {
        $to = $this->userWith(RoleName::IndoorSales);

        $this->patchJson($this->assignUrl((string) Str::uuid7()), ['sales_owner_id' => $to->id], $this->bearerFor(RoleName::Manager))
            ->assertStatus(404);
    }

    public function test_that_a_missing_owner_is_refused(): void
    {
        $id = $this->customer('Alpha Trading');

        $this->patchJson($this->assignUrl($id), [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_that_a_null_owner_is_refused(): void
    {
        $owner = $this->userWith(RoleName::IndoorSales);
        $id = $this->customer('Alpha Trading', ownerId: $owner->id);

        // §3.3 has no "unassign" action. Clearing the owner is `D-34`'s
        // deactivation path, not this route.
        $this->patchJson($this->assignUrl($id), ['sales_owner_id' => null], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422);

        $this->assertDatabaseHas('customers', ['id' => $id, 'sales_owner_id' => $owner->id]);
    }

    public function test_that_a_malformed_owner_id_is_refused(): void
    {
        $id = $this->customer('Alpha Trading');

        $this->patchJson($this->assignUrl($id), ['sales_owner_id' => 'not-a-uuid'], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422);
    }

    /** Asked of Identity's contract, never `exists:users,id` — `CLAUDE.md` forbids the cross-module read. */
    public function test_that_an_owner_who_is_not_a_user_is_refused(): void
    {
        $id = $this->customer('Alpha Trading');

        $this->patchJson($this->assignUrl($id), ['sales_owner_id' => (string) Str::uuid7()], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'sales_owner_id');

        $this->assertDatabaseHas('customers', ['id' => $id, 'sales_owner_id' => null]);
    }

    /** §3.12 rule 6 — the hidden Super Admin is not a name this route can file work under. */
    public function test_that_the_hidden_super_admin_cannot_be_made_an_owner(): void
    {
        $id = $this->customer('Alpha Trading');
        $superAdmin = $this->userWith(RoleName::SuperAdmin);

        $this->patchJson($this->assignUrl($id), ['sales_owner_id' => $superAdmin->id], $this->bearerFor(RoleName::Manager))
            ->assertStatus(422);

        $this->assertDatabaseHas('customers', ['id' => $id, 'sales_owner_id' => null]);
    }

    // ─────────────────────────────── F-19 · 1.2: several customers in one request (`D-92`)

    public function test_that_an_unauthenticated_caller_cannot_bulk_assign(): void
    {
        $this->postJson(self::BULK)->assertStatus(401);
    }

    /** §3.12 rule 1: the same five roles refused, at the API. */
    #[DataProvider('rolesWithoutAssign')]
    public function test_that_a_role_without_the_permission_cannot_bulk_assign(RoleName $role): void
    {
        $owner = $this->userWith($role);
        $id = $this->customer('Alpha Trading', ownerId: $owner->id);
        $target = $this->userWith(RoleName::Manager);

        $this->postJson(self::BULK, ['ids' => [$id], 'sales_owner_id' => $target->id], $this->bearerFor($role))
            ->assertStatus(403);

        $this->assertDatabaseHas('customers', ['id' => $id, 'sales_owner_id' => $owner->id]);
    }

    /** `D-92`: `customer.assign` at its scope for every customer, so the unbacked `team` reaches none of them. */
    public function test_that_a_team_leader_bulk_assigns_nothing_while_team_is_unbacked(): void
    {
        $a = $this->customer('Alpha Trading');
        $b = $this->customer('Beta Supplies');
        $target = $this->userWith(RoleName::IndoorSales);

        // The customer's own refusal, not an unmatched route's: both are `404 resource_not_found`.
        $this->postJson(self::BULK, ['ids' => [$a, $b], 'sales_owner_id' => $target->id], $this->bearerFor(RoleName::TeamLeader))
            ->assertStatus(404)
            ->assertJsonPath('error.message', (string) __('customers.not_found'));

        $this->assertDatabaseHas('customers', ['id' => $a, 'sales_owner_id' => null]);
        $this->assertDatabaseHas('customers', ['id' => $b, 'sales_owner_id' => null]);
    }

    /** `D-92` and `OpenAPI §7.3`: one owner for the list, and a result per record, in the order asked. */
    public function test_that_a_manager_assigns_several_customers_in_one_request(): void
    {
        $to = $this->userWith(RoleName::OutdoorSales);
        $a = $this->customer('Alpha Trading', ownerId: $this->userWith(RoleName::IndoorSales)->id);
        $b = $this->customer('Beta Supplies');

        $this->postJson(self::BULK, ['ids' => [$a, $b], 'sales_owner_id' => $to->id], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.id', $a)
            ->assertJsonPath('data.items.0.sales_owner_id', $to->id)
            ->assertJsonPath('data.items.1.id', $b)
            ->assertJsonPath('data.items.1.sales_owner_id', $to->id);

        $this->assertDatabaseHas('customers', ['id' => $a, 'sales_owner_id' => $to->id]);
        $this->assertDatabaseHas('customers', ['id' => $b, 'sales_owner_id' => $to->id]);
    }

    /** §3.12 rule 4 per customer: each one that moved has its own entry; one already theirs has none (`AUD-03`). */
    public function test_that_each_moved_customer_gets_its_own_reassignment_entry(): void
    {
        $to = $this->userWith(RoleName::OutdoorSales);
        $a = $this->customer('Alpha Trading', ownerId: $this->userWith(RoleName::IndoorSales)->id);
        $b = $this->customer('Beta Supplies');
        $already = $this->customer('Gamma Tools', ownerId: $to->id);

        $this->postJson(self::BULK, ['ids' => [$a, $b, $already], 'sales_owner_id' => $to->id], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200);

        self::assertSame(1, $this->auditCount(self::EVENT, $a));
        self::assertSame(1, $this->auditCount(self::EVENT, $b));
        self::assertSame(0, $this->auditCount(self::EVENT, $already));
    }

    /**
     * `D-92`'s "all or none". The first customer is written before the second
     * refuses, so only the outer transaction takes it back, with its entry.
     * The refusal names no customer (§5.1; the owner's ruling of 2026-09-27).
     */
    public function test_that_one_customer_out_of_reach_refuses_the_whole_request(): void
    {
        $from = $this->userWith(RoleName::IndoorSales);
        $to = $this->userWith(RoleName::OutdoorSales);
        $a = $this->customer('Alpha Trading', ownerId: $from->id);
        $missing = (string) Str::uuid7();

        $response = $this->postJson(self::BULK, ['ids' => [$a, $missing], 'sales_owner_id' => $to->id], $this->bearerFor(RoleName::Manager))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found')
            ->assertJsonPath('error.message', (string) __('customers.not_found'));

        self::assertStringNotContainsString($missing, (string) $response->getContent());
        $this->assertDatabaseHas('customers', ['id' => $a, 'sales_owner_id' => $from->id]);
        self::assertSame(0, $this->auditCount(self::EVENT, $a));
    }

    /** @return array<string, array{\Closure(string, string, string): array<string, mixed>}> */
    public static function malformedBulkBodies(): array
    {
        return [
            'no ids' => [static fn (string $a, string $owner, string $admin): array => ['sales_owner_id' => $owner]],
            'an empty list' => [static fn (string $a, string $owner, string $admin): array => ['ids' => [], 'sales_owner_id' => $owner]],
            'ids that are not a list' => [static fn (string $a, string $owner, string $admin): array => ['ids' => $a, 'sales_owner_id' => $owner]],
            // `OpenAPI §7.3`'s "bounded identifier list", at §6.1's page of 100.
            'more than a page (101)' => [static fn (string $a, string $owner, string $admin): array => [
                'ids' => [$a, ...array_map(static fn (): string => (string) Str::uuid7(), range(1, 100))],
                'sales_owner_id' => $owner,
            ]],
            'the same customer twice' => [static fn (string $a, string $owner, string $admin): array => ['ids' => [$a, $a], 'sales_owner_id' => $owner]],
            'an id that is not a UUID' => [static fn (string $a, string $owner, string $admin): array => ['ids' => [$a, 'not-a-uuid'], 'sales_owner_id' => $owner]],
            'no owner' => [static fn (string $a, string $owner, string $admin): array => ['ids' => [$a]]],
            'an owner who is not a user' => [static fn (string $a, string $owner, string $admin): array => ['ids' => [$a], 'sales_owner_id' => (string) Str::uuid7()]],
            'the hidden Super Admin as owner' => [static fn (string $a, string $owner, string $admin): array => ['ids' => [$a], 'sales_owner_id' => $admin]],
        ];
    }

    /** @param \Closure(string, string, string): array<string, mixed> $body */
    #[DataProvider('malformedBulkBodies')]
    public function test_that_a_malformed_bulk_request_is_refused_and_writes_nothing(\Closure $body): void
    {
        $from = $this->userWith(RoleName::IndoorSales);
        $a = $this->customer('Alpha Trading', ownerId: $from->id);
        $owner = $this->userWith(RoleName::OutdoorSales)->id;
        $admin = $this->userWith(RoleName::SuperAdmin)->id;

        $this->postJson(self::BULK, $body($a, $owner, $admin), $this->bearerFor(RoleName::Manager))
            ->assertStatus(422);

        $this->assertDatabaseHas('customers', ['id' => $a, 'sales_owner_id' => $from->id]);
        self::assertSame(0, $this->auditCount(self::EVENT, $a));
    }
}
