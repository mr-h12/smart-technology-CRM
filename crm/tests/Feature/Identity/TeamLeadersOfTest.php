<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Module 16, Point 2 (`D-153`, `D-138`) — each user's Team Leader, for J-16's
 * "the owner and the owner's Team Leader". Only an **active**, visible, live
 * leader is named: an alert to someone who cannot sign in is an alert nobody
 * reads. The user themself may be inactive — their leader still answers for
 * the deal. A user with no leader has no entry; the empty list asks nothing.
 */
final class TeamLeadersOfTest extends TestCase
{
    use RefreshDatabase;

    public function test_that_each_user_maps_to_their_team_leader(): void
    {
        $leader = User::factory()->create();
        $member = User::factory()->create(['team_leader_id' => $leader->id]);
        $inactiveMember = User::factory()->inactive()->create(['team_leader_id' => $leader->id]);
        $alone = User::factory()->create();

        self::assertEquals(
            [$member->id => $leader->id, $inactiveMember->id => $leader->id],
            $this->facts()->teamLeadersOf([$member->id, $inactiveMember->id, $alone->id]),
        );
    }

    public function test_that_an_inactive_hidden_or_deleted_leader_is_not_named(): void
    {
        $inactive = User::factory()->inactive()->create();
        $hidden = User::factory()->hidden()->create();
        $deleted = User::factory()->create();
        $a = User::factory()->create(['team_leader_id' => $inactive->id]);
        $b = User::factory()->create(['team_leader_id' => $hidden->id]);
        $c = User::factory()->create(['team_leader_id' => $deleted->id]);
        $deleted->delete();

        self::assertSame([], $this->facts()->teamLeadersOf([$a->id, $b->id, $c->id]));
    }

    public function test_that_the_empty_list_answers_empty(): void
    {
        self::assertSame([], $this->facts()->teamLeadersOf([]));
    }

    private function facts(): UserFactsInterface
    {
        return $this->app->make(UserFactsInterface::class);
    }
}
