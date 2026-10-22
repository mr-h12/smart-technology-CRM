<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

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
 * Module 16, Point 1 — reading one's own alerts (`D-153`).
 *
 * - `GET /alerts/unread-count` — the bell's number, read on every page change like `GET /badges`.
 * - `GET /alerts` — the caller's open alerts, newest first, paginated (`OpenAPI §4.2`).
 * - `POST /alerts/{id}/read` and `POST /alerts/read-all` — read state only; nothing is deleted.
 *
 * No permission: an alert is addressed to a person, and every signed-in person
 * reads their own. Another person's alert answers 404, never 403 (`SEC-09`: no
 * leak that it exists). A cleared alert is history — it leaves the list and the count.
 */
final class AlertsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 22)->setTime(9, 0));
    }

    public function test_that_an_anonymous_caller_is_refused(): void
    {
        $this->getJson('/api/v1/alerts')->assertStatus(401);
        $this->getJson('/api/v1/alerts/unread-count')->assertStatus(401);
        $this->postJson('/api/v1/alerts/read-all')->assertStatus(401);
    }

    /** @return iterable<string, array{RoleName}> */
    public static function everyRole(): iterable
    {
        foreach (RoleName::cases() as $role) {
            yield $role->value => [$role];
        }
    }

    #[DataProvider('everyRole')]
    public function test_that_every_role_reads_its_own_alerts(RoleName $role): void
    {
        $me = $this->user($role);
        $this->alert($me, 'job_failed', 'job-1');

        $this->getJson('/api/v1/alerts/unread-count', $this->bearer($me))->assertOk()->assertJsonPath('data', ['unread' => 1]);
        $this->getJson('/api/v1/alerts', $this->bearer($me))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_that_a_user_never_sees_an_alert_addressed_to_someone_else(): void
    {
        $me = $this->user(RoleName::IndoorSales);
        $other = $this->user(RoleName::IndoorSales);
        $mine = $this->alert($me, 'quotation_expiring', 'q-1');
        $this->alert($other, 'quotation_expiring', 'q-2');

        $auth = $this->bearer($me);
        $this->getJson('/api/v1/alerts', $auth)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine);
        $this->getJson('/api/v1/alerts/unread-count', $auth)->assertJsonPath('data.unread', 1);
    }

    public function test_that_the_list_carries_each_alert_newest_first(): void
    {
        $me = $this->user(RoleName::IndoorSales);
        $older = $this->alert($me, 'quotation_expiring', 'q-1', [
            'details' => json_encode(['number' => 'QT-2026-0018', 'valid_until' => '2026-10-24']),
            'occurrences' => 3,
            'first_raised_at' => '2026-10-20 06:00:00+00',
            'last_raised_at' => '2026-10-21 06:00:00+00',
        ]);
        $newer = $this->alert($me, 'quotation_expiring', 'q-2', ['last_raised_at' => '2026-10-22 06:00:00+00']);

        $this->getJson('/api/v1/alerts', $this->bearer($me))
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer)
            ->assertJsonPath('data.1', [
                'id' => $older,
                'kind' => 'quotation_expiring',
                'subject_id' => 'q-1',
                'details' => ['number' => 'QT-2026-0018', 'valid_until' => '2026-10-24'],
                'occurrences' => 3,
                'first_raised_at' => '2026-10-20T06:00:00+00:00',
                'last_raised_at' => '2026-10-21T06:00:00+00:00',
                'read' => false,
            ])
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.pagination.page', 1);
    }

    public function test_that_a_cleared_alert_leaves_the_list_and_the_count(): void
    {
        $me = $this->user(RoleName::IndoorSales);
        $this->alert($me, 'quotation_expiring', 'q-1', ['cleared_at' => now()]);
        $open = $this->alert($me, 'quotation_expiring', 'q-2');

        $auth = $this->bearer($me);
        $this->getJson('/api/v1/alerts', $auth)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $open);
        $this->getJson('/api/v1/alerts/unread-count', $auth)->assertJsonPath('data.unread', 1);
    }

    public function test_that_the_list_is_paginated(): void
    {
        $me = $this->user(RoleName::IndoorSales);
        foreach (range(1, 30) as $i) {
            $this->alert($me, 'job_failed', "job-{$i}");
        }

        $auth = $this->bearer($me);
        $this->getJson('/api/v1/alerts', $auth)->assertJsonCount(25, 'data')->assertJsonPath('meta.pagination.has_next_page', true);
        $this->getJson('/api/v1/alerts?page=2', $auth)->assertJsonCount(5, 'data')->assertJsonPath('meta.pagination.has_previous_page', true);
    }

    public function test_that_marking_an_alert_read_lowers_the_count_and_keeps_it_listed(): void
    {
        $me = $this->user(RoleName::IndoorSales);
        $first = $this->alert($me, 'quotation_expiring', 'q-1');
        $this->alert($me, 'quotation_expiring', 'q-2');
        $auth = $this->bearer($me);

        $this->postJson("/api/v1/alerts/{$first}/read", [], $auth)->assertOk()->assertJsonPath('data', ['unread' => 1]);
        // Twice is the same as once.
        $this->postJson("/api/v1/alerts/{$first}/read", [], $auth)->assertOk()->assertJsonPath('data', ['unread' => 1]);

        $this->getJson('/api/v1/alerts', $auth)->assertJsonCount(2, 'data');
        self::assertNotNull(DB::table('alerts')->where('id', $first)->value('read_at'));
    }

    public function test_that_another_persons_alert_cannot_be_marked_and_does_not_leak(): void
    {
        $me = $this->user(RoleName::Manager);
        $theirs = $this->alert($this->user(RoleName::IndoorSales), 'quotation_expiring', 'q-1');

        $this->postJson("/api/v1/alerts/{$theirs}/read", [], $this->bearer($me))->assertStatus(404);
        $this->postJson('/api/v1/alerts/'.Str::uuid().'/read', [], $this->bearer($me))->assertStatus(404);
        self::assertNull(DB::table('alerts')->where('id', $theirs)->value('read_at'));
    }

    public function test_that_read_all_marks_only_the_callers_open_alerts(): void
    {
        $me = $this->user(RoleName::IndoorSales);
        $other = $this->user(RoleName::IndoorSales);
        $this->alert($me, 'quotation_expiring', 'q-1');
        $this->alert($me, 'quotation_expiring', 'q-2');
        $theirs = $this->alert($other, 'quotation_expiring', 'q-3');

        $this->postJson('/api/v1/alerts/read-all', [], $this->bearer($me))->assertOk()->assertJsonPath('data', ['unread' => 0]);

        self::assertSame(0, DB::table('alerts')->where('user_id', $me->id)->whereNull('read_at')->count());
        self::assertNull(DB::table('alerts')->where('id', $theirs)->value('read_at'));
    }

    /** D-153: nothing is deleted — there is no route that could. */
    public function test_that_an_alert_cannot_be_deleted(): void
    {
        $me = $this->user(RoleName::SuperAdmin);
        $mine = $this->alert($me, 'job_failed', 'job-1');

        $status = $this->deleteJson("/api/v1/alerts/{$mine}", [], $this->bearer($me))->status();

        self::assertContains($status, [404, 405]);
        self::assertSame(1, DB::table('alerts')->where('id', $mine)->count());
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $overrides */
    private function alert(User $user, string $kind, ?string $subject, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::table('alerts')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'kind' => $kind,
            'subject_id' => $subject,
            'details' => '{}',
            'occurrences' => 1,
            'first_raised_at' => now(),
            'last_raised_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);

        return $id;
    }

    private function user(RoleName $role): User
    {
        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label(),
            'email' => Str::random(8).'@example.test',
            'password' => self::PASSWORD,
            'role_id' => Role::query()->where('slug', $role->value)->firstOrFail()->id,
            'is_active' => true,
            'is_hidden' => $role->isHidden(),
        ]);
        $user->save();

        return $user;
    }

    /** @return array<string, string> */
    private function bearer(User $user): array
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(201)->json('data.token');
        self::assertIsString($token);

        return ['Authorization' => 'Bearer '.$token];
    }
}
