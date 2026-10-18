<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 13, slice 3 · 3.3 — `D-133` (amends `D-131`): `GET /customers/{customer}/follow-ups`.
 *
 * For each report, the follow-ups of its **latest submitted version** whose customer is
 * this one: never a draft's live rows, a returned report's frozen copy until it is
 * resubmitted, and no duplicates after a resubmit. Each item carries `note`,
 * `author_name` and `followed_up_at` — the moment the row was first saved, never the
 * submit time — newest first. Read under `customer.view` at the customer's own row
 * scope, as for customer notes: out of reach or unknown is the parent's `404`.
 */
final class CustomerFollowUpsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private const REPORTS = '/api/v1/reports';

    /** @var array<string, string> one login per user — login is limited to 10 a minute per user */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00:00', 'UTC'));
    }

    /** `D-133`: a submitted report's follow-ups show, read-only, newest first, with date, author and note. */
    public function test_that_a_submitted_reports_follow_ups_show_newest_first(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $nile = $this->customer('Nile Trading', $author);
        $id = $this->create($author);

        $this->addFollowUp($author, $id, $nile, 'Older.');
        $this->travel(5)->minutes();
        $this->addFollowUp($author, $id, $nile, 'Newer.');
        $this->submit($author, $id, [$manager])->assertStatus(200);

        $response = $this->getJson($this->followUps($nile), $this->bearer($author))
            ->assertStatus(200)
            ->assertJsonPath('data.0.note', 'Newer.')
            ->assertJsonPath('data.0.author_name', $author->name)
            ->assertJsonPath('data.1.note', 'Older.');

        self::assertSame(['Newer.', 'Older.'], $response->json('data.*.note'));
        $this->assertMoment('2026-10-15 09:05:00', $response->json('data.0.followed_up_at'));
        $this->assertMoment('2026-10-15 09:00:00', $response->json('data.1.followed_up_at'));
    }

    /** `D-133`: a draft's live rows never show — a never-submitted draft shows nothing. */
    public function test_that_a_drafts_follow_ups_never_show(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $nile = $this->customer('Nile Trading', $author);
        $id = $this->create($author);
        $this->addFollowUp($author, $id, $nile, 'Still a draft.');

        $this->getJson($this->followUps($nile), $this->bearer($author))
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    /**
     * `D-133`: a returned report keeps showing its last submitted version's frozen row,
     * even after the author edits the live row; after a resubmit only the new
     * version's rows show, never both.
     */
    public function test_that_a_returned_report_shows_its_last_submitted_version_then_only_the_resubmitted_one(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $nile = $this->customer('Nile Trading', $author);
        $id = $this->create($author);
        $row = $this->addFollowUp($author, $id, $nile, 'Before submit.');
        $this->submit($author, $id, [$manager])->assertStatus(200);

        $this->patchJson(self::REPORTS.'/'.$id.'/return', ['reason' => 'Add detail.'], [...$this->bearer($manager), 'If-Match' => $this->etag($id)])
            ->assertStatus(200);
        $this->patchJson(self::REPORTS.'/'.$id.'/follow-ups/'.$row, ['note' => 'Live edit.'], $this->bearer($author))
            ->assertStatus(200);

        $returned = $this->getJson($this->followUps($nile), $this->bearer($author))->assertStatus(200);
        self::assertSame(['Before submit.'], $returned->json('data.*.note'));

        $this->submit($author, $id, [$manager])->assertStatus(200);

        $resubmitted = $this->getJson($this->followUps($nile), $this->bearer($author))->assertStatus(200);
        self::assertSame(['Live edit.'], $resubmitted->json('data.*.note'));
    }

    /** `D-133`: the date is the moment the row was first saved — not its last edit, not the submit. */
    public function test_that_the_date_is_the_first_save_not_the_submit(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $nile = $this->customer('Nile Trading', $author);
        $id = $this->create($author);
        $row = $this->addFollowUp($author, $id, $nile, 'First wording.');

        $this->travel(1)->hours();
        $this->patchJson(self::REPORTS.'/'.$id.'/follow-ups/'.$row, ['note' => 'Second wording.'], $this->bearer($author))
            ->assertStatus(200);
        $this->travel(1)->hours();
        $this->submit($author, $id, [$manager])->assertStatus(200);

        $response = $this->getJson($this->followUps($nile), $this->bearer($author))
            ->assertStatus(200)
            ->assertJsonPath('data.0.note', 'Second wording.');

        $this->assertMoment('2026-10-15 09:00:00', $response->json('data.0.followed_up_at'));
    }

    /**
     * `D-133`: whoever can view the customer under `customer.view` at its row scope sees
     * them; anyone else — and an unknown id — gets the parent's 404, as for customer notes.
     * The owner's 200 first, so a missing route cannot pass this.
     */
    public function test_that_a_customer_out_of_scope_is_404(): void
    {
        $owner = $this->user(RoleName::IndoorSales, 'a');
        $outsider = $this->user(RoleName::IndoorSales, 'b');
        $manager = $this->user(RoleName::Manager);
        $nile = $this->customer('Nile Trading', $owner);
        $id = $this->create($owner);
        $this->addFollowUp($owner, $id, $nile, 'Private.');
        $this->submit($owner, $id, [$manager])->assertStatus(200);

        $this->getJson($this->followUps($nile), $this->bearer($owner))
            ->assertStatus(200)
            ->assertJsonPath('data.0.note', 'Private.');

        $this->getJson($this->followUps($nile), $this->bearer($outsider))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
        $this->getJson($this->followUps((string) Str::uuid7()), $this->bearer($owner))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    /** `D-133` · `SEC-07`: a role with no `customer.view` grant is refused with 403, not shown an empty list. */
    public function test_that_a_role_without_customer_view_is_refused(): void
    {
        $owner = $this->user(RoleName::IndoorSales);
        $nile = $this->customer('Nile Trading', $owner);

        $role = new Role;
        $role->fill(['slug' => 'auditor_without_grants', 'name' => 'Auditor', 'is_editable' => true]);
        $role->save();
        $grantless = new User;
        $grantless->fill([
            'name' => 'Grantless', 'email' => 'grantless@example.test', 'password' => self::PASSWORD,
            'role_id' => $role->id, 'is_active' => true, 'is_hidden' => false,
        ]);
        $grantless->save();

        $this->getJson($this->followUps($nile), $this->bearer($grantless))->assertStatus(403);
    }

    /** `D-133` · `SEC-07`: no token, no follow-ups. */
    public function test_that_an_unauthenticated_caller_is_refused(): void
    {
        $this->getJson($this->followUps((string) Str::uuid7()))->assertStatus(401);
    }

    /**
     * `D-133` · `SEC-07`: a caller whose customer scope does not reach the owner (a Team
     * Leader the owner is not a member of, `D-138`) gets the parent's 404 even on a
     * customer with a submitted follow-up. The owner's 200 first, so a missing route
     * cannot pass this.
     */
    public function test_that_a_team_leader_outside_the_owners_team_gets_404(): void
    {
        $owner = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $teamLeader = $this->user(RoleName::TeamLeader);
        $nile = $this->customer('Nile Trading', $owner);
        $id = $this->create($owner);
        $this->addFollowUp($owner, $id, $nile, 'Visible to the owner only.');
        $this->submit($owner, $id, [$manager])->assertStatus(200);

        $this->getJson($this->followUps($nile), $this->bearer($owner))->assertStatus(200)->assertJsonCount(1, 'data');

        $this->getJson($this->followUps($nile), $this->bearer($teamLeader))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    /** `D-133`: only the follow-ups whose customer is this one. */
    public function test_that_a_follow_up_on_another_customer_does_not_show(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $nile = $this->customer('Nile Trading', $author);
        $delta = $this->customer('Delta Foods', $author);
        $id = $this->create($author);
        $this->addFollowUp($author, $id, $nile, 'About Nile.');
        $this->addFollowUp($author, $id, $delta, 'About Delta.');
        $this->submit($author, $id, [$manager])->assertStatus(200);

        $response = $this->getJson($this->followUps($nile), $this->bearer($author))->assertStatus(200);
        self::assertSame(['About Nile.'], $response->json('data.*.note'));
    }

    /**
     * `D-133`: a version frozen before 3.3 has no per-row date; its rows fall back to
     * the version's submit time. The snapshot is written directly, as 3.2 froze it.
     */
    public function test_that_an_old_snapshot_without_a_row_date_falls_back_to_the_submit_time(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $nile = $this->customer('Nile Trading', $author);
        $submittedAt = CarbonImmutable::parse('2026-10-14 16:30:00', 'UTC');
        $reportId = (string) Str::uuid7();

        DB::table('reports')->insert([
            'id' => $reportId, 'code' => 'RPT-DY-OLD-0001', 'type' => 'daily', 'status' => 'submitted',
            'author_id' => $author->id, 'period_start' => '2026-10-14', 'period_end' => '2026-10-14',
            'version_token' => 2, 'submitted_at' => $submittedAt,
            'created_by' => $author->id, 'updated_by' => $author->id, 'created_at' => $submittedAt, 'updated_at' => $submittedAt,
        ]);
        DB::table('report_versions')->insert([
            'id' => (string) Str::uuid7(), 'report_id' => $reportId, 'version' => 1,
            'snapshot' => json_encode([
                'follow_ups' => [['customer_id' => $nile, 'customer_name' => 'Nile Trading', 'note' => 'Frozen before 3.3.']],
                'notes' => [],
                'activity' => [],
            ], JSON_THROW_ON_ERROR),
            'created_by' => $author->id, 'updated_by' => $author->id, 'created_at' => $submittedAt, 'updated_at' => $submittedAt,
        ]);

        $response = $this->getJson($this->followUps($nile), $this->bearer($author))
            ->assertStatus(200)
            ->assertJsonPath('data.0.note', 'Frozen before 3.3.')
            ->assertJsonPath('data.0.author_name', $author->name);

        $this->assertMoment('2026-10-14 16:30:00', $response->json('data.0.followed_up_at'));
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function assertMoment(string $expectedUtc, mixed $actual): void
    {
        self::assertIsString($actual, 'followed_up_at is missing.');
        self::assertTrue(
            CarbonImmutable::parse($expectedUtc, 'UTC')->equalTo(CarbonImmutable::parse($actual)),
            "followed_up_at {$actual} is not {$expectedUtc} UTC.",
        );
    }

    private function followUps(string $customerId): string
    {
        return '/api/v1/customers/'.$customerId.'/follow-ups';
    }

    private function addFollowUp(User $author, string $reportId, string $customerId, string $note): string
    {
        $row = $this->postJson(self::REPORTS.'/'.$reportId.'/follow-ups', ['customer_id' => $customerId, 'note' => $note], $this->bearer($author))
            ->assertStatus(201)
            ->json('data.id');
        self::assertIsString($row);

        return $row;
    }

    private function etag(string $id): string
    {
        $token = DB::table('reports')->where('id', $id)->value('version_token');
        self::assertIsInt($token);

        return 'report:'.$id.':'.$token;
    }

    /**
     * @param  list<User>  $recipients
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function submit(User $author, string $id, array $recipients): TestResponse
    {
        return $this->patchJson(
            self::REPORTS.'/'.$id.'/submit',
            ['recipient_ids' => array_map(static fn (User $u): string => $u->id, $recipients)],
            [...$this->bearer($author), 'If-Match' => $this->etag($id)],
        );
    }

    private function create(User $author): string
    {
        $id = $this->postJson(self::REPORTS, ['type' => 'daily'], $this->bearer($author))
            ->assertStatus(201)
            ->json('data.id');
        self::assertIsString($id);

        return $id;
    }

    private function customer(string $name, User $owner): string
    {
        $id = (string) Str::uuid7();
        DB::table('customers')->insert([
            'id' => $id, 'name' => $name, 'customer_status' => 'prospect', 'sales_owner_id' => $owner->id,
            'is_archived' => false, 'is_incomplete' => false, 'created_by' => $owner->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function user(RoleName $role, string $tag = ''): User
    {
        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label().($tag !== '' ? ' '.$tag : ''),
            'email' => str_replace('_', '.', $role->value).($tag !== '' ? '.'.$tag : '').'@example.test',
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
        if (! isset($this->tokens[$user->id])) {
            $token = $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => self::PASSWORD,
            ])->assertStatus(201)->json('data.token');
            self::assertIsString($token);
            $this->tokens[$user->id] = $token;
        }

        return ['Authorization' => 'Bearer '.$this->tokens[$user->id], 'Idempotency-Key' => Uuid::uuid4()->toString()];
    }
}
