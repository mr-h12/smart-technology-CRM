<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Authentication\IdentityAuditEvents;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Domain\RoleAdministration\ReferenceListCriteria;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Identity\Infrastructure\Eloquent\UserSession;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Point 5.4 — `SEC-05`: "8-hour session timeout + **active device list** +
 * force logout".
 *
 * `SEC-05` · `SEC-10` · §3.1 · §9 Flow 0 · `D-29` · `D-74` · `DB-01` ·
 * `DB-08` · `AUD-01` · `AUD-02` · `AUD-03` · Coding Standards §9 ·
 * `OpenAPI §4.1`, §4.2, §5, §5.1, §6.1, §6.2.
 *
 * ── The three properties this file exists to defend ────────────────────────
 *
 * 1. **The list is the caller's own and nobody else's.** There is no `user_id`
 *    parameter and no filter that could become one.
 * 2. **A live credential is never published.** `D-74` stores the SHA-256
 *    digest of the bearer token in `user_sessions.session_id`; that column
 *    must not appear in a response body, and must not appear in an `audit_log`
 *    row either, which `AUD-03` makes permanent.
 * 3. **§3.1's Super Admin stays hidden.** A Login As session belongs to the
 *    administrator driving it, so it is neither listed to the account owner
 *    nor revocable by them — which also means it cannot be discovered by
 *    guessing an id.
 */
final class SessionManagementTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private const LIST = '/api/v1/auth/sessions';

    private function user(string $email = 'person@example.test'): User
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => RoleName::IndoorSales->value],
            ['name' => RoleName::IndoorSales->label(), 'is_system' => true],
        );

        $user = new User;
        $user->fill([
            'name' => 'Test Person',
            'email' => $email,
            'password' => self::PASSWORD,   // the `hashed` cast does SEC-02's half
            'role_id' => $role->id,
            'is_active' => true,
            'is_hidden' => false,
        ]);
        $user->save();

        return $user;
    }

    /** Logs in for real, so every token under test is one the login flow issued. */
    private function tokenFor(User $user): string
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(201)->json('data.token');

        self::assertIsString($token);

        return $token;
    }

    /**
     * `GET /auth/sessions`, narrowed once.
     *
     * PHPStan level 10 types `->json()` as `mixed`, and casting it at each use
     * is how a test starts asserting about the string "Array". Narrowed here,
     * with the assertions that make the narrowing honest.
     *
     * @return list<array<string, mixed>>
     */
    private function devices(string $token): array
    {
        $rows = $this->withToken($token)->getJson(self::LIST)->assertStatus(200)->json('data');

        self::assertIsArray($rows);

        $devices = [];

        foreach ($rows as $row) {
            self::assertIsArray($row);

            /** @var array<string, mixed> $row */
            $devices[] = $row;
        }

        return $devices;
    }

    private function deviceId(string $token, bool $current): string
    {
        foreach ($this->devices($token) as $row) {
            if (($row['is_current'] ?? null) === $current) {
                $id = $row['id'] ?? null;
                self::assertIsString($id);

                return $id;
            }
        }

        self::fail($current ? 'No row was marked current.' : 'No remote device was listed.');
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

    // ── the listing ─────────────────────────────────────────────────────────

    public function test_the_caller_sees_their_own_devices_with_exactly_one_marked_current(): void
    {
        $user = $this->user();
        $first = $this->tokenFor($user);
        $this->tokenFor($user);
        $this->tokenFor($user);

        $response = $this->withToken($first)->getJson(self::LIST)->assertStatus(200);

        $rows = $this->devices($first);
        self::assertCount(3, $rows);

        $current = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['is_current'] ?? null) === true,
        ));

        self::assertCount(1, $current, 'Exactly one row is the device making the call.');
    }

    public function test_the_current_row_is_the_session_the_token_resolves_to(): void
    {
        $user = $this->user();
        $first = $this->tokenFor($user);
        $second = $this->tokenFor($user);

        // Two tokens, two different rows. A screen that inferred "current" from
        // the most recent activity would mark the same row for both.
        self::assertNotSame($this->deviceId($first, true), $this->deviceId($second, true));
    }

    public function test_the_response_never_carries_the_session_fingerprint(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        $body = $this->withToken($token)->getJson(self::LIST)->assertStatus(200)->getContent();

        self::assertIsString($body);
        // D-74 · Coding Standards §9. Neither the token nor the digest the
        // server compares it against may leave the server.
        self::assertStringNotContainsString($token, $body);
        self::assertStringNotContainsString(hash('sha256', $token), $body);
        self::assertStringNotContainsString('session_id', $body);
    }

    public function test_the_payload_carries_the_fields_sec_05_asks_for(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        $this->withToken($token)->getJson(self::LIST)
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'ip_address', 'user_agent', 'last_activity_at', 'signed_in_at', 'is_current']],
                'meta' => [
                    'request_id',
                    // OpenAPI §4.2: all six, on every list endpoint.
                    'pagination' => [
                        'page', 'per_page', 'total', 'total_pages', 'has_next_page', 'has_previous_page',
                    ],
                ],
            ]);
    }

    public function test_timestamps_are_utc_iso_8601(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        $at = $this->withToken($token)->getJson(self::LIST)->assertStatus(200)->json('data.0.last_activity_at');

        self::assertIsString($at);
        // DB-08: stored and sent in UTC, converted for display only.
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $at);
    }

    public function test_another_account_s_devices_are_not_listed(): void
    {
        $mine = $this->user('mine@example.test');
        $theirs = $this->user('theirs@example.test');

        $token = $this->tokenFor($mine);
        $this->tokenFor($theirs);

        self::assertCount(1, $this->devices($token));
    }

    public function test_an_impersonation_session_is_hidden_from_the_account_it_runs_as(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        $admin = $this->user('admin@example.test');

        $session = new UserSession;
        $session->fill([
            'user_id' => $user->id,
            'impersonator_id' => $admin->id,
            'session_id' => hash('sha256', 'an-impersonation'),
            'ip_address' => '203.0.113.9',
            'user_agent' => 'Impersonating client',
            'last_activity_at' => now(),
        ]);
        $session->save();

        // §3.1 — "completely hidden from all users". A Login As row is the
        // administrator's device, and naming it here names them by implication.
        self::assertCount(1, $this->devices($token));
        self::assertNotSame((string) $session->id, $this->deviceId($token, true));
    }

    public function test_a_revoked_device_leaves_the_listing(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);
        $other = $this->tokenFor($user);

        $target = $this->remoteSessionId($token, $other);

        $this->withToken($token)->deleteJson(self::LIST.'/'.$target)->assertStatus(200);

        self::assertCount(1, $this->devices($token));
    }

    public function test_an_over_large_page_size_is_a_400_and_not_a_clamp(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        // OpenAPI §6.1: "Invalid or excessive values return 400." The same
        // contract `GET /permissions` answers to.
        $this->withToken($token)->getJson(self::LIST.'?per_page=500')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request')
            ->assertJsonPath('error.details.0.code', 'above_maximum');
    }

    public function test_an_undeclared_filter_is_refused(): void
    {
        $user = $this->user();
        $theirs = $this->user('theirs@example.test');
        $token = $this->tokenFor($user);

        // §6.2 declares no filters on this resource, and the one a caller would
        // reach for is the one that must never work.
        $this->withToken($token)->getJson(self::LIST.'?filter[user_id]='.$theirs->id)
            ->assertStatus(400)
            ->assertJsonPath('error.details.0.code', 'unknown_filter');
    }

    public function test_the_default_order_is_most_recently_active_first(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);
        $other = $this->tokenFor($user);

        // The second login is the newer row; the first token then becomes the
        // most recently *active* one by making this request.
        $rows = $this->devices($token);

        self::assertTrue(($rows[0]['is_current'] ?? null) === true, 'The caller just moved; it sorts first.');
        self::assertNotSame('', $other);
    }

    public function test_the_two_shipped_reference_resources_still_declare_ascending_defaults(): void
    {
        // Point 5.4 made `sort()` parse §6.2's `-` prefix out of the *default*
        // as well as out of a submitted value, so that a resource may document
        // a descending default. That change is only safe while the two shipped
        // resources declare no prefix — if one ever gains a `-`, its listing
        // silently flips order.
        self::assertStringStartsNotWith('-', ReferenceListCriteria::ROLE_DEFAULT_SORT);
        self::assertStringStartsNotWith('-', ReferenceListCriteria::PERMISSION_DEFAULT_SORT);
        self::assertStringStartsWith('-', ReferenceListCriteria::SESSION_DEFAULT_SORT);
    }

    // ── revoking one device ─────────────────────────────────────────────────

    /** The id of a session belonging to the caller that is **not** the calling one. */
    private function remoteSessionId(string $callerToken, string $otherToken): string
    {
        self::assertNotSame($callerToken, $otherToken);

        return $this->deviceId($callerToken, false);
    }

    public function test_revoking_a_remote_device_kills_that_token_and_not_the_caller_s(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);
        $other = $this->tokenFor($user);

        $target = $this->remoteSessionId($token, $other);

        $this->withToken($token)->deleteJson(self::LIST.'/'.$target)
            ->assertStatus(200)
            ->assertJsonPath('data.revoked', true)
            ->assertJsonStructure(['data' => ['revoked', 'session_id'], 'meta' => ['request_id']]);

        // The point of the feature: the evicted device is actually evicted.
        $this->withToken($other)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);
    }

    public function test_a_revocation_is_a_soft_delete(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);
        $other = $this->tokenFor($user);

        $target = $this->remoteSessionId($token, $other);

        $this->withToken($token)->deleteJson(self::LIST.'/'.$target)->assertStatus(200);

        // DB-01 forbids the physical delete. The row is what a later "who was
        // signed in on the 3rd" question reads.
        self::assertNull(UserSession::query()->find($target));
        self::assertNotNull(UserSession::withTrashed()->find($target));
    }

    public function test_revoking_the_calling_session_is_refused_rather_than_half_done(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        $this->withToken($token)->deleteJson(self::LIST.'/'.$this->deviceId($token, true))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'business_rule_blocked')
            ->assertJsonPath('error.details.0.code', 'session_is_current');

        // And it is still alive: a refusal that revoked anyway would be worse
        // than the behaviour it refused to perform.
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);
    }

    public function test_another_account_s_session_answers_404_and_survives(): void
    {
        $mine = $this->user('mine@example.test');
        $theirs = $this->user('theirs@example.test');

        $token = $this->tokenFor($mine);
        $theirToken = $this->tokenFor($theirs);

        $target = $this->deviceId($theirToken, true);

        $this->withToken($token)->deleteJson(self::LIST.'/'.$target)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found')
            ->assertJsonPath('error.details.0.code', 'session_not_found');

        $this->withToken($theirToken)->getJson('/api/v1/auth/me')->assertStatus(200);
    }

    public function test_an_impersonation_session_cannot_be_revoked_by_guessing_its_id(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);
        $admin = $this->user('admin@example.test');

        $session = new UserSession;
        $session->fill([
            'user_id' => $user->id,
            'impersonator_id' => $admin->id,
            'session_id' => hash('sha256', 'an-impersonation'),
            'ip_address' => null,
            'user_agent' => null,
            'last_activity_at' => now(),
        ]);
        $session->save();

        // Hidden from the list *and* from the command, or the list would only
        // be hiding the id from somebody who has not tried yet.
        $this->withToken($token)->deleteJson(self::LIST.'/'.$session->id)
            ->assertStatus(404)
            ->assertJsonPath('error.details.0.code', 'session_not_found');

        self::assertNotNull(UserSession::query()->find($session->id));
    }

    public function test_a_real_login_as_session_is_hidden_from_the_target(): void
    {
        // The same property as the two tests above, through the real endpoint
        // rather than a hand-built row — `SEC-10`'s flow is what has to stay
        // invisible, not a fixture that resembles it.
        $this->seed(RolePermissionSeeder::class);

        $superAdminRole = Role::query()->where('slug', RoleName::SuperAdmin->value)->firstOrFail();

        $admin = new User;
        $admin->fill([
            'name' => 'Test Super Admin',
            'email' => 'super.admin@example.test',
            'password' => self::PASSWORD,
            'role_id' => $superAdminRole->id,
            'is_active' => true,
            'is_hidden' => true,
        ]);
        $admin->save();

        $target = $this->user('target@example.test');
        $targetToken = $this->tokenFor($target);

        $adminToken = $this->tokenFor($admin);
        $this->withToken($adminToken)->postJson('/api/v1/auth/impersonate/'.$target->id)->assertStatus(201);

        self::assertCount(1, $this->devices($targetToken), 'The Login As session is not one of the target\'s devices.');
    }

    // ── revoking every other device ─────────────────────────────────────────

    public function test_sign_out_everywhere_else_keeps_the_calling_session(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);
        $second = $this->tokenFor($user);
        $third = $this->tokenFor($user);

        $this->withToken($token)->deleteJson(self::LIST)
            ->assertStatus(200)
            ->assertJsonPath('data.revoked', 2)
            ->assertJsonPath('data.current_session_kept', true);

        $this->withToken($second)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->withToken($third)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(200);
    }

    public function test_sign_out_everywhere_else_is_idempotent(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);
        $this->tokenFor($user);

        $this->withToken($token)->deleteJson(self::LIST)->assertJsonPath('data.revoked', 1);
        $this->withToken($token)->deleteJson(self::LIST)->assertJsonPath('data.revoked', 0);
    }

    public function test_sign_out_everywhere_else_leaves_other_accounts_alone(): void
    {
        $mine = $this->user('mine@example.test');
        $theirs = $this->user('theirs@example.test');

        $token = $this->tokenFor($mine);
        $theirToken = $this->tokenFor($theirs);

        $this->withToken($token)->deleteJson(self::LIST)->assertStatus(200);

        $this->withToken($theirToken)->getJson('/api/v1/auth/me')->assertStatus(200);
    }

    // ── AUD-01 ──────────────────────────────────────────────────────────────

    public function test_revoking_one_device_writes_an_audit_row_naming_the_scope(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);
        $other = $this->tokenFor($user);

        $target = $this->remoteSessionId($token, $other);

        $this->withToken($token)->deleteJson(self::LIST.'/'.$target)->assertStatus(200);

        $rows = $this->auditRows(IdentityAuditEvents::SESSION_REVOKED);

        self::assertCount(1, $rows);
        self::assertSame('user', $rows[0]['entity_type']);
        self::assertSame($user->id, $rows[0]['entity_id']);

        $newValues = $rows[0]['new_values'];
        self::assertIsString($newValues);
        $new = json_decode($newValues, true);
        self::assertIsArray($new);
        self::assertSame('one_device', $new['scope'] ?? null);
        self::assertSame(1, $new['sessions_revoked'] ?? null);
    }

    public function test_signing_out_every_other_device_writes_its_own_row_even_when_nothing_moved(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        $this->withToken($token)->deleteJson(self::LIST)->assertStatus(200);

        $rows = $this->auditRows(IdentityAuditEvents::SESSION_REVOKED);

        self::assertCount(1, $rows, 'Pressing the button is a fact about the account, revoked or not.');

        $newValues = $rows[0]['new_values'];
        self::assertIsString($newValues);
        $new = json_decode($newValues, true);
        self::assertIsArray($new);
        self::assertSame('other_devices', $new['scope'] ?? null);
        self::assertSame(0, $new['sessions_revoked'] ?? null);
    }

    public function test_no_audit_row_carries_a_session_fingerprint(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);
        $other = $this->tokenFor($user);

        $this->withToken($token)->deleteJson(self::LIST.'/'.$this->remoteSessionId($token, $other))->assertStatus(200);
        $this->withToken($token)->deleteJson(self::LIST)->assertStatus(200);

        // AUD-03 makes these rows permanent. A permanent record of the value a
        // live credential is matched against outlives the credential.
        foreach ($this->auditRows(IdentityAuditEvents::SESSION_REVOKED) as $row) {
            $serialised = json_encode($row);
            self::assertIsString($serialised);
            self::assertStringNotContainsString(hash('sha256', $other), $serialised);
            self::assertStringNotContainsString($other, $serialised);
        }
    }

    public function test_a_refused_revocation_writes_no_audit_row(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        $this->withToken($token)->deleteJson(self::LIST.'/'.$this->deviceId($token, true))->assertStatus(422);

        self::assertSame([], $this->auditRows(IdentityAuditEvents::SESSION_REVOKED));
    }

    // ── the guard ───────────────────────────────────────────────────────────

    public function test_every_route_refuses_an_unauthenticated_caller(): void
    {
        $this->getJson(self::LIST)->assertStatus(401);
        $this->deleteJson(self::LIST)->assertStatus(401);
        $this->deleteJson(self::LIST.'/'.fake()->uuid())->assertStatus(401);
    }

    public function test_a_revoked_token_cannot_read_the_device_list(): void
    {
        $user = $this->user();
        $token = $this->tokenFor($user);

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertStatus(200);

        $this->withToken($token)->getJson(self::LIST)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'authentication_required');
    }
}
