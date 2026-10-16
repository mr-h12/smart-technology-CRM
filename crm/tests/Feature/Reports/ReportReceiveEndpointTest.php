<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 13, slice 2 — the receiving side of a daily report (§3.10, §11.4,
 * `D-127`; the owner's division of 2026-10-16).
 *
 * - "Received" is only what was sent to the caller (a `report_recipients` row),
 *   for the Manager and the CEO alike; `Team` and `Out` wait for teams (`D-129`).
 * - Viewed: a recipient's first open; the author's own open does not count (`D-127`).
 * - Acknowledge and Return are `PATCH`es under `If-Match`, like slice 1's submit.
 * - The receiving facts live on the version row (`report_versions`), per version.
 * - Return → the author edits and resubmits → `report_versions` v2; v1 stays as it was (§11.4).
 */
final class ReportReceiveEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private const ENDPOINT = '/api/v1/reports';

    private const RECEIVED = '/api/v1/reports/received';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(9, 0));
    }

    // ── the received list ───────────────────────────────────────────────────

    public function test_that_the_received_list_shows_only_the_reports_sent_to_the_caller(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $managerA = $this->user(RoleName::Manager, 'a');
        $managerB = $this->user(RoleName::Manager, 'b');
        $mine = $this->submitted($author, [$managerA]);
        $this->submitted($this->user(RoleName::Procurement), [$managerB]);

        $rows = $this->getJson(self::RECEIVED, $this->bearer($managerA))
            ->assertStatus(200)
            ->json('data');

        self::assertIsArray($rows);
        /** @var list<array<string, mixed>> $rows */
        self::assertSame([$mine], array_column($rows, 'id'));
        self::assertSame($author->name, $rows[0]['author_name']);
        self::assertSame('daily', $rows[0]['type']);
        self::assertSame('2026-10-15', $rows[0]['period_start']);
        self::assertSame('2026-10-15', $rows[0]['period_end']);
        self::assertSame('submitted', $rows[0]['status']);
    }

    /** §3.10: the CEO's *view received* is `All`, narrowed by the owner to what was sent to them. */
    public function test_that_the_ceo_lists_and_opens_a_report_sent_to_them(): void
    {
        $ceo = $this->user(RoleName::Ceo);
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$ceo]);

        $listed = $this->getJson(self::RECEIVED, $this->bearer($ceo))->assertStatus(200)->json('data');
        self::assertIsArray($listed);
        self::assertSame([$id], array_column($listed, 'id'));

        $this->getJson(self::RECEIVED.'/'.$id, $this->bearer($ceo))
            ->assertStatus(200)
            ->assertJsonPath('data.id', $id);
    }

    /** §3.10: Sales and Procurement hold no *view received*. */
    public function test_that_sales_and_procurement_have_no_received_list(): void
    {
        foreach ([RoleName::IndoorSales, RoleName::OutdoorSales, RoleName::Procurement] as $role) {
            $this->getJson(self::RECEIVED, $this->bearer($this->user($role)))->assertStatus(403);
        }
    }

    public function test_that_a_report_not_sent_to_the_caller_is_not_found(): void
    {
        $recipient = $this->user(RoleName::Manager, 'a');
        $other = $this->user(RoleName::Manager, 'b');
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$recipient]);

        $this->getJson(self::RECEIVED.'/'.$id, $this->bearer($recipient))->assertStatus(200);
        $this->getJson(self::RECEIVED.'/'.$id, $this->bearer($other))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');
    }

    /** §11.3: a recipient reads what was submitted, not the live row. */
    public function test_that_a_recipient_reads_the_frozen_copy(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->create($author);
        $note = $this->note($author, $id, 'Submitted words.')->assertStatus(201)->json('data.id');
        $this->submit($author, $id, [$manager])->assertStatus(200);
        DB::table('report_notes')->where('id', $note)->update(['body' => 'Changed afterwards.']);

        $this->getJson(self::RECEIVED.'/'.$id, $this->bearer($manager))
            ->assertStatus(200)
            ->assertJsonPath('data.notes.0.body', 'Submitted words.');
    }

    // ── viewed ──────────────────────────────────────────────────────────────

    /** `D-127`: Submitted → Viewed the first time a recipient opens it; who and when are recorded. */
    public function test_that_a_recipients_first_open_marks_the_report_viewed(): void
    {
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$manager]);

        $this->getJson(self::RECEIVED.'/'.$id, $this->bearer($manager))
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'viewed');

        self::assertSame('viewed', DB::table('reports')->where('id', $id)->value('status'));
        $version = $this->version($id, 1);
        self::assertSame($manager->id, $version->viewed_by);
        self::assertNotNull($version->viewed_at);
        $this->assertAudited('REPORT_VIEWED', $id, $manager);
    }

    public function test_that_a_second_open_changes_nothing(): void
    {
        $managerA = $this->user(RoleName::Manager, 'a');
        $managerB = $this->user(RoleName::Manager, 'b');
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$managerA, $managerB]);
        $this->getJson(self::RECEIVED.'/'.$id, $this->bearer($managerA))->assertStatus(200);
        $first = $this->version($id, 1);

        $this->travel(1)->hours();
        $this->getJson(self::RECEIVED.'/'.$id, $this->bearer($managerA))->assertStatus(200);
        $this->getJson(self::RECEIVED.'/'.$id, $this->bearer($managerB))->assertStatus(200);

        $again = $this->version($id, 1);
        self::assertSame($managerA->id, $again->viewed_by);
        self::assertEquals($first->viewed_at, $again->viewed_at);
        self::assertSame(1, DB::table('audit_log')->where('event', 'REPORT_VIEWED')->where('entity_id', $id)->count());
    }

    /** `D-127`: "The author opening it does not count." */
    public function test_that_the_authors_own_open_does_not_mark_it_viewed(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $id = $this->submitted($author, [$this->user(RoleName::Manager)]);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))->assertStatus(200);

        self::assertSame('submitted', DB::table('reports')->where('id', $id)->value('status'));
        self::assertNull($this->version($id, 1)->viewed_by);
    }

    // ── acknowledge ─────────────────────────────────────────────────────────

    public function test_that_a_recipient_acknowledges_a_submitted_report(): void
    {
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$manager]);

        $this->acknowledge($manager, $id)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'acknowledged');

        self::assertSame('acknowledged', DB::table('reports')->where('id', $id)->value('status'));
        $version = $this->version($id, 1);
        self::assertSame($manager->id, $version->acknowledged_by);
        self::assertNotNull($version->acknowledged_at);
        $this->assertAudited('REPORT_ACKNOWLEDGED', $id, $manager);
    }

    public function test_that_a_viewed_report_can_be_acknowledged(): void
    {
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$manager]);
        $this->getJson(self::RECEIVED.'/'.$id, $this->bearer($manager))->assertStatus(200);

        $this->acknowledge($manager, $id)->assertStatus(200)->assertJsonPath('data.status', 'acknowledged');
    }

    /** §11.4: "After Acknowledged the report is permanently read-only, even for its author." */
    public function test_that_an_acknowledged_report_is_read_only_even_for_its_author(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($author, [$manager]);
        $this->acknowledge($manager, $id)->assertStatus(200);

        // `D-131`: a note route answers 409 (the two-answers debt row in CHECKLIST.md).
        $this->note($author, $id, 'Too late.')
            ->assertStatus(409)
            ->assertJsonPath('error.details.0.code', 'report_not_editable');
        $this->submit($author, $id, [$manager])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'business_rule_blocked')
            ->assertJsonPath('error.details.0.code', 'report_not_draft');
        $this->returnReport($manager, $id, 'Changed my mind.')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'business_rule_blocked');
        $this->acknowledge($manager, $id)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'business_rule_blocked');

        self::assertSame('acknowledged', DB::table('reports')->where('id', $id)->value('status'));
        self::assertSame(1, DB::table('report_versions')->where('report_id', $id)->count());
        self::assertNull($this->version($id, 1)->returned_by);
    }

    public function test_that_a_stale_acknowledge_is_a_409(): void
    {
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$manager]);
        $current = $this->etag($id);

        $this->acknowledge($manager, $id, 'report:'.$id.':1')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'concurrency_conflict')
            ->assertJsonPath('error.details.0.current_etag', $current);

        self::assertSame('submitted', DB::table('reports')->where('id', $id)->value('status'));
    }

    public function test_that_an_acknowledge_without_if_match_is_a_400(): void
    {
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$manager]);

        $this->patchJson(self::ENDPOINT.'/'.$id.'/acknowledge', [], $this->bearer($manager))
            ->assertStatus(400)
            ->assertJsonPath('error.details.0.field', 'If-Match');
    }

    // ── return ──────────────────────────────────────────────────────────────

    /** §11.4: Returned carries a mandatory reason. */
    public function test_that_a_return_without_a_reason_is_refused(): void
    {
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$manager]);

        foreach ([null, '', '   '] as $reason) {
            $this->returnReport($manager, $id, $reason)
                ->assertStatus(422)
                ->assertJsonPath('error.details.0.field', 'reason');
        }

        self::assertSame('submitted', DB::table('reports')->where('id', $id)->value('status'));
        self::assertNull($this->version($id, 1)->returned_by);
    }

    public function test_that_a_recipient_returns_a_report_with_a_reason(): void
    {
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$manager]);

        $this->returnReport($manager, $id, 'Add the visit to Delta.')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'returned');

        self::assertSame('returned', DB::table('reports')->where('id', $id)->value('status'));
        $version = $this->version($id, 1);
        self::assertSame($manager->id, $version->returned_by);
        self::assertNotNull($version->returned_at);
        self::assertSame('Add the visit to Delta.', $version->return_reason);
        $this->assertAudited('REPORT_RETURNED', $id, $manager);
    }

    /**
     * §11.4: Returned → an editable v2 that starts from v1's notes and
     * recipients (the owner, 2026-10-16); v1's frozen copy and its reason stay.
     */
    public function test_that_a_returned_report_is_edited_and_resubmitted_as_version_2(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->create($author);
        $note = $this->note($author, $id, 'First words.')->assertStatus(201)->json('data.id');
        self::assertIsString($note);
        $this->submit($author, $id, [$manager])->assertStatus(200);
        $this->returnReport($manager, $id, 'Add the visit to Delta.')->assertStatus(200);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'returned')
            ->assertJsonPath('data.notes.0.body', 'First words.')
            ->assertJsonPath('data.recipients.0.id', $manager->id);

        $this->patchJson(self::ENDPOINT.'/'.$id.'/notes/'.$note, ['body' => 'Second words.'], $this->bearer($author))->assertStatus(200);
        $this->submit($author, $id, [$manager])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'submitted');

        self::assertSame('submitted', DB::table('reports')->where('id', $id)->value('status'));
        self::assertSame([$manager->id], DB::table('report_recipients')->where('report_id', $id)->whereNull('deleted_at')->pluck('user_id')->all());

        $v1 = $this->version($id, 1);
        self::assertEquals([['body' => 'First words.']], $this->snapshotOf($v1)['notes']);
        self::assertSame('Add the visit to Delta.', $v1->return_reason);
        self::assertSame($manager->id, $v1->returned_by);

        $v2 = $this->version($id, 2);
        self::assertEquals([['body' => 'Second words.']], $this->snapshotOf($v2)['notes']);
        self::assertNull($v2->returned_by);
        self::assertNull($v2->viewed_by);
    }

    public function test_that_a_stale_return_is_a_409(): void
    {
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$manager]);

        $this->returnReport($manager, $id, 'Reason.', 'report:'.$id.':1')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'concurrency_conflict');

        self::assertSame('submitted', DB::table('reports')->where('id', $id)->value('status'));
    }

    // ── refusals ────────────────────────────────────────────────────────────

    /** The recipient succeeds where the non-recipient is answered 404 — otherwise a missing route passes this. */
    public function test_that_only_a_recipient_may_acknowledge_or_return(): void
    {
        $recipient = $this->user(RoleName::Manager, 'a');
        $other = $this->user(RoleName::Manager, 'b');
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$recipient]);

        $this->acknowledge($other, $id)->assertStatus(404);
        $this->returnReport($other, $id, 'Not mine to return.')->assertStatus(404);
        self::assertSame('submitted', DB::table('reports')->where('id', $id)->value('status'));

        $this->acknowledge($recipient, $id)->assertStatus(200);
    }

    /** §3.10: the CEO views received reports but holds no acknowledge and no return. */
    public function test_that_the_ceo_cannot_acknowledge_or_return(): void
    {
        $ceo = $this->user(RoleName::Ceo);
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$ceo]);

        $this->acknowledge($ceo, $id)->assertStatus(403);
        $this->returnReport($ceo, $id, 'Not allowed.')->assertStatus(403);

        self::assertSame('submitted', DB::table('reports')->where('id', $id)->value('status'));
        self::assertNull($this->version($id, 1)->acknowledged_by);
    }

    /** §3.10: Sales and Procurement hold neither action — on their own report or anyone else's. */
    public function test_that_an_author_cannot_acknowledge_or_return(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $colleague = $this->user(RoleName::Procurement);
        $id = $this->submitted($author, [$this->user(RoleName::Manager)]);

        foreach ([$author, $colleague] as $caller) {
            $this->acknowledge($caller, $id)->assertStatus(403);
            $this->returnReport($caller, $id, 'Nope.')->assertStatus(403);
        }

        self::assertSame('submitted', DB::table('reports')->where('id', $id)->value('status'));
    }

    public function test_that_an_unauthenticated_caller_is_refused(): void
    {
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$this->user(RoleName::Manager)]);
        // The request guard keeps the user it resolved for the sign-ins above; drop it.
        $this->app['auth']->forgetGuards();

        $this->getJson(self::RECEIVED)->assertStatus(401);
        $this->getJson(self::RECEIVED.'/'.$id)->assertStatus(401);
        $this->patchJson(self::ENDPOINT.'/'.$id.'/acknowledge', [], ['If-Match' => 'report:'.$id.':2'])->assertStatus(401);
        $this->patchJson(self::ENDPOINT.'/'.$id.'/return', ['reason' => 'x'], ['If-Match' => 'report:'.$id.':2'])->assertStatus(401);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function assertAudited(string $event, string $id, User $user): void
    {
        $entry = DB::table('audit_log')->where('event', $event)->where('entity_id', $id)->first();
        self::assertNotNull($entry, "AUD-01: no {$event} entry.");
        self::assertSame($user->id, $entry->user_id);
    }

    /**
     * @return object{
     *     snapshot: string, viewed_by: ?string, viewed_at: ?string, acknowledged_by: ?string,
     *     acknowledged_at: ?string, returned_by: ?string, returned_at: ?string, return_reason: ?string
     * }
     */
    private function version(string $id, int $version): object
    {
        /**
         * @var object{
         *     snapshot: string, viewed_by: ?string, viewed_at: ?string, acknowledged_by: ?string,
         *     acknowledged_at: ?string, returned_by: ?string, returned_at: ?string, return_reason: ?string
         * }|null $row
         */
        $row = DB::table('report_versions')->where('report_id', $id)->where('version', $version)->first();
        self::assertNotNull($row, "report_versions has no v{$version}.");

        return $row;
    }

    /** @return array<string, mixed> */
    private function snapshotOf(object $version): array
    {
        self::assertIsString($version->snapshot ?? null);
        $snapshot = json_decode($version->snapshot, true);
        self::assertIsArray($snapshot);

        /** @var array<string, mixed> $snapshot */
        return $snapshot;
    }

    /** The current token, read from the row — whether an open advances it is the implementation's call. */
    private function etag(string $id): string
    {
        $token = DB::table('reports')->where('id', $id)->value('version_token');
        self::assertIsInt($token);

        return 'report:'.$id.':'.$token;
    }

    /** @return TestResponse<\Illuminate\Http\JsonResponse> */
    private function acknowledge(User $caller, string $id, ?string $ifMatch = null): TestResponse
    {
        return $this->patchJson(self::ENDPOINT.'/'.$id.'/acknowledge', [], [...$this->bearer($caller), 'If-Match' => $ifMatch ?? $this->etag($id)]);
    }

    /** @return TestResponse<\Illuminate\Http\JsonResponse> */
    private function returnReport(User $caller, string $id, ?string $reason, ?string $ifMatch = null): TestResponse
    {
        return $this->patchJson(
            self::ENDPOINT.'/'.$id.'/return',
            $reason === null ? [] : ['reason' => $reason],
            [...$this->bearer($caller), 'If-Match' => $ifMatch ?? $this->etag($id)],
        );
    }

    /**
     * `D-131`: a note, through its own route.
     *
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function note(User $author, string $id, string $body): TestResponse
    {
        return $this->postJson(self::ENDPOINT.'/'.$id.'/notes', ['body' => $body], $this->bearer($author));
    }

    /**
     * @param  list<User>  $recipients
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function submit(User $author, string $id, array $recipients): TestResponse
    {
        return $this->patchJson(
            self::ENDPOINT.'/'.$id.'/submit',
            ['recipient_ids' => array_map(static fn (User $u): string => $u->id, $recipients)],
            [...$this->bearer($author), 'If-Match' => $this->etag($id)],
        );
    }

    /** @param list<User> $recipients */
    private function submitted(User $author, array $recipients): string
    {
        $id = $this->create($author);
        $this->submit($author, $id, $recipients)->assertStatus(200);

        return $id;
    }

    private function create(User $author): string
    {
        $id = $this->postJson(self::ENDPOINT, ['type' => 'daily'], $this->bearer($author))
            ->assertStatus(201)
            ->json('data.id');
        self::assertIsString($id);

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
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(201)->json('data.token');
        self::assertIsString($token);

        return ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => Uuid::uuid4()->toString()];
    }
}
