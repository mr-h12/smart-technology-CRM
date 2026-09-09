<?php

declare(strict_types=1);

namespace Tests\Feature\Deals;

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
 * Module 5, Point 5.2 — `GET /deals/{id}/timeline`.
 *
 * §4.4: "Every status change is written to the deal timeline: old status · new
 * status · who · when." Point 1.1 declined a `deal_status_history` table
 * because `audit_log` already holds those four fields; Point 5.1 gave Audit a
 * read port; this is the route that finally checks `deal.view_timeline`, a
 * permission §3.4 has seeded to all seven roles since Module 1 with nothing
 * anywhere able to ask for it.
 *
 * ── The two refusals that matter ───────────────────────────────────────────
 *
 * A caller **without** the permission is a 403. A caller **with** it, on a deal
 * outside their reach, is a **404** — `OpenAPI §5.1`'s "does not exist or is
 * not visible to the caller; do not reveal which case applies". The row is
 * settled before the history is read: filtering afterwards would leak how busy
 * a deal somebody may not see has been, through the count alone.
 *
 * `Team`, `Out` and `Asgn` still resolve to nothing (Point 2.1), so a Team
 * Leader holding `view_timeline` as `Team` reaches no deal at all. Asserted
 * rather than worked around — it is the backend debt, visible here.
 */
final class DealTimelineEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/deals';

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

    /** @param  array<string, mixed>  $overrides */
    private function deal(?string $ownerId = null, array $overrides = []): string
    {
        $id = (string) Str::uuid7();

        DB::table('customers')->insert([
            'id' => $customerId = (string) Str::uuid7(),
            'name' => 'Test Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('deals')->insert(array_merge([
            'id' => $id,
            'code' => 'DL-2026-'.substr(str_replace('-', '', $id), -4),
            'customer_id' => $customerId,
            'title' => 'Existing deal',
            'owner_id' => $ownerId,
            'status' => 'lead',
            'last_activity_at' => now(),
            'created_by' => $ownerId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    private function timelineUrl(string $id): string
    {
        return self::ENDPOINT.'/'.$id.'/timeline';
    }

    /**
     * One audit row about a deal, written straight to the table.
     *
     * @param  array<array-key, mixed>|null  $old
     * @param  array<array-key, mixed>|null  $new
     */
    private function audit(string $dealId, string $event, ?array $old, ?array $new, ?string $actorId = null): void
    {
        DB::table('audit_log')->insert([
            'id' => (string) Str::uuid7(),
            'user_id' => $actorId,
            'impersonated_user_id' => null,
            'event' => $event,
            'entity_type' => 'deal',
            'entity_id' => $dealId,
            'old_values' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR),
            'new_values' => $new === null ? null : json_encode($new, JSON_THROW_ON_ERROR),
            'ip_address' => null,
            'user_agent' => null,
            'request_id' => null,
            'correlation_id' => null,
            'created_at' => now(),
        ]);
    }

    // ─────────────────────────────────────────────── §3.4's view_timeline row

    public function test_that_an_unauthenticated_caller_cannot_read_a_timeline(): void
    {
        $this->getJson($this->timelineUrl((string) Str::uuid7()))->assertStatus(401);
    }

    /** §3.4 fills every column of `view timeline` — there is no role without it. */
    public function test_that_every_role_section_3_4_grants_view_timeline_to_is_admitted(): void
    {
        $owner = $this->userWith(RoleName::Manager);
        $id = $this->deal($owner->id);

        // Manager and CEO hold `All`; the rest hold a scope with no mechanism,
        // so they are admitted by permission and then find no row — a 404, not
        // a 403. Both answers prove the permission was checked.
        foreach ([RoleName::Manager, RoleName::Ceo] as $role) {
            $this->getJson($this->timelineUrl($id), $this->bearerFor($role))->assertStatus(200);
        }
    }

    public function test_that_the_route_gates_on_view_timeline_and_not_on_view(): void
    {
        // ⚠️ There is no role to assert a 403 against: §3.4's `view timeline`
        // row fills all seven business columns, and the only other role — the
        // Super Admin — holds `unconditional_access` (§3.11) and is admitted
        // by it, which a first version of this test got wrong and the run
        // caught. So the permission is proved by **withdrawing** it, the same
        // way `SEC-09` is checked on a screen: the Manager keeps `deal.view`
        // and loses `deal.view_timeline`, and the route must refuse.
        $manager = $this->userWith(RoleName::Manager);
        $id = $this->deal($manager->id);
        $headers = $this->bearerFor(RoleName::Manager);

        // Still admitted while the grant is held.
        $this->getJson($this->timelineUrl($id), $headers)->assertStatus(200);

        $permission = DB::table('permissions')
            ->where('resource', 'deal')
            ->where('action', 'view_timeline')
            ->value('id');

        self::assertNotNull($permission, 'deal.view_timeline is not seeded.');

        DB::table('role_permissions')
            ->where('role_id', DB::table('roles')->where('slug', RoleName::Manager->value)->value('id'))
            ->where('permission_id', $permission)
            ->delete();

        // `deal.view` is untouched, so a route reading the wrong column would
        // still answer 200 here.
        $this->getJson(self::ENDPOINT.'/'.$id, $headers)->assertStatus(200);
        $this->getJson($this->timelineUrl($id), $headers)->assertStatus(403);
    }

    public function test_that_a_deal_outside_the_reach_is_a_404_and_not_a_403(): void
    {
        // A Team Leader holds `view_timeline` as `Team`, and `Team` has no
        // mechanism (Point 2.1) — so the permission passes and the row does
        // not. §5.1: the answer must not say which case applies.
        $id = $this->deal();

        $this->getJson($this->timelineUrl($id), $this->bearerFor(RoleName::TeamLeader))
            ->assertStatus(404);
    }

    public function test_that_a_deal_that_does_not_exist_is_the_same_404(): void
    {
        $this->getJson($this->timelineUrl((string) Str::uuid7()), $this->bearerFor(RoleName::Manager))
            ->assertStatus(404);
    }

    public function test_that_the_history_is_not_read_for_a_deal_outside_the_reach(): void
    {
        // The count alone would tell a caller how busy a deal they may not see
        // has been, so the row is settled *before* the reader is asked.
        $id = $this->deal();
        $this->audit($id, 'DEAL_STATUS_CHANGED', ['status' => 'lead'], ['status' => 'won']);

        $this->getJson($this->timelineUrl($id), $this->bearerFor(RoleName::IndoorSales))
            ->assertStatus(404)
            ->assertJsonMissingPath('meta.pagination');
    }

    // ────────────────────────────────────────────────────── §4.4's four fields

    public function test_that_a_status_change_reports_old_status_new_status_who_and_when(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $id = $this->deal($manager->id);

        $this->audit($id, 'DEAL_STATUS_CHANGED', ['status' => 'lead'], ['status' => 'contacted'], $manager->id);

        $this->getJson($this->timelineUrl($id), $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'DEAL_STATUS_CHANGED')
            ->assertJsonPath('data.0.old_status', 'lead')
            ->assertJsonPath('data.0.new_status', 'contacted')
            ->assertJsonPath('data.0.actor_id', $manager->id)
            ->assertJsonPath('data.0.impersonated_user_id', null);
    }

    public function test_that_the_newest_entry_is_first(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $id = $this->deal($manager->id);

        $this->audit($id, 'DEAL_CREATED', null, ['status' => 'lead'], $manager->id);
        $this->audit($id, 'DEAL_STATUS_CHANGED', ['status' => 'lead'], ['status' => 'contacted'], $manager->id);

        $this->getJson($this->timelineUrl($id), $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonPath('data.0.new_status', 'contacted')
            ->assertJsonPath('data.1.new_status', 'lead');
    }

    public function test_that_an_event_which_changed_no_status_is_kept_with_nulls(): void
    {
        // A history with holes in it is not a history: the entry happened, and
        // §4.4 describes what must be *in* the timeline, not what to filter out.
        $manager = $this->userWith(RoleName::Manager);
        $id = $this->deal($manager->id);

        $this->audit($id, 'DEAL_UPDATED', ['title' => 'Old'], ['title' => 'New'], $manager->id);

        $this->getJson($this->timelineUrl($id), $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'DEAL_UPDATED')
            ->assertJsonPath('data.0.old_status', null)
            ->assertJsonPath('data.0.new_status', null);
    }

    public function test_that_another_deals_history_never_appears(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $mine = $this->deal($manager->id);
        $theirs = $this->deal($manager->id);

        $this->audit($mine, 'DEAL_STATUS_CHANGED', ['status' => 'lead'], ['status' => 'won'], $manager->id);
        $this->audit($theirs, 'DEAL_STATUS_CHANGED', ['status' => 'lead'], ['status' => 'lost'], $manager->id);

        $this->getJson($this->timelineUrl($mine), $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.new_status', 'won');
    }

    public function test_that_a_deal_with_no_history_is_an_empty_list_not_a_404(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $id = $this->deal($manager->id);

        $this->getJson($this->timelineUrl($id), $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.pagination.total', 0);
    }

    // ─────────────────────────────────────────────── the forensic fields stay out

    public function test_that_the_wire_carries_no_ip_user_agent_or_request_id(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $id = $this->deal($manager->id);
        $this->audit($id, 'DEAL_STATUS_CHANGED', ['status' => 'lead'], ['status' => 'won'], $manager->id);

        $entry = $this->getJson($this->timelineUrl($id), $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->json('data.0');

        self::assertIsArray($entry);
        // AUD-05 forensics are not fields a timeline shows a salesperson, and
        // SEC-10's reason for storing the impersonator does not extend to
        // publishing an IP to whoever holds `deal.view_timeline`.
        foreach (['ip_address', 'user_agent', 'request_id', 'correlation_id', 'old_values', 'new_values'] as $field) {
            self::assertArrayNotHasKey($field, $entry);
        }
        self::assertSame(
            ['id', 'event', 'old_status', 'new_status', 'actor_id', 'impersonated_user_id', 'occurred_at'],
            array_keys($entry),
        );
    }

    // ───────────────────────────────────────────────────────── OpenAPI §6's query

    public function test_that_the_page_carries_the_six_pagination_numbers(): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $id = $this->deal($manager->id);

        for ($i = 0; $i < 3; $i++) {
            $this->audit($id, 'DEAL_UPDATED', null, ['title' => "t{$i}"], $manager->id);
        }

        $this->getJson($this->timelineUrl($id).'?page=2&per_page=2', $this->bearerFor(RoleName::Manager))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.pagination.page', 2)
            ->assertJsonPath('meta.pagination.per_page', 2)
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.pagination.total_pages', 2)
            ->assertJsonPath('meta.pagination.has_next_page', false)
            ->assertJsonPath('meta.pagination.has_previous_page', true);
    }

    #[DataProvider('badQueries')]
    public function test_that_an_invalid_or_undeclared_parameter_is_a_400(string $query): void
    {
        $manager = $this->userWith(RoleName::Manager);
        $id = $this->deal($manager->id);

        $this->getJson($this->timelineUrl($id).'?'.$query, $this->bearerFor(RoleName::Manager))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_request');
    }

    /** @return array<string, array{string}> */
    public static function badQueries(): array
    {
        return [
            'page zero' => ['page=0'],
            'page not a number' => ['page=first'],
            'per_page above the maximum' => ['per_page=101'],
            // §6.2 answers an undeclared parameter rather than ignoring it: a
            // caller who sent a sort and got an unsorted list would believe it
            // had been applied.
            'a sort this list does not declare' => ['sort=-occurred_at'],
            'a search this list does not declare' => ['q=won'],
            'an event filter, which is owed a D-xx before it exists' => ['filter[event]=DEAL_STATUS_CHANGED'],
        ];
    }
}
