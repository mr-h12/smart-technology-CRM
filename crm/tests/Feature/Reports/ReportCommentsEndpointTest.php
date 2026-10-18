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
 * Module 13, slice 7 · 7.3 — comments on a report (§3.10 *comment*, `D-24`, `D-127`;
 * the owner's rulings of 2026-10-18).
 *
 * - Only a LIVE RECIPIENT holding `report.comment` may add one (Manager, Team Leader,
 *   Outdoor Supervisor, CEO). The author reads comments and cannot add.
 * - One flat list, oldest first (`created_at`, then `id`); no edit, no remove; each add audited.
 * - Allowed at any status, Acknowledged and Archived included: a comment never changes the report.
 * - The CEO comments as a recipient; acknowledge and return stay refused for the CEO
 *   (`ReportReceiveEndpointTest::test_that_the_ceo_cannot_acknowledge_or_return`).
 * - A refusal writes no row and no audit entry. Every refusal test runs after a positive control
 *   on the same route, so a missing route cannot pass for the rule.
 */
final class ReportCommentsEndpointTest extends TestCase
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

    // ── add ─────────────────────────────────────────────────────────────────

    public function test_that_a_recipient_comments_and_the_reply_names_the_comment(): void
    {
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$manager]);

        $response = $this->comment($manager, $id, 'Please add the visit to Delta.')
            ->assertStatus(201)
            ->assertJsonPath('data.body', 'Please add the visit to Delta.')
            ->assertJsonPath('data.author', ['id' => $manager->id, 'name' => $manager->name])
            ->assertJsonPath('data.created_at', '2026-10-15T09:00:00+00:00');
        $comment = $response->json('data.id');
        self::assertIsString($comment);

        $row = DB::table('report_comments')->where('id', $comment)->first();
        self::assertNotNull($row);
        self::assertSame($id, $row->report_id);
        self::assertSame('Please add the visit to Delta.', $row->body);
        self::assertSame($manager->id, $row->created_by, 'The comment\'s author is the row\'s created_by.');
        self::assertNull($row->deleted_at);
    }

    /** `D-127`: every comment is audited — entity the report, the comment id and body in `new_values`. */
    public function test_that_each_comment_is_audited(): void
    {
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$manager]);

        $first = $this->commentId($manager, $id, 'First.');
        $second = $this->commentId($manager, $id, 'Second.');

        $entries = DB::table('audit_log')->where('event', 'REPORT_COMMENT_ADDED')->orderBy('created_at')->orderBy('id')->get();
        self::assertCount(2, $entries, 'AUD-01: one REPORT_COMMENT_ADDED entry per comment.');
        $seen = [];
        foreach ($entries as $entry) {
            self::assertSame('report', $entry->entity_type);
            self::assertSame($id, $entry->entity_id);
            self::assertSame($manager->id, $entry->user_id);
            self::assertNull($entry->old_values);
            /** @var string $stored the audit column is JSON text */
            $stored = $entry->new_values;
            $new = json_decode($stored, true);
            self::assertIsArray($new);
            /** @var array{comment_id?: string, body?: string} $new */
            $seen[(string) ($new['comment_id'] ?? '')] = $new['body'] ?? null;
        }
        self::assertSame([$first => 'First.', $second => 'Second.'], $seen);
    }

    /** `D-127`: Submitted, Viewed, Acknowledged and Archived all accept a comment, and none of them moves. */
    public function test_that_a_comment_is_allowed_at_any_status_and_never_changes_the_report(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);

        foreach (['submitted', 'viewed', 'acknowledged', 'archived'] as $status) {
            $id = $this->reportAt($status, $author, $manager);
            $before = $this->reportFacts($id);

            $this->comment($manager, $id, "A comment on a {$status} report.")->assertStatus(201);

            self::assertSame($before, $this->reportFacts($id), "A comment changed a {$status} report (D-127: it never does).");
            self::assertSame($status, DB::table('reports')->where('id', $id)->value('status'));
        }

        self::assertSame(4, DB::table('report_comments')->count());
    }

    public function test_that_each_recipient_role_holding_the_comment_grant_may_comment(): void
    {
        $author = $this->user(RoleName::OutdoorSales);
        $recipients = [
            $this->user(RoleName::Manager),
            $this->user(RoleName::OutdoorSupervisor),
            $this->user(RoleName::Ceo),
        ];
        $id = $this->submitted($author, $recipients);

        foreach ($recipients as $recipient) {
            $this->comment($recipient, $id, 'Comment from '.$recipient->name)->assertStatus(201);
        }

        self::assertSame(3, DB::table('report_comments')->where('report_id', $id)->count());
    }

    /** §3.10 and `D-24`: the CEO's one exception — a comment — as a recipient. Its refused answers: `ReportReceiveEndpointTest`. */
    public function test_that_the_ceo_comments_on_a_report_sent_to_them(): void
    {
        $ceo = $this->user(RoleName::Ceo);
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$ceo]);

        $this->comment($ceo, $id, 'Noted.')
            ->assertStatus(201)
            ->assertJsonPath('data.author.id', $ceo->id);

        self::assertSame($ceo->id, DB::table('report_comments')->where('report_id', $id)->value('created_by'));
        // The CEO still holds no answer: the report is as the comment found it.
        $this->patchJson(self::ENDPOINT.'/'.$id.'/acknowledge', [], [...$this->bearer($ceo), 'If-Match' => $this->etag($id)])->assertStatus(403);
        self::assertSame('submitted', DB::table('reports')->where('id', $id)->value('status'));
    }

    // ── validation ──────────────────────────────────────────────────────────

    public function test_that_a_blank_missing_or_long_body_is_422_and_writes_nothing(): void
    {
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$manager]);

        foreach ([[], ['body' => ''], ['body' => '   '], ['body' => str_repeat('a', 5001)], ['body' => ['not', 'text']]] as $payload) {
            $this->postJson(self::ENDPOINT.'/'.$id.'/comments', $payload, $this->bearer($manager))
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonPath('error.details.0.field', 'body');
        }
        $this->assertNothingWritten(0);

        // 5000 is the note's limit and the comment's: the boundary is accepted.
        $this->comment($manager, $id, str_repeat('a', 5000))->assertStatus(201);
        $this->assertNothingWritten(1);
    }

    // ── refusals ────────────────────────────────────────────────────────────

    /** A Manager or CEO the report was NOT sent to is answered 404, as the other received routes do. */
    public function test_that_a_comment_holder_who_is_not_a_recipient_is_not_found(): void
    {
        $recipient = $this->user(RoleName::Manager, 'a');
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$recipient]);
        $this->comment($recipient, $id, 'Mine to make.')->assertStatus(201);

        foreach ([$this->user(RoleName::Manager, 'b'), $this->user(RoleName::Ceo), $this->user(RoleName::OutdoorSupervisor)] as $outsider) {
            $this->comment($outsider, $id, 'Not mine.')
                ->assertStatus(404)
                ->assertJsonPath('error.code', 'resource_not_found');
        }

        $this->assertNothingWritten(1);
    }

    public function test_that_a_removed_recipient_can_no_longer_comment(): void
    {
        $kept = $this->user(RoleName::Manager, 'kept');
        $removed = $this->user(RoleName::Manager, 'removed');
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$kept, $removed]);
        $this->comment($removed, $id, 'Before I was removed.')->assertStatus(201);

        DB::table('report_recipients')->where('report_id', $id)->where('user_id', $removed->id)->update(['deleted_at' => now()]);

        $this->comment($removed, $id, 'After.')->assertStatus(404);
        $this->comment($kept, $id, 'Still here.')->assertStatus(201);
        $this->assertNothingWritten(2);
    }

    /** Owner ruling 2026-10-18: the author reads comments and cannot add. §3.10: Sales and Procurement hold no *comment*. */
    public function test_that_a_user_without_the_comment_grant_is_refused(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($author, [$manager]);
        $this->comment($manager, $id, 'The control.')->assertStatus(201);

        foreach ([$author, $this->user(RoleName::Procurement), $this->user(RoleName::OutdoorSales)] as $caller) {
            $this->comment($caller, $id, 'Not allowed.')->assertStatus(403);
        }

        $this->assertNothingWritten(1);
    }

    /** Recipients cannot reach a draft: not even a Manager who holds the grant. */
    public function test_that_a_draft_cannot_be_commented_on(): void
    {
        $manager = $this->user(RoleName::Manager);
        $draft = $this->create($this->user(RoleName::IndoorSales));
        $submitted = $this->submitted($this->user(RoleName::Procurement), [$manager]);
        $this->comment($manager, $submitted, 'The control.')->assertStatus(201);

        $this->comment($manager, $draft, 'On a draft.')->assertStatus(404);

        $this->assertNothingWritten(1);
    }

    /**
     * Owner ruling 2026-10-18 for authors that DO hold `report.comment` (Manager, Team Leader, Outdoor
     * Supervisor): holding the grant is not being a recipient. A report is never sent to its own author
     * (`RecipientCandidates`), so `ReportAccess::reachReceived` answers 404 — the grant alone opens nothing.
     */
    public function test_that_an_author_who_holds_the_comment_grant_cannot_comment_on_their_own_report(): void
    {
        foreach ([RoleName::Manager, RoleName::TeamLeader, RoleName::OutdoorSupervisor] as $n => $role) {
            $author = $this->user($role, 'author');
            $recipient = $this->user(RoleName::Ceo, $role->value);
            $id = $this->submitted($author, [$recipient]);

            // The control: a live recipient comments on this very report.
            $this->comment($recipient, $id, 'The control.')->assertStatus(201);

            $this->comment($author, $id, 'On my own report.')
                ->assertStatus(404)
                ->assertJsonPath('error.code', 'resource_not_found');
            $this->assertNothingWritten($n + 1);
        }
    }

    public function test_that_an_unauthenticated_caller_is_refused(): void
    {
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$manager]);
        $this->comment($manager, $id, 'The control.')->assertStatus(201);
        $this->app['auth']->forgetGuards();

        $this->postJson(self::ENDPOINT.'/'.$id.'/comments', ['body' => 'Anonymous.'])->assertStatus(401);

        $this->assertNothingWritten(1);
    }

    // ── append-only ─────────────────────────────────────────────────────────

    /** `D-127`: a comment cannot be edited or removed — there is no route to do either (404 or 405). */
    public function test_that_a_comment_cannot_be_edited_or_removed(): void
    {
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$manager]);
        $comment = $this->commentId($manager, $id, 'Final words.');
        $before = (array) DB::table('report_comments')->where('id', $comment)->first();
        $url = self::ENDPOINT.'/'.$id.'/comments/'.$comment;

        $answers = [
            $this->patchJson($url, ['body' => 'Changed.'], $this->bearer($manager))->getStatusCode(),
            $this->putJson($url, ['body' => 'Changed.'], $this->bearer($manager))->getStatusCode(),
            $this->deleteJson($url, [], $this->bearer($manager))->getStatusCode(),
        ];
        foreach ($answers as $status) {
            self::assertContains($status, [404, 405], 'D-127: no edit and no remove route exists.');
        }

        self::assertSame($before, (array) DB::table('report_comments')->where('id', $comment)->first(), 'The comment row changed.');
        $this->assertNothingWritten(1);
    }

    // ── reading ─────────────────────────────────────────────────────────────

    /**
     * The author's page and a recipient's page carry the same list: oldest first by
     * `created_at`, ties by `id`, each with its author's `{id, name}` and its time.
     */
    public function test_that_both_pages_list_the_comments_oldest_first(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $ceo = $this->user(RoleName::Ceo);
        $id = $this->submitted($author, [$manager, $ceo]);

        // Inserted out of order on purpose; B and C share a second, so `id` breaks the tie.
        $late = '99999999-9999-7999-8999-999999999999';
        $tieA = '00000000-0000-7000-8000-000000000001';
        $tieB = '00000000-0000-7000-8000-000000000002';
        $this->insertComment($late, $id, $ceo, 'Last in time.', '2026-10-15 09:05:00');
        $this->insertComment($tieB, $id, $manager, 'Second of the tie.', '2026-10-15 09:01:00');
        $this->insertComment($tieA, $id, $manager, 'First of the tie.', '2026-10-15 09:01:00');

        $expected = [
            ['id' => $tieA, 'body' => 'First of the tie.', 'author' => ['id' => $manager->id, 'name' => $manager->name], 'created_at' => '2026-10-15T09:01:00+00:00'],
            ['id' => $tieB, 'body' => 'Second of the tie.', 'author' => ['id' => $manager->id, 'name' => $manager->name], 'created_at' => '2026-10-15T09:01:00+00:00'],
            ['id' => $late, 'body' => 'Last in time.', 'author' => ['id' => $ceo->id, 'name' => $ceo->name], 'created_at' => '2026-10-15T09:05:00+00:00'],
        ];

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))
            ->assertStatus(200)
            ->assertJsonPath('data.comments', $expected);
        $this->getJson(self::RECEIVED.'/'.$id, $this->bearer($manager))
            ->assertStatus(200)
            ->assertJsonPath('data.comments', $expected);
    }

    public function test_that_a_new_comment_appears_after_the_old_ones_on_the_authors_page(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($author, [$manager]);
        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))->assertStatus(200)->assertJsonPath('data.comments', []);

        $first = $this->commentId($manager, $id, 'One.');
        $this->travel(5)->minutes();
        $second = $this->commentId($manager, $id, 'Two.');

        $listed = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))->assertStatus(200)->json('data.comments');
        self::assertIsArray($listed);
        self::assertSame([$first, $second], array_column($listed, 'id'));
    }

    /** A draft has no comments: the key is there, empty (the page draws its empty state from it). */
    public function test_that_a_draft_reads_an_empty_comment_list(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $id = $this->create($author);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))
            ->assertStatus(200)
            ->assertJsonPath('data.comments', []);
    }

    /** A hidden account is never named (`UserFactsInterface::namesOf`): the name is blank, as a recipient's would be. */
    public function test_that_a_hidden_commenter_is_not_named(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $admin = $this->user(RoleName::SuperAdmin);
        $id = $this->submitted($author, [$manager]);
        $this->insertComment('00000000-0000-7000-8000-000000000009', $id, $admin, 'From the shadows.', '2026-10-15 09:01:00');

        $listed = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))->assertStatus(200)->json('data.comments');
        self::assertIsArray($listed);
        /** @var list<array{author?: array{id?: string, name?: string}}> $listed */
        self::assertCount(1, $listed);
        // Like a hidden recipient (`ShowReport::read`): the id stays, the name is blank — the id is the row's own `created_by`.
        self::assertSame($admin->id, $listed[0]['author']['id'] ?? null);
        self::assertSame('', $listed[0]['author']['name'] ?? null);
        self::assertStringNotContainsString($admin->name, (string) json_encode($listed));
    }

    /** A report that HAS comments: they are read only by its author and its live recipients, and the 404 carries none. */
    public function test_that_a_stranger_reads_neither_the_report_nor_its_comments(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager, 'recipient');
        $strangerManager = $this->user(RoleName::Manager, 'stranger');
        $id = $this->submitted($author, [$manager]);
        $this->comment($manager, $id, 'Private words.')->assertStatus(201);

        // The controls: the author and the recipient both read the comment.
        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))->assertStatus(200)->assertJsonPath('data.comments.0.body', 'Private words.');
        $this->getJson(self::RECEIVED.'/'.$id, $this->bearer($manager))->assertStatus(200)->assertJsonPath('data.comments.0.body', 'Private words.');

        // Not the author: another employee, and a Manager who holds report.create but wrote nothing here.
        foreach ([$this->user(RoleName::Procurement), $strangerManager] as $stranger) {
            $response = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($stranger))
                ->assertStatus(404)
                ->assertJsonPath('error.code', 'resource_not_found');
            self::assertStringNotContainsString('Private words.', (string) $response->getContent());
        }

        // Not a recipient: a Manager and the CEO both hold view_received, and neither was sent it.
        foreach ([$strangerManager, $this->user(RoleName::Ceo)] as $outsider) {
            $response = $this->getJson(self::RECEIVED.'/'.$id, $this->bearer($outsider))
                ->assertStatus(404)
                ->assertJsonPath('error.code', 'resource_not_found');
            self::assertStringNotContainsString('Private words.', (string) $response->getContent());
        }
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** @return TestResponse<\Illuminate\Http\JsonResponse> */
    private function comment(User $caller, string $id, string $body): TestResponse
    {
        return $this->postJson(self::ENDPOINT.'/'.$id.'/comments', ['body' => $body], $this->bearer($caller));
    }

    private function commentId(User $caller, string $id, string $body): string
    {
        $comment = $this->comment($caller, $id, $body)->assertStatus(201)->json('data.id');
        self::assertIsString($comment);

        return $comment;
    }

    /** No refusal leaves a row or an audit entry behind: both counts are the positive controls' alone. */
    private function assertNothingWritten(int $comments): void
    {
        self::assertSame($comments, DB::table('report_comments')->count(), 'A refused comment wrote a row.');
        self::assertSame($comments, DB::table('audit_log')->where('event', 'REPORT_COMMENT_ADDED')->count(), 'A refused comment was audited.');
    }

    private function insertComment(string $id, string $report, User $by, string $body, string $at): void
    {
        DB::table('report_comments')->insert([
            'id' => $id, 'report_id' => $report, 'body' => $body,
            'created_by' => $by->id, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    /** @return array{status: mixed, token: mixed, versions: mixed} what a comment must leave as it found it */
    private function reportFacts(string $id): array
    {
        $report = DB::table('reports')->where('id', $id)->first();
        self::assertNotNull($report);

        return [
            'status' => $report->status,
            'token' => $report->version_token,
            'versions' => DB::table('report_versions')->where('report_id', $id)->orderBy('version')->pluck('snapshot')->all(),
        ];
    }

    /** A submitted report moved to `$status` the way its own route (or, for Archived, J-08's write) would. */
    private function reportAt(string $status, User $author, User $recipient): string
    {
        $id = $this->submitted($author, [$recipient]);

        if ($status === 'viewed' || $status === 'acknowledged') {
            $this->getJson(self::RECEIVED.'/'.$id, $this->bearer($recipient))->assertStatus(200);
        }
        if ($status === 'acknowledged') {
            $this->patchJson(self::ENDPOINT.'/'.$id.'/acknowledge', [], [...$this->bearer($recipient), 'If-Match' => $this->etag($id)])->assertStatus(200);
        }
        if ($status === 'archived') {
            DB::table('reports')->where('id', $id)->update(['status' => 'archived']);
        }

        return $id;
    }

    private function etag(string $id): string
    {
        $token = DB::table('reports')->where('id', $id)->value('version_token');
        self::assertIsInt($token);

        return 'report:'.$id.':'.$token;
    }

    /** @param list<User> $recipients */
    private function submitted(User $author, array $recipients): string
    {
        $id = $this->create($author);
        $this->patchJson(
            self::ENDPOINT.'/'.$id.'/submit',
            ['recipient_ids' => array_map(static fn (User $u): string => $u->id, $recipients)],
            [...$this->bearer($author), 'If-Match' => $this->etag($id)],
        )->assertStatus(200);

        return $id;
    }

    private function create(User $author): string
    {
        // 7.3: one author may need several same-day reports (one per status); the fixture confirms the duplicate.
        $id = $this->postJson(self::ENDPOINT, ['type' => 'daily', 'confirm_duplicate' => true], $this->bearer($author))
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
