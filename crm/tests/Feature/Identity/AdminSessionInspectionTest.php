<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Identity\Infrastructure\Eloquent\UserSession;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Point 5.5 — §13 screen 2's "devices · IP · browser", and its force logout.
 *
 * §3.1 · §3.11 · §3.12 rules 1 and 6 · `SEC-05` · `SEC-09` · `SEC-10` ·
 * `D-34` · `D-74` · `D-78` · `DB-01` · `AUD-01` · `AUD-02` · `AUD-03` ·
 * Coding Standards §9 · `OpenAPI §4.2`, §5.1, §6.1.
 *
 * ── The four properties this file exists to defend ─────────────────────────
 *
 * 1. **§3.11 decides who may look.** A role with no administration row is
 *    refused at the API, not merely deprived of a button (§3.12 rule 1).
 * 2. **§3.12 rule 6 survives the new endpoints.** The hidden Super Admin's
 *    devices are a `404` that does not say whether the account exists — the
 *    same answer `GET /users/{id}` already gives.
 * 3. **A Login As session is not a device.** §3.1 hides the Super Admin from
 *    *all* users, and the Manager reading this screen is one of them.
 * 4. **A live credential is never published**, and neither is it written into
 *    a row `AUD-03` makes permanent.
 */
final class AdminSessionInspectionTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    protected function setUp(): void
    {
        parent::setUp();

        // SEC-07: the administration abilities are grant rows, so the matrix
        // has to be in the database before a single request is made.
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWith(RoleName $role, ?string $email = null): User
    {
        $row = Role::query()->where('slug', $role->value)->firstOrFail();

        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label(),
            'email' => $email ?? str_replace('_', '.', $role->value).'@example.test',
            'password' => self::PASSWORD,
            'role_id' => $row->id,
            'is_active' => true,
            'is_hidden' => $role->isHidden(),
        ]);
        $user->save();

        return $user;
    }

    private function tokenFor(User $user): string
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(201)->json('data.token');

        self::assertIsString($token);

        return $token;
    }

    private static function endpoint(User $target): string
    {
        return '/api/v1/users/'.$target->id.'/sessions';
    }

    /**
     * The target's device rows as the administrator sees them, narrowed once.
     *
     * PHPStan level 10 types `->json()` as `mixed`, and casting it at each use
     * is how a test starts asserting about the string "Array".
     *
     * @return list<array<string, mixed>>
     */
    private function devices(string $adminToken, User $target): array
    {
        $rows = $this->withToken($adminToken)->getJson(self::endpoint($target))->assertStatus(200)->json('data');

        self::assertIsArray($rows);

        $devices = [];

        foreach ($rows as $row) {
            self::assertIsArray($row);

            /** @var array<string, mixed> $row */
            $devices[] = $row;
        }

        return $devices;
    }

    private function firstDeviceId(string $adminToken, User $target): string
    {
        $rows = $this->devices($adminToken, $target);

        self::assertNotSame([], $rows, 'The target has no listed device.');

        $id = $rows[0]['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /** @return list<array<string, mixed>> */
    private function auditRows(string $event): array
    {
        $rows = [];

        foreach (DB::select('SELECT * FROM audit_log WHERE event = ?', [$event]) as $row) {
            /** @var array<string, mixed> $fields */
            $fields = (array) $row;
            $rows[] = $fields;
        }

        return $rows;
    }

    // ── §3.11 decides who may look ──────────────────────────────────────────

    public function test_a_manager_may_read_an_employee_s_devices(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $target = $this->userWith(RoleName::IndoorSales);

        $this->tokenFor($target);
        $this->tokenFor($target);

        self::assertCount(2, $this->devices($this->tokenFor($manager), $target));
    }

    public function test_a_role_with_no_administration_row_is_refused_at_the_api(): void
    {
        $target = $this->userWith(RoleName::IndoorSales, 'target@example.test');
        $nosy = $this->userWith(RoleName::Procurement);

        $token = $this->tokenFor($nosy);
        $this->tokenFor($target);

        // §3.12 rule 1: enforcement is here, not in a hidden button.
        $this->withToken($token)->getJson(self::endpoint($target))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'permission_denied');

        $this->withToken($token)->deleteJson(self::endpoint($target).'/'.fake()->uuid())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'permission_denied');
    }

    public function test_every_route_refuses_an_unauthenticated_caller(): void
    {
        $target = $this->userWith(RoleName::IndoorSales);

        $this->getJson(self::endpoint($target))->assertStatus(401);
        $this->deleteJson(self::endpoint($target).'/'.fake()->uuid())->assertStatus(401);
    }

    // ── §3.12 rule 6 ────────────────────────────────────────────────────────

    public function test_the_hidden_super_admin_s_devices_are_a_404(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $admin = $this->userWith(RoleName::SuperAdmin);

        $token = $this->tokenFor($manager);
        $this->tokenFor($admin);

        // §5.1: "does not exist **or** is not visible to the caller. Do not
        // reveal which case applies." A 403 would confirm the account.
        $this->withToken($token)->getJson(self::endpoint($admin))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found')
            ->assertJsonPath('error.details.0.code', 'user_not_found');
    }

    public function test_the_hidden_super_admin_s_device_cannot_be_terminated(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $admin = $this->userWith(RoleName::SuperAdmin);

        $token = $this->tokenFor($manager);
        $adminToken = $this->tokenFor($admin);

        $session = UserSession::query()->where('user_id', $admin->id)->firstOrFail();

        $this->withToken($token)->deleteJson(self::endpoint($admin).'/'.$session->id)
            ->assertStatus(404)
            ->assertJsonPath('error.details.0.code', 'user_not_found');

        // Hiding the listing while allowing the command would be no hiding at
        // all: the id is guessable, and the account would still be evictable.
        $this->withToken($adminToken)->getJson('/api/v1/auth/me')->assertStatus(200);
    }

    public function test_an_unknown_user_id_is_the_same_404(): void
    {
        $manager = $this->userWith(RoleName::Manager);

        $this->withToken($this->tokenFor($manager))->getJson('/api/v1/users/'.fake()->uuid().'/sessions')
            ->assertStatus(404)
            ->assertJsonPath('error.details.0.code', 'user_not_found');
    }

    // ── §3.1: a Login As session is not a device ────────────────────────────

    public function test_an_impersonation_session_is_hidden_from_the_administrative_list(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $admin = $this->userWith(RoleName::SuperAdmin);
        $target = $this->userWith(RoleName::IndoorSales);

        $managerToken = $this->tokenFor($manager);
        $this->tokenFor($target);

        $adminToken = $this->tokenFor($admin);
        $this->withToken($adminToken)->postJson('/api/v1/auth/impersonate/'.$target->id)->assertStatus(201);

        // §3.1 — "completely hidden from all users", and a Manager is one of
        // them. The row would name the administrator by implication.
        self::assertCount(1, $this->devices($managerToken, $target));
    }

    public function test_an_impersonation_session_cannot_be_terminated_by_guessing_its_id(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $admin = $this->userWith(RoleName::SuperAdmin);
        $target = $this->userWith(RoleName::IndoorSales);

        $managerToken = $this->tokenFor($manager);
        $this->tokenFor($target);
        $this->tokenFor($admin);

        $impersonation = new UserSession;
        $impersonation->fill([
            'user_id' => $target->id,
            'impersonator_id' => $admin->id,
            'session_id' => hash('sha256', 'an-impersonation'),
            'ip_address' => null,
            'user_agent' => null,
            'last_activity_at' => now(),
        ]);
        $impersonation->save();

        $this->withToken($managerToken)->deleteJson(self::endpoint($target).'/'.$impersonation->id)
            ->assertStatus(404)
            ->assertJsonPath('error.details.0.code', 'session_not_found');

        self::assertNotNull(UserSession::query()->find($impersonation->id));
    }

    // ── the payload ─────────────────────────────────────────────────────────

    public function test_the_administrative_payload_never_carries_the_fingerprint(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $target = $this->userWith(RoleName::IndoorSales);

        $managerToken = $this->tokenFor($manager);
        $targetToken = $this->tokenFor($target);

        $body = $this->withToken($managerToken)->getJson(self::endpoint($target))->assertStatus(200)->getContent();

        self::assertIsString($body);
        // D-74 · Coding Standards §9. Kept out of `SEC-05`'s own screen in
        // Point 5.4; it must not reappear because a second reader was added.
        self::assertStringNotContainsString($targetToken, $body);
        self::assertStringNotContainsString(hash('sha256', $targetToken), $body);
        self::assertStringNotContainsString('session_id', $body);
    }

    public function test_the_payload_is_the_documented_collection_envelope(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $target = $this->userWith(RoleName::IndoorSales);

        $managerToken = $this->tokenFor($manager);
        $this->tokenFor($target);

        $this->withToken($managerToken)->getJson(self::endpoint($target))
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'ip_address', 'user_agent', 'last_activity_at', 'signed_in_at', 'is_current']],
                'meta' => [
                    'request_id',
                    'pagination' => [
                        'page', 'per_page', 'total', 'total_pages', 'has_next_page', 'has_previous_page',
                    ],
                ],
            ]);
    }

    public function test_an_over_large_page_size_is_a_400_and_not_a_clamp(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $target = $this->userWith(RoleName::IndoorSales);

        $this->withToken($this->tokenFor($manager))->getJson(self::endpoint($target).'?per_page=500')
            ->assertStatus(400)
            ->assertJsonPath('error.details.0.code', 'above_maximum');
    }

    public function test_none_of_the_target_s_rows_is_marked_as_the_administrator_s_own(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $target = $this->userWith(RoleName::IndoorSales);

        $managerToken = $this->tokenFor($manager);
        $this->tokenFor($target);

        foreach ($this->devices($managerToken, $target) as $row) {
            self::assertFalse($row['is_current'] ?? null);
        }
    }

    public function test_an_administrator_inspecting_themselves_sees_which_row_is_theirs(): void
    {
        $manager = $this->userWith(RoleName::Manager);

        $token = $this->tokenFor($manager);
        $this->tokenFor($manager);

        $current = array_values(array_filter(
            $this->devices($token, $manager),
            static fn (array $row): bool => ($row['is_current'] ?? null) === true,
        ));

        self::assertCount(1, $current, 'Exactly one row is the device the administrator is looking through.');
    }

    // ── the force logout ────────────────────────────────────────────────────

    public function test_an_administrator_can_end_one_of_an_employee_s_sessions(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $target = $this->userWith(RoleName::IndoorSales);

        $managerToken = $this->tokenFor($manager);
        $doomed = $this->tokenFor($target);
        $spared = $this->tokenFor($target);

        $rows = $this->devices($managerToken, $target);
        self::assertCount(2, $rows);

        $victim = $rows[1]['id'];
        self::assertIsString($victim);

        $this->withToken($managerToken)->deleteJson(self::endpoint($target).'/'.$victim)
            ->assertStatus(200)
            ->assertJsonPath('data.revoked', true)
            ->assertJsonStructure(['data' => ['revoked', 'session_id'], 'meta' => ['request_id']]);

        // `D-34` takes every session down; this takes exactly one, which is the
        // whole reason it is a separate endpoint.
        self::assertCount(1, $this->devices($managerToken, $target));
        self::assertNull(UserSession::query()->find($victim));
        self::assertNotNull(UserSession::withTrashed()->find($victim), 'DB-01 forbids the physical delete.');
        self::assertNotSame($doomed, $spared);
    }

    public function test_the_evicted_employee_really_is_evicted(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $target = $this->userWith(RoleName::IndoorSales);

        $managerToken = $this->tokenFor($manager);
        $targetToken = $this->tokenFor($target);

        $this->withToken($managerToken)
            ->deleteJson(self::endpoint($target).'/'.$this->firstDeviceId($managerToken, $target))
            ->assertStatus(200);

        $this->withToken($targetToken)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->withToken($managerToken)->getJson('/api/v1/auth/me')->assertStatus(200);
    }

    public function test_an_administrator_may_not_end_their_own_calling_session_here(): void
    {
        $manager = $this->userWith(RoleName::Manager);

        $token = $this->tokenFor($manager);
        $mine = $this->firstDeviceId($token, $manager);

        $this->withToken($token)->deleteJson('/api/v1/users/'.$manager->id.'/sessions/'.$mine)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'business_rule_blocked')
            ->assertJsonPath('error.details.0.code', 'session_is_current');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);
    }

    public function test_a_session_belonging_to_a_different_user_answers_404(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $target = $this->userWith(RoleName::IndoorSales, 'one@example.test');
        $other = $this->userWith(RoleName::IndoorSales, 'two@example.test');

        $managerToken = $this->tokenFor($manager);
        $this->tokenFor($target);
        $otherToken = $this->tokenFor($other);

        $foreign = $this->firstDeviceId($managerToken, $other);

        // The path names `$target`; the session is `$other`'s. Revoking it
        // would mean the `{user}` segment was decoration.
        $this->withToken($managerToken)->deleteJson(self::endpoint($target).'/'.$foreign)
            ->assertStatus(404)
            ->assertJsonPath('error.details.0.code', 'session_not_found');

        $this->withToken($otherToken)->getJson('/api/v1/auth/me')->assertStatus(200);
    }

    // ── AUD-01 ──────────────────────────────────────────────────────────────

    public function test_the_termination_is_audited_against_the_target_and_the_administrator(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $target = $this->userWith(RoleName::IndoorSales);

        $managerToken = $this->tokenFor($manager);
        $this->tokenFor($target);

        $victim = $this->firstDeviceId($managerToken, $target);

        $this->withToken($managerToken)->deleteJson(self::endpoint($target).'/'.$victim)->assertStatus(200);

        $rows = $this->auditRows(IdentityAuditEvents::ADMIN_SESSION_TERMINATED);

        self::assertCount(1, $rows);
        // AUD-02: who did it, and to whom. The self-service event would record
        // only the second, which is the ambiguity this event name removes.
        self::assertSame($manager->id, $rows[0]['user_id']);
        self::assertSame('user', $rows[0]['entity_type']);
        self::assertSame($target->id, $rows[0]['entity_id']);

        $newValues = $rows[0]['new_values'];
        self::assertIsString($newValues);
        $new = json_decode($newValues, true);
        self::assertIsArray($new);
        self::assertSame('one_device', $new['scope'] ?? null);
        self::assertSame(1, $new['sessions_revoked'] ?? null);

        // And it is not filed under the self-service name.
        self::assertSame([], $this->auditRows(IdentityAuditEvents::SESSION_REVOKED));
    }

    public function test_no_audit_row_carries_a_session_fingerprint(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $target = $this->userWith(RoleName::IndoorSales);

        $managerToken = $this->tokenFor($manager);
        $targetToken = $this->tokenFor($target);

        $this->withToken($managerToken)
            ->deleteJson(self::endpoint($target).'/'.$this->firstDeviceId($managerToken, $target))
            ->assertStatus(200);

        foreach ($this->auditRows(IdentityAuditEvents::ADMIN_SESSION_TERMINATED) as $row) {
            $serialised = json_encode($row);
            self::assertIsString($serialised);
            self::assertStringNotContainsString(hash('sha256', $targetToken), $serialised);
            self::assertStringNotContainsString($targetToken, $serialised);
        }
    }

    public function test_a_refused_termination_writes_no_audit_row(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $target = $this->userWith(RoleName::IndoorSales);

        $managerToken = $this->tokenFor($manager);
        $this->tokenFor($target);

        $this->withToken($managerToken)->deleteJson(self::endpoint($target).'/'.fake()->uuid())->assertStatus(404);

        self::assertSame([], $this->auditRows(IdentityAuditEvents::ADMIN_SESSION_TERMINATED));
    }
}
