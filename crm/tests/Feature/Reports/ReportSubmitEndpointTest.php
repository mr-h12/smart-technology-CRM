<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Ramsey\Uuid\Uuid;
use Tests\Feature\Pdf\InsertsQuotationRows;
use Tests\TestCase;

/**
 * Module 13, first slice — choosing a report's recipients and submitting it.
 *
 * - Candidates: active users holding §3.10's `report.view_received` with a scope
 *   that covers the author (`D-127`, `D-129`). `All` covers everyone; `Out`
 *   covers Outdoor Sales authors; `Team` covers the author's own Team Leader
 *   (7.5d, `D-138`), who is a default beside the named Manager.
 * - Default: the one Manager the `reports.default_recipient` setting names, when
 *   that user is still an active Manager — and never on a Manager's own report.
 * - Submit: §11.3's frozen copy (`report_versions` v1) and the recipients, in
 *   one transaction, under the token. The acceptance criterion "unchanged even
 *   if the data changes" is read back after the data changes.
 */
final class ReportSubmitEndpointTest extends TestCase
{
    use InsertsQuotationRows;
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private const ENDPOINT = '/api/v1/reports';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(9, 0));
    }

    // ── candidates ──────────────────────────────────────────────────────────

    public function test_that_an_indoor_authors_candidates_are_the_active_managers_and_the_ceo(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $managerA = $this->user(RoleName::Manager, 'a');
        $managerB = $this->user(RoleName::Manager, 'b');
        $ceo = $this->user(RoleName::Ceo);
        $this->user(RoleName::Manager, 'gone', active: false);
        $this->user(RoleName::TeamLeader);          // `Team` covers only the author's own leader; this author has none.
        $this->user(RoleName::OutdoorSupervisor);   // `Out` does not cover an indoor author.
        $this->user(RoleName::Procurement);
        $this->user(RoleName::SuperAdmin);

        $id = $this->create($author);

        $candidates = $this->candidates($author, $id);

        self::assertEqualsCanonicalizing([$managerA->id, $managerB->id, $ceo->id], array_column($candidates, 'id'));
        foreach ($candidates as $candidate) {
            self::assertSame(['id', 'name', 'is_default'], array_keys($candidate));
            self::assertFalse($candidate['is_default'], 'No setting, no default (D-129).');
        }
    }

    public function test_that_an_outdoor_authors_candidates_include_the_outdoor_supervisor(): void
    {
        $author = $this->user(RoleName::OutdoorSales);
        $supervisor = $this->user(RoleName::OutdoorSupervisor);
        $manager = $this->user(RoleName::Manager);

        $ids = array_column($this->candidates($author, $this->create($author)), 'id');

        self::assertContains($supervisor->id, $ids);
        self::assertContains($manager->id, $ids);
    }

    /** 7.5d, `D-138`: the author's own Team Leader is a candidate, and a default beside the named Manager. */
    public function test_that_the_authors_team_leader_is_a_candidate_and_a_default(): void
    {
        $leader = $this->user(RoleName::TeamLeader, 'mine');
        $author = $this->memberOf($leader);
        $manager = $this->user(RoleName::Manager);
        $this->setting('reports.default_recipient', $manager->id);

        $defaults = array_column($this->candidates($author, $this->create($author)), 'is_default', 'id');

        self::assertArrayHasKey($leader->id, $defaults, 'The author\'s own Team Leader is a candidate.');
        self::assertTrue($defaults[$leader->id], 'The author\'s own Team Leader is a default.');
        self::assertTrue($defaults[$manager->id], 'The named Manager stays a default.');
    }

    /** 7.5d, `D-138`: a leader whose role also holds `All` on *view received* is still a default for the team. */
    public function test_that_a_leader_holding_another_scope_is_still_a_default(): void
    {
        DB::table('role_permissions')->insert([
            'id' => (string) Str::uuid7(),
            'role_id' => Role::query()->where('slug', RoleName::TeamLeader->value)->value('id'),
            'permission_id' => DB::table('permissions')
                ->where(['resource' => 'report', 'action' => 'view_received', 'scope' => 'all'])
                ->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $leader = $this->user(RoleName::TeamLeader, 'mine');
        $other = $this->user(RoleName::TeamLeader, 'other');
        $author = $this->memberOf($leader);

        $defaults = array_column($this->candidates($author, $this->create($author)), 'is_default', 'id');

        self::assertArrayHasKey($other->id, $defaults, 'The control: `All` makes every Team Leader a candidate.');
        self::assertFalse($defaults[$other->id], 'A leader of nobody here is no default.');
        self::assertTrue($defaults[$leader->id] ?? false, 'The author\'s own Team Leader is a default.');
    }

    /** 7.5d: `Team` reaches the leader's own team only — another Team Leader is no candidate. */
    public function test_that_another_team_leader_is_not_a_candidate(): void
    {
        $leader = $this->user(RoleName::TeamLeader, 'mine');
        $other = $this->user(RoleName::TeamLeader, 'other');
        $this->memberOf($other, 'theirs');
        $author = $this->memberOf($leader);
        self::assertNotSame($leader->id, $other->id);

        $ids = array_column($this->candidates($author, $this->create($author)), 'id');

        self::assertContains($leader->id, $ids, 'The control: the author\'s own leader is there.');
        self::assertNotContains($other->id, $ids);
    }

    /** 7.5d: an author with no leader is offered no Team Leader, even one who leads somebody else. */
    public function test_that_an_author_without_a_leader_gets_no_team_leader(): void
    {
        $leader = $this->user(RoleName::TeamLeader);
        $this->memberOf($leader, 'member');
        $author = $this->user(RoleName::IndoorSales, 'alone');
        $manager = $this->user(RoleName::Manager);
        self::assertNull($author->team_leader_id);

        $ids = array_column($this->candidates($author, $this->create($author)), 'id');

        self::assertContains($manager->id, $ids, 'The control: the author has candidates.');
        self::assertNotContains($leader->id, $ids);
    }

    /** 7.5d: `Out` covers Outdoor Sales authors only — an Outdoor Supervisor's report does not offer another one. */
    public function test_that_an_outdoor_supervisors_report_does_not_offer_the_outdoor_supervisor(): void
    {
        $author = $this->user(RoleName::OutdoorSupervisor, 'author');
        $other = $this->user(RoleName::OutdoorSupervisor, 'other');
        $manager = $this->user(RoleName::Manager);

        $ids = array_column($this->candidates($author, $this->create($author)), 'id');

        self::assertContains($manager->id, $ids, 'The control: the author has candidates.');
        self::assertNotContains($other->id, $ids);
    }

    /** `D-129`: the Manager the setting names is the default. */
    public function test_that_the_named_manager_is_the_default(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $named = $this->user(RoleName::Manager, 'a');
        $other = $this->user(RoleName::Manager, 'b');
        $this->setting('reports.default_recipient', $named->id);

        $candidates = $this->candidates($author, $this->create($author));

        $defaults = array_column($candidates, 'is_default', 'id');
        self::assertTrue($defaults[$named->id]);
        self::assertFalse($defaults[$other->id]);
    }

    /** `D-129`: a named user who is inactive, or no longer a Manager, is no default. */
    public function test_that_a_named_user_who_is_no_longer_an_active_manager_is_no_default(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $named = $this->user(RoleName::Manager, 'a');
        $this->user(RoleName::Manager, 'b');
        $this->setting('reports.default_recipient', $named->id);
        $id = $this->create($author);

        DB::table('users')->where('id', $named->id)->update(['is_active' => false]);
        self::assertNotContains(true, array_column($this->candidates($author, $id), 'is_default'));

        DB::table('users')->where('id', $named->id)->update([
            'is_active' => true,
            'role_id' => Role::query()->where('slug', RoleName::IndoorSales->value)->value('id'),
        ]);
        self::assertNotContains(true, array_column($this->candidates($author, $id), 'is_default'));
    }

    /** `D-127`: a Manager's own report has no default; the Manager chooses each time, and not himself. */
    public function test_that_a_managers_own_report_has_no_default_and_excludes_the_author(): void
    {
        $author = $this->user(RoleName::Manager, 'author');
        $other = $this->user(RoleName::Manager, 'other');
        $this->setting('reports.default_recipient', $other->id);

        $candidates = $this->candidates($author, $this->create($author));

        self::assertNotContains($author->id, array_column($candidates, 'id'));
        self::assertContains($other->id, array_column($candidates, 'id'));
        self::assertNotContains(true, array_column($candidates, 'is_default'));
    }

    public function test_that_another_authors_candidates_are_not_found(): void
    {
        $owner = $this->user(RoleName::Manager);
        $id = $this->create($owner);

        $this->getJson(self::ENDPOINT.'/'.$id.'/recipient-candidates', $this->bearer($this->user(RoleName::IndoorSales)))
            ->assertStatus(404);
    }

    // ── submit ──────────────────────────────────────────────────────────────

    public function test_that_submitting_freezes_a_copy_and_saves_the_recipients(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $this->activity($author->id, 'DEAL_CREATED', '2026-10-15 08:00:00');
        $id = $this->create($author);
        // `D-131`: a note is written through its own route and leaves the report's token where it was.
        $this->withNote($author, $id, 'A good day.');

        $this->submit($author, $id, 'report:'.$id.':1', [$manager->id])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.etag', 'report:'.$id.':2')
            ->assertJsonPath('data.recipients.0.id', $manager->id)
            ->assertJsonPath('data.recipients.0.name', $manager->name);

        $report = DB::table('reports')->where('id', $id)->first();
        self::assertNotNull($report);
        self::assertSame('submitted', $report->status);
        self::assertNotNull($report->submitted_at);

        self::assertSame([$manager->id], DB::table('report_recipients')->where('report_id', $id)->pluck('user_id')->all());

        $version = DB::table('report_versions')->where('report_id', $id)->first();
        self::assertNotNull($version, '§11.3: a submitted report keeps a frozen copy.');
        self::assertIsInt($version->version);
        self::assertSame(1, $version->version);
        self::assertIsString($version->snapshot);
        $snapshot = json_decode($version->snapshot, true);
        self::assertIsArray($snapshot);
        self::assertEquals([['body' => 'A good day.']], $snapshot['notes']);
        self::assertIsArray($snapshot['activity']);
        self::assertIsArray($snapshot['activity']['counts']);
        self::assertSame(1, $snapshot['activity']['counts']['deals_created']);
    }

    /** Acceptance criterion: "Submitted report → fixed snapshot … unchanged even if the data changes". */
    public function test_that_a_submitted_report_does_not_change_when_the_data_does(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $this->activity($author->id, 'DEAL_CREATED', '2026-10-15 08:00:00');
        $id = $this->create($author);
        $this->submit($author, $id, 'report:'.$id.':1', [$manager->id])->assertStatus(200);

        $this->activity($author->id, 'DEAL_CREATED', '2026-10-15 08:30:00');

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))
            ->assertStatus(200)
            ->assertJsonPath('data.activity.counts.deals_created', 1);
    }

    /**
     * `D-130`: the frozen copy holds the records as they were at submit — a
     * deal that moves on afterwards still shows its submit-time status.
     */
    public function test_that_the_frozen_copy_holds_the_records_as_they_were_at_submit(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $dealId = $this->activity($author->id, 'DEAL_CREATED', '2026-10-15 08:00:00');
        $code = DB::table('deals')->where('id', $dealId)->value('code');
        $id = $this->create($author);
        $this->submit($author, $id, 'report:'.$id.':1', [$manager->id])->assertStatus(200);

        $frozen = ['deal_id' => $dealId, 'code' => $code, 'customer_name' => 'Nile Trading', 'status' => 'lead'];

        $version = DB::table('report_versions')->where('report_id', $id)->first();
        self::assertNotNull($version);
        self::assertIsString($version->snapshot);
        $snapshot = json_decode($version->snapshot, true);
        self::assertIsArray($snapshot);
        self::assertIsArray($snapshot['activity']);
        self::assertArrayHasKey('sections', $snapshot['activity'], 'D-130: the frozen copy holds the records, not the log.');
        self::assertIsArray($snapshot['activity']['sections']);
        // jsonb stores keys in its own order; the frozen copy is compared by value.
        self::assertEquals([$frozen], $snapshot['activity']['sections']['deals_created']);

        DB::table('deals')->where('id', $dealId)->update(['status' => 'contacted']);
        DB::table('customers')->where('id', DB::table('deals')->where('id', $dealId)->value('customer_id'))->update(['name' => 'Renamed Later']);

        $shown = $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))
            ->assertStatus(200)
            ->json('data.activity.sections.deals_created');
        self::assertEquals([$frozen], $shown);
    }

    public function test_that_submitting_is_audited(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->create($author);
        $this->submit($author, $id, 'report:'.$id.':1', [$manager->id])->assertStatus(200);

        $entry = DB::table('audit_log')->where('event', 'REPORT_SUBMITTED')->where('entity_id', $id)->first();
        self::assertNotNull($entry, 'AUD-01: the submit left no audit entry.');
        self::assertSame($author->id, $entry->user_id);
    }

    /** `D-127`: a report goes to somebody — with no default, the author must choose. */
    public function test_that_a_submit_without_recipients_is_refused(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $id = $this->create($author);

        $this->submit($author, $id, 'report:'.$id.':1', [])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'recipient_ids');

        $this->assertStillDraft($id);
    }

    public function test_that_a_recipient_outside_the_candidates_is_refused(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $colleague = $this->user(RoleName::Procurement);
        $id = $this->create($author);

        $this->submit($author, $id, 'report:'.$id.':1', [$colleague->id])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'recipient_ids');

        $this->assertStillDraft($id);
    }

    /** 7.5d: a report goes to the author's own Team Leader by submit. */
    public function test_that_submitting_to_the_authors_team_leader_is_accepted(): void
    {
        $leader = $this->user(RoleName::TeamLeader, 'mine');
        $author = $this->memberOf($leader);
        $id = $this->create($author);

        $this->submit($author, $id, 'report:'.$id.':1', [$leader->id])
            ->assertStatus(200)
            ->assertJsonPath('data.recipients.0.id', $leader->id);

        self::assertSame([$leader->id], DB::table('report_recipients')->where('report_id', $id)->pluck('user_id')->all());
    }

    /** 7.5d: a Team Leader who does not lead the author is refused like any non-candidate. */
    public function test_that_submitting_to_another_team_leader_is_refused(): void
    {
        $leader = $this->user(RoleName::TeamLeader, 'mine');
        $other = $this->user(RoleName::TeamLeader, 'other');
        $author = $this->memberOf($leader);
        $id = $this->create($author);

        $this->submit($author, $id, 'report:'.$id.':1', [$other->id])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.code', 'recipient_not_allowed');

        $this->assertStillDraft($id);
    }

    public function test_that_a_manager_may_not_send_his_report_to_himself(): void
    {
        $author = $this->user(RoleName::Manager);
        $id = $this->create($author);

        $this->submit($author, $id, 'report:'.$id.':1', [$author->id])->assertStatus(422);

        $this->assertStillDraft($id);
    }

    public function test_that_a_stale_submit_is_a_409(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->create($author);
        // The same submit sent twice from one opened copy (`D-131`: only submit and the answers move the token now).
        $this->submit($author, $id, 'report:'.$id.':1', [$manager->id])->assertStatus(200);

        $this->submit($author, $id, 'report:'.$id.':1', [$manager->id])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'concurrency_conflict')
            ->assertJsonPath('error.details.0.current_etag', 'report:'.$id.':2');

        self::assertSame(1, DB::table('report_versions')->where('report_id', $id)->count(), 'The stale submit froze no second copy.');
    }

    public function test_that_a_submit_without_if_match_is_a_400(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->create($author);

        $this->patchJson(self::ENDPOINT.'/'.$id.'/submit', ['recipient_ids' => [$manager->id]], $this->bearer($author))
            ->assertStatus(400)
            ->assertJsonPath('error.details.0.field', 'If-Match');
    }

    public function test_that_a_submitted_report_cannot_be_submitted_again(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->create($author);
        $this->submit($author, $id, 'report:'.$id.':1', [$manager->id])->assertStatus(200);

        // Module 13 · 7.3 (owner, 2026-10-18): one answer for a report that is no longer editable.
        $this->submit($author, $id, 'report:'.$id.':2', [$manager->id])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'state_transition_invalid')
            ->assertJsonPath('error.details.0.code', 'report_not_editable');

        self::assertSame(1, DB::table('report_versions')->where('report_id', $id)->count());
        self::assertSame(1, DB::table('report_recipients')->where('report_id', $id)->count());
    }

    public function test_that_another_authors_report_cannot_be_submitted(): void
    {
        $owner = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->create($owner);
        $intruder = $this->user(RoleName::Procurement);

        $this->submit($intruder, $id, 'report:'.$id.':1', [$manager->id])->assertStatus(404);

        $this->assertStillDraft($id);
    }

    // ── follow-ups and notes in the frozen copy (`D-131`) ───────────────────

    /** `D-131`: submit freezes each follow-up with its customer's name as it was, and the notes list. */
    public function test_that_submitting_freezes_the_follow_ups_and_notes(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $nile = $this->customer('Nile Trading', $author);
        $id = $this->create($author);
        $this->withFollowUp($author, $id, $nile, 'Wants a visit.');
        $this->withNote($author, $id, 'A good day.');

        $this->submit($author, $id, $this->etag($id), [$manager->id])->assertStatus(200);

        $snapshot = $this->snapshot($id, 1);
        // jsonb stores keys in its own order; the frozen copy is compared by value.
        self::assertEquals([['customer_id' => $nile, 'customer_name' => 'Nile Trading', 'note' => 'Wants a visit.', 'followed_up_at' => '2026-10-15T09:00:00+00:00']], $snapshot['follow_ups'] ?? null);
        self::assertEquals([['body' => 'A good day.']], $snapshot['notes'] ?? null);
    }

    /** `D-131`: "each follow-up with its customer's name as it was" — a later rename does not reach the copy. */
    public function test_that_a_customer_renamed_after_submit_keeps_its_frozen_name(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $nile = $this->customer('Nile Trading', $author);
        $id = $this->create($author);
        $this->withFollowUp($author, $id, $nile, 'Wants a visit.');
        $this->submit($author, $id, $this->etag($id), [$manager->id])->assertStatus(200);

        DB::table('customers')->where('id', $nile)->update(['name' => 'Renamed Later']);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))
            ->assertStatus(200)
            ->assertJsonPath('data.follow_ups.0.customer_name', 'Nile Trading')
            ->assertJsonPath('data.follow_ups.0.note', 'Wants a visit.');
    }

    /** `D-131`: both are shown to a recipient, from the frozen copy. */
    public function test_that_a_recipient_reads_the_follow_ups_and_notes(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $nile = $this->customer('Nile Trading', $author);
        $id = $this->create($author);
        $this->withFollowUp($author, $id, $nile, 'Wants a visit.');
        $this->withNote($author, $id, 'A good day.');
        $this->submit($author, $id, $this->etag($id), [$manager->id])->assertStatus(200);

        $this->getJson(self::ENDPOINT.'/received/'.$id, $this->bearer($manager))
            ->assertStatus(200)
            ->assertJsonPath('data.follow_ups.0.customer_name', 'Nile Trading')
            ->assertJsonPath('data.follow_ups.0.note', 'Wants a visit.')
            ->assertJsonPath('data.notes.0.body', 'A good day.');
    }

    /** `D-131`: "a version submitted earlier shows its notes text as one note". */
    public function test_that_an_older_snapshots_notes_text_reads_as_one_note(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->create($author);
        $this->submit($author, $id, $this->etag($id), [$manager->id])->assertStatus(200);
        // The frozen copy as slices 1 and 2 wrote it: one `notes` string, no follow-ups.
        $old = $this->snapshot($id, 1);
        unset($old['follow_ups']);
        DB::table('report_versions')->where('report_id', $id)->update(['snapshot' => json_encode([...$old, 'notes' => 'Old words.'])]);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))
            ->assertStatus(200)
            ->assertJsonPath('data.notes.0.body', 'Old words.')
            ->assertJsonCount(1, 'data.notes')
            ->assertJsonPath('data.follow_ups', []);
        $this->getJson(self::ENDPOINT.'/received/'.$id, $this->bearer($manager))
            ->assertStatus(200)
            ->assertJsonPath('data.notes.0.body', 'Old words.');
    }

    /**
     * §11.4 with `D-131`: Return → the rows and notes stay editable in the
     * draft; the resubmit freezes v2, and v1's frozen copy is unchanged.
     */
    public function test_that_return_then_version_2_keeps_the_rows_editable_and_v1_frozen(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $nile = $this->customer('Nile Trading', $author);
        $id = $this->create($author);
        $row = $this->withFollowUp($author, $id, $nile, 'First wording.');
        $note = $this->withNote($author, $id, 'First note.');
        $this->submit($author, $id, $this->etag($id), [$manager->id])->assertStatus(200);
        $this->patchJson(self::ENDPOINT.'/'.$id.'/return', ['reason' => 'Add Delta.'], [...$this->bearer($manager), 'If-Match' => $this->etag($id)])
            ->assertStatus(200);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'returned')
            ->assertJsonPath('data.follow_ups.0.id', $row)
            ->assertJsonPath('data.follow_ups.0.note', 'First wording.')
            ->assertJsonPath('data.notes.0.id', $note);

        $this->patchJson(self::ENDPOINT.'/'.$id.'/follow-ups/'.$row, ['note' => 'Second wording.'], $this->bearer($author))->assertStatus(200);
        $this->withNote($author, $id, 'Added after return.');
        $this->submit($author, $id, $this->etag($id), [$manager->id])->assertStatus(200);

        $v1 = $this->snapshot($id, 1);
        self::assertEquals([['customer_id' => $nile, 'customer_name' => 'Nile Trading', 'note' => 'First wording.', 'followed_up_at' => '2026-10-15T09:00:00+00:00']], $v1['follow_ups'] ?? null);
        self::assertEquals([['body' => 'First note.']], $v1['notes'] ?? null);

        $v2 = $this->snapshot($id, 2);
        self::assertEquals([['customer_id' => $nile, 'customer_name' => 'Nile Trading', 'note' => 'Second wording.', 'followed_up_at' => '2026-10-15T09:00:00+00:00']], $v2['follow_ups'] ?? null);
        self::assertEquals([['body' => 'First note.'], ['body' => 'Added after return.']], $v2['notes'] ?? null);
    }

    // ── refusals the permission audit asked for ─────────────────────────────

    /** §3.10: the CEO holds no `report.create`, so no author route opens for them — even on a real report id. */
    public function test_that_the_ceo_is_refused_on_every_report_route(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->create($author);
        $ceo = $this->bearer($this->user(RoleName::Ceo));

        $this->getJson(self::ENDPOINT.'/'.$id, $ceo)->assertStatus(403);
        $this->patchJson(self::ENDPOINT.'/'.$id, [], [...$ceo, 'If-Match' => 'report:'.$id.':1'])->assertStatus(403);
        // `D-131`: the follow-up and note routes sit behind the same author gate.
        $row = (string) Str::uuid7();
        $this->postJson(self::ENDPOINT.'/'.$id.'/follow-ups', ['customer_id' => $row, 'note' => 'x'], $ceo)->assertStatus(403);
        $this->patchJson(self::ENDPOINT.'/'.$id.'/follow-ups/'.$row, ['note' => 'x'], $ceo)->assertStatus(403);
        $this->deleteJson(self::ENDPOINT.'/'.$id.'/follow-ups/'.$row, [], $ceo)->assertStatus(403);
        $this->postJson(self::ENDPOINT.'/'.$id.'/notes', ['body' => 'x'], $ceo)->assertStatus(403);
        $this->patchJson(self::ENDPOINT.'/'.$id.'/notes/'.$row, ['body' => 'x'], $ceo)->assertStatus(403);
        $this->deleteJson(self::ENDPOINT.'/'.$id.'/notes/'.$row, [], $ceo)->assertStatus(403);
        $this->getJson(self::ENDPOINT.'/'.$id.'/recipient-candidates', $ceo)->assertStatus(403);
        $this->patchJson(self::ENDPOINT.'/'.$id.'/submit', ['recipient_ids' => [$manager->id]], [...$ceo, 'If-Match' => 'report:'.$id.':1'])
            ->assertStatus(403);

        $this->assertStillDraft($id);
        self::assertSame(0, DB::table('report_notes')->where('report_id', $id)->count());
    }

    public function test_that_an_unauthenticated_caller_is_refused(): void
    {
        $id = $this->create($this->user(RoleName::IndoorSales));
        // The request guard keeps the user it resolved for create(); drop it so the calls below carry no identity.
        $this->app['auth']->forgetGuards();

        $this->getJson(self::ENDPOINT)->assertStatus(401);
        $this->postJson(self::ENDPOINT, ['type' => 'daily'])->assertStatus(401);
        $this->getJson(self::ENDPOINT.'/'.$id)->assertStatus(401);
        $this->getJson(self::ENDPOINT.'/'.$id.'/recipient-candidates')->assertStatus(401);
        $this->patchJson(self::ENDPOINT.'/'.$id.'/submit', ['recipient_ids' => []])->assertStatus(401);
    }

    /** The candidates rule holds on the submit path too: an inactive Manager and the hidden Super Admin are refused. */
    public function test_that_an_inactive_manager_or_the_super_admin_is_refused_as_a_recipient(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $gone = $this->user(RoleName::Manager, 'gone', active: false);
        $admin = $this->user(RoleName::SuperAdmin);
        self::assertTrue((bool) $admin->is_hidden, 'The fixture must build the Super Admin hidden, or this proves nothing.');
        $id = $this->create($author);

        $this->submit($author, $id, 'report:'.$id.':1', [$gone->id])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.code', 'recipient_not_allowed');
        $this->submit($author, $id, 'report:'.$id.':1', [$admin->id])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.code', 'recipient_not_allowed');

        $this->assertStillDraft($id);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function withFollowUp(User $author, string $id, string $customer, string $note): string
    {
        $row = $this->postJson(self::ENDPOINT.'/'.$id.'/follow-ups', ['customer_id' => $customer, 'note' => $note], $this->bearer($author))
            ->assertStatus(201)
            ->json('data.id');
        self::assertIsString($row);

        return $row;
    }

    private function withNote(User $author, string $id, string $body): string
    {
        $note = $this->postJson(self::ENDPOINT.'/'.$id.'/notes', ['body' => $body], $this->bearer($author))
            ->assertStatus(201)
            ->json('data.id');
        self::assertIsString($note);

        return $note;
    }

    /** @return array<string, mixed> */
    private function snapshot(string $id, int $version): array
    {
        $raw = DB::table('report_versions')->where('report_id', $id)->where('version', $version)->value('snapshot');
        self::assertIsString($raw, "report_versions has no v{$version}.");
        $snapshot = json_decode($raw, true);
        self::assertIsArray($snapshot);

        /** @var array<string, mixed> $snapshot */
        return $snapshot;
    }

    /** The current token, read from the row. */
    private function etag(string $id): string
    {
        $token = DB::table('reports')->where('id', $id)->value('version_token');
        self::assertIsInt($token);

        return 'report:'.$id.':'.$token;
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

    private function assertStillDraft(string $id): void
    {
        self::assertSame('draft', DB::table('reports')->where('id', $id)->value('status'));
        self::assertSame(0, DB::table('report_versions')->where('report_id', $id)->count());
        self::assertSame(0, DB::table('report_recipients')->where('report_id', $id)->count());
    }

    /** @return list<array<string, mixed>> */
    private function candidates(User $author, string $id): array
    {
        $data = $this->getJson(self::ENDPOINT.'/'.$id.'/recipient-candidates', $this->bearer($author))
            ->assertStatus(200)
            ->json('data');
        self::assertIsArray($data);

        /** @var list<array<string, mixed>> $data */
        return $data;
    }

    /**
     * @param  list<string>  $recipients
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function submit(User $author, string $id, string $ifMatch, array $recipients): TestResponse
    {
        return $this->patchJson(
            self::ENDPOINT.'/'.$id.'/submit',
            ['recipient_ids' => $recipients],
            [...$this->bearer($author), 'If-Match' => $ifMatch],
        );
    }

    private function create(User $author): string
    {
        $id = $this->postJson(self::ENDPOINT, ['type' => 'daily'], $this->bearer($author))
            ->assertStatus(201)
            ->json('data.id');
        self::assertIsString($id);

        return $id;
    }

    /** Through the Super Admin's settings endpoint, so the settings cache is the real one. */
    private function setting(string $key, string $value): void
    {
        $this->patchJson('/api/v1/settings', ['settings' => [$key => $value]], $this->bearer($this->user(RoleName::SuperAdmin)))
            ->assertStatus(200);
    }

    /** One index entry pointing at a real deal (Module 9's fixture), as `D-130` needs; returns the deal's id. */
    private function activity(string $userId, string $event, string $at): string
    {
        $dealId = DB::table('quotations')->where('id', $this->insertQuotation())->value('deal_id');
        self::assertIsString($dealId);
        DB::table('audit_log')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $userId, 'event' => $event,
            'entity_type' => 'deal', 'entity_id' => $dealId, 'created_at' => $at,
        ]);

        return $dealId;
    }

    /** An Indoor Sales user whose `team_leader_id` is $leader's (7.5a). */
    private function memberOf(User $leader, string $tag = 'member'): User
    {
        $member = $this->user(RoleName::IndoorSales, $tag);
        $member->forceFill(['team_leader_id' => $leader->id])->save();

        return $member;
    }

    private function user(RoleName $role, string $tag = '', bool $active = true): User
    {
        $user = new User;
        $user->fill([
            'name' => 'Test '.$role->label().($tag !== '' ? ' '.$tag : ''),
            'email' => str_replace('_', '.', $role->value).($tag !== '' ? '.'.$tag : '').'@example.test',
            'password' => self::PASSWORD,
            'role_id' => Role::query()->where('slug', $role->value)->firstOrFail()->id,
            'is_active' => $active,
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
