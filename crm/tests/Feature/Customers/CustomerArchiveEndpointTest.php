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
 * Module 3, Point 3.4 — Flow 7's archive and restore.
 *
 * ── One permission for two routes ──────────────────────────────────────────
 *
 * §3.3 merges the pair into a single row — `archive / restore`, granted
 * `All · Team · — · — · — · — · —` — and `PermissionMatrix` carries exactly one
 * `customer.archive` permission with no `customer.restore` beside it. Both
 * routes therefore check the same ability; inventing a second permission would
 * be a matrix row no document contains.
 *
 * ── ⚠️ A Team Leader can archive nothing today ─────────────────────────────
 *
 * Flow 7 says "Manager / TL only" and §3.3 gives the Team Leader `Team`, which
 * has no mechanism (owner's deferral, 2026-08-29). `CustomerRowScope` fails it
 * closed, so half of Flow 7's authority is unreachable and the criterion is
 * only half met. Tested, so the cost is visible rather than surprising.
 *
 * ── Both actions are idempotent ────────────────────────────────────────────
 *
 * `OpenAPI §7.2` wants each action's "accepted current state" documented and no
 * source names one, so archiving an archived customer succeeds and changes
 * nothing. It also writes **no audit row**: `AUD-03` keeps audit entries
 * permanently and immutably, and a row recording a change that did not happen
 * is a permanent false record. That matters most for Flow 7's select-all
 * restore, where "some of these are already active" is the normal case.
 */
final class CustomerArchiveEndpointTest extends TestCase
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

    private function customer(string $name, bool $archived = false, ?string $ownerId = null): string
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

    private function archiveUrl(string $id): string
    {
        return self::ENDPOINT.'/'.$id.'/archive';
    }

    private function restoreUrl(string $id): string
    {
        return self::ENDPOINT.'/'.$id.'/restore';
    }

    private function auditCount(string $event, string $id): int
    {
        return DB::table('audit_log')->where('event', $event)->where('entity_id', $id)->count();
    }

    // ─────────────────────────────── §3.3's archive / restore row

    public function test_that_an_unauthenticated_caller_cannot_archive(): void
    {
        $this->patchJson($this->archiveUrl((string) Str::uuid7()))->assertStatus(401);
    }

    public function test_that_an_unauthenticated_caller_cannot_restore(): void
    {
        $this->patchJson($this->restoreUrl((string) Str::uuid7()))->assertStatus(401);
    }

    /** @return array<string, array{RoleName}> */
    public static function rolesWithoutArchive(): array
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
     * §3.12 rule 1 — the refusal is at the API, not in a hidden button.
     */
    #[DataProvider('rolesWithoutArchive')]
    public function test_that_a_role_without_the_permission_cannot_archive(RoleName $role): void
    {
        $id = $this->customer('Alpha Trading', ownerId: $this->userWith($role)->id);

        $this->patchJson($this->archiveUrl($id), [], $this->bearerFor($role))->assertStatus(403);

        $this->assertDatabaseHas('customers', ['id' => $id, 'is_archived' => false]);
    }

    #[DataProvider('rolesWithoutArchive')]
    public function test_that_a_role_without_the_permission_cannot_restore(RoleName $role): void
    {
        $id = $this->customer('Alpha Trading', archived: true, ownerId: $this->userWith($role)->id);

        $this->patchJson($this->restoreUrl($id), [], $this->bearerFor($role))->assertStatus(403);

        $this->assertDatabaseHas('customers', ['id' => $id, 'is_archived' => true]);
    }

    /** ⚠️ The visible cost of the unbacked `team` scope, not a decision about Flow 7. */
    public function test_that_a_team_leader_can_archive_nothing_while_team_is_unbacked(): void
    {
        $id = $this->customer('Alpha Trading');

        $this->patchJson($this->archiveUrl($id), [], $this->bearerFor(RoleName::TeamLeader))
            ->assertStatus(404);

        $this->assertDatabaseHas('customers', ['id' => $id, 'is_archived' => false]);
    }

    // ─────────────────────────────── the happy path

    public function test_that_a_manager_archives_a_customer(): void
    {
        $id = $this->customer('Alpha Trading');

        $this->patchJson($this->archiveUrl($id), [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.is_archived', true);

        $this->assertDatabaseHas('customers', ['id' => $id, 'is_archived' => true]);
    }

    public function test_that_a_manager_restores_a_customer(): void
    {
        $id = $this->customer('Alpha Trading', archived: true);

        $this->patchJson($this->restoreUrl($id), [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.is_archived', false);

        $this->assertDatabaseHas('customers', ['id' => $id, 'is_archived' => false]);
    }

    /** Flow 7: "Customer disappears from the customer list." */
    public function test_that_an_archived_customer_leaves_the_default_list(): void
    {
        $id = $this->customer('Alpha Trading');
        $headers = $this->bearerFor(RoleName::Manager);

        $this->patchJson($this->archiveUrl($id), [], $headers)->assertStatus(200);

        $this->getJson(self::ENDPOINT, $headers)
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 0);

        $this->getJson(self::ENDPOINT.'?filter[is_archived]=true', $headers)
            ->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1);
    }

    /** Flow 7: "No customer is ever permanently deleted." `DB-01` says the same. */
    public function test_that_archiving_is_not_a_delete(): void
    {
        $id = $this->customer('Alpha Trading');

        $this->patchJson($this->archiveUrl($id), [], $this->bearerFor(RoleName::Manager))->assertStatus(200);

        $row = DB::table('customers')->where('id', $id)->first();

        self::assertNotNull($row);
        self::assertNull($row->deleted_at, 'Flow 7 archives; it never soft-deletes.');
    }

    /** `DB-02` — who did it, on the row as well as in the audit. */
    public function test_that_archiving_records_the_actor_on_the_row(): void
    {
        $id = $this->customer('Alpha Trading');

        $this->patchJson($this->archiveUrl($id), [], $this->bearerFor(RoleName::Manager))->assertStatus(200);

        $this->assertDatabaseHas('customers', [
            'id' => $id,
            'updated_by' => $this->userWith(RoleName::Manager)->id,
        ]);
    }

    public function test_that_an_unknown_customer_is_404_on_archive(): void
    {
        $this->patchJson($this->archiveUrl((string) Str::uuid7()), [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    public function test_that_an_unknown_customer_is_404_on_restore(): void
    {
        $this->patchJson($this->restoreUrl((string) Str::uuid7()), [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(404);
    }

    // ─────────────────────────────── idempotence

    public function test_that_archiving_an_archived_customer_changes_nothing_and_records_nothing(): void
    {
        $id = $this->customer('Alpha Trading', archived: true);

        $this->patchJson($this->archiveUrl($id), [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.is_archived', true);

        self::assertSame(0, $this->auditCount('CUSTOMER_ARCHIVED', $id),
            'AUD-03 keeps audit rows forever; one recording a change that did not happen is a permanent lie.');
    }

    public function test_that_restoring_a_live_customer_changes_nothing_and_records_nothing(): void
    {
        $id = $this->customer('Alpha Trading');

        $this->patchJson($this->restoreUrl($id), [], $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.is_archived', false);

        self::assertSame(0, $this->auditCount('ARCHIVE_RESTORED', $id));
    }

    // ─────────────────────────────── AUD-01 · §3.12 rule 4

    public function test_that_archiving_writes_an_audit_entry(): void
    {
        $id = $this->customer('Alpha Trading');

        $this->patchJson($this->archiveUrl($id), [], $this->bearerFor(RoleName::Manager))->assertStatus(200);

        self::assertSame(1, $this->auditCount('CUSTOMER_ARCHIVED', $id));
    }

    /** §3.12 rule 4 names "restore from archive" among the nine that must always be audited. */
    public function test_that_restoring_writes_the_mandatory_archive_restored_entry(): void
    {
        $id = $this->customer('Alpha Trading', archived: true);

        $this->patchJson($this->restoreUrl($id), [], $this->bearerFor(RoleName::Manager))->assertStatus(200);

        self::assertSame(1, $this->auditCount('ARCHIVE_RESTORED', $id));

        $row = DB::table('audit_log')->where('event', 'ARCHIVE_RESTORED')->where('entity_id', $id)->first();

        self::assertNotNull($row);
        self::assertSame('customer', $row->entity_type);
        self::assertSame($this->userWith(RoleName::Manager)->id, $row->user_id);
    }
}
