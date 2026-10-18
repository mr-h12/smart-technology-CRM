<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `D-138` (Module 13 · 7.5c): a Team Leader's team is the leader plus every
 * user whose `team_leader_id` is theirs. Owner, 2026-10-18: a deactivated
 * member stays in the team; a hidden or soft-deleted user does not.
 */
final class TeamMemberIdsTest extends TestCase
{
    use RefreshDatabase;

    public function test_that_the_team_is_the_leader_and_their_members(): void
    {
        $leader = User::factory()->create();
        $member = User::factory()->create(['team_leader_id' => $leader->id]);
        $inactive = User::factory()->inactive()->create(['team_leader_id' => $leader->id]);
        User::factory()->hidden()->create(['team_leader_id' => $leader->id]);
        User::factory()->create(['team_leader_id' => $leader->id])->delete();

        $otherLeader = User::factory()->create();
        User::factory()->create(['team_leader_id' => $otherLeader->id]);
        User::factory()->create();

        self::assertEqualsCanonicalizing(
            [$leader->id, $member->id, $inactive->id],
            $this->facts()->teamMemberIdsOf($leader->id),
        );
    }

    /** `D-139`: a lead role never sits in a team, even when a legacy or direct-DB row says it does. */
    public function test_that_a_lead_role_is_never_a_member(): void
    {
        $leader = User::factory()->create();
        $member = User::factory()->create(['team_leader_id' => $leader->id]);

        foreach (['super_admin', 'ceo', 'manager', 'team_leader'] as $slug) {
            $roleId = Role::firstOrCreate(['slug' => $slug], ['name' => $slug, 'is_system' => true])->id;
            $lead = User::factory()->create(['role_id' => $roleId]);
            DB::table('users')->where('id', $lead->id)->update(['team_leader_id' => $leader->id]);
        }

        self::assertSame([$leader->id, $member->id], $this->facts()->teamMemberIdsOf($leader->id));
    }

    public function test_that_a_user_who_leads_nobody_is_a_team_of_one(): void
    {
        $user = User::factory()->create();
        User::factory()->create();

        self::assertSame([$user->id], $this->facts()->teamMemberIdsOf($user->id));
    }

    private function facts(): UserFactsInterface
    {
        return $this->app->make(UserFactsInterface::class);
    }
}
