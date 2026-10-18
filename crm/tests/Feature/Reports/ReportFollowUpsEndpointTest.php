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
use Tests\TestCase;

/**
 * Module 13, slice 3 — a daily report's follow-ups and notes, written row by
 * row (`D-131`, spec §4):
 *
 * - `POST|PATCH|DELETE /reports/{report}/follow-ups[/{row}]` and the same for `/notes`.
 * - Under the report gate: `report.create`, the author only (`ReportAccess::reach`,
 *   404 for anyone else), and only while the report is a draft or returned
 *   (409 `report_not_editable` otherwise).
 * - Text required and ≤ 5000; a follow-up's customer one the author can see under
 *   `customer.view` at its row scope — another is 422, never a hint that it exists.
 * - Each add, edit and remove audited with old and new values (`AUD-01`).
 * - `PATCH /reports/{id}`'s old `notes` field is refused, as `D-122` did for customers.
 */
final class ReportFollowUpsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Passw0rd123';

    private const ENDPOINT = '/api/v1/reports';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(9, 0));
    }

    // ── follow-ups ──────────────────────────────────────────────────────────

    public function test_that_the_author_adds_edits_and_removes_a_follow_up_and_each_is_audited(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $nile = $this->customer('Nile Trading', $author);
        $delta = $this->customer('Delta Foods', $author);
        $id = $this->create($author);

        $row = $this->postJson($this->followUps($id), ['customer_id' => $nile, 'note' => 'Wants a visit.'], $this->bearer($author))
            ->assertStatus(201)
            ->assertJsonPath('data.customer_id', $nile)
            ->assertJsonPath('data.note', 'Wants a visit.')
            ->json('data.id');
        self::assertIsString($row);
        $this->assertDatabaseHas('report_follow_ups', ['id' => $row, 'report_id' => $id, 'customer_id' => $nile, 'deleted_at' => null]);
        $added = $this->audit('REPORT_FOLLOW_UP_ADDED', $row, $author);
        self::assertNull($added['old']);
        self::assertSame($nile, $added['new']['customer_id'] ?? null);
        self::assertSame('Wants a visit.', $added['new']['note'] ?? null);

        $this->patchJson($this->followUps($id).'/'.$row, ['customer_id' => $delta, 'note' => 'Sent the catalogue.'], $this->bearer($author))
            ->assertStatus(200)
            ->assertJsonPath('data.customer_id', $delta)
            ->assertJsonPath('data.note', 'Sent the catalogue.');
        $updated = $this->audit('REPORT_FOLLOW_UP_UPDATED', $row, $author);
        self::assertIsArray($updated['old']);
        self::assertSame($nile, $updated['old']['customer_id'] ?? null);
        self::assertSame('Wants a visit.', $updated['old']['note'] ?? null);
        self::assertSame($delta, $updated['new']['customer_id'] ?? null);
        self::assertSame('Sent the catalogue.', $updated['new']['note'] ?? null);

        $this->deleteJson($this->followUps($id).'/'.$row, [], $this->bearer($author))->assertStatus(204);
        self::assertNotNull(DB::table('report_follow_ups')->where('id', $row)->value('deleted_at'), 'DB-01: the row was not soft-deleted.');
        $removed = $this->audit('REPORT_FOLLOW_UP_REMOVED', $row, $author);
        self::assertIsArray($removed['old']);
        self::assertSame('Sent the catalogue.', $removed['old']['note'] ?? null);

        // Removed is gone: a second remove, or an edit, is a 404.
        $this->deleteJson($this->followUps($id).'/'.$row, [], $this->bearer($author))->assertStatus(404);
        $this->patchJson($this->followUps($id).'/'.$row, ['note' => 'back'], $this->bearer($author))->assertStatus(404);
    }

    /** Spec §4: a draft's payload lists its rows from the tables, in the order they were added. */
    public function test_that_a_drafts_payload_lists_its_follow_ups_and_notes_in_order(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $nile = $this->customer('Nile Trading', $author);
        $delta = $this->customer('Delta Foods', $author);
        $id = $this->create($author);

        $this->postJson($this->followUps($id), ['customer_id' => $nile, 'note' => 'First.'], $this->bearer($author))->assertStatus(201);
        $this->travel(1)->minutes();
        $this->postJson($this->followUps($id), ['customer_id' => $delta, 'note' => 'Second.'], $this->bearer($author))->assertStatus(201);
        $this->postJson($this->notes($id), ['body' => 'Note one.'], $this->bearer($author))->assertStatus(201);
        $this->travel(1)->minutes();
        $this->postJson($this->notes($id), ['body' => 'Note two.'], $this->bearer($author))->assertStatus(201);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))
            ->assertStatus(200)
            ->assertJsonPath('data.follow_ups.0.customer_id', $nile)
            ->assertJsonPath('data.follow_ups.0.customer_name', 'Nile Trading')
            ->assertJsonPath('data.follow_ups.0.note', 'First.')
            ->assertJsonPath('data.follow_ups.1.customer_name', 'Delta Foods')
            ->assertJsonPath('data.notes.0.body', 'Note one.')
            ->assertJsonPath('data.notes.1.body', 'Note two.');
    }

    public function test_that_a_blank_or_long_note_or_a_missing_customer_is_422(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $nile = $this->customer('Nile Trading', $author);
        $id = $this->create($author);

        foreach ([
            'note' => [['customer_id' => $nile], ['customer_id' => $nile, 'note' => '   '], ['customer_id' => $nile, 'note' => str_repeat('a', 5001)]],
            'customer_id' => [['note' => 'ok'], ['customer_id' => 'not-a-uuid', 'note' => 'ok'], ['customer_id' => (string) Str::uuid7(), 'note' => 'ok']],
        ] as $field => $payloads) {
            foreach ($payloads as $payload) {
                $this->postJson($this->followUps($id), $payload, $this->bearer($author))
                    ->assertStatus(422)
                    ->assertJsonPath('error.code', 'validation_failed')
                    ->assertJsonPath('error.details.0.field', $field);
            }
        }
        $this->assertDatabaseCount('report_follow_ups', 0);

        // The ceiling itself is allowed.
        $row = $this->postJson($this->followUps($id), ['customer_id' => $nile, 'note' => str_repeat('a', 5000)], $this->bearer($author))
            ->assertStatus(201)
            ->json('data.id');
        self::assertIsString($row);

        // `D-131`: a cleared note is not saved; the stored text stays.
        $this->patchJson($this->followUps($id).'/'.$row, ['note' => '   '], $this->bearer($author))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'note');
        self::assertSame(str_repeat('a', 5000), DB::table('report_follow_ups')->where('id', $row)->value('note'));
    }

    /**
     * `D-131`: the customer is one the author can see under `customer.view` at its
     * row scope — an Indoor Sales author's is `own`. Another salesperson's customer
     * is a 422 on the field, never a 404 that would say it exists.
     */
    public function test_that_a_customer_outside_the_authors_scope_is_422(): void
    {
        $author = $this->user(RoleName::IndoorSales, 'a');
        $colleague = $this->user(RoleName::IndoorSales, 'b');
        $mine = $this->customer('Nile Trading', $author);
        $theirs = $this->customer('Delta Foods', $colleague);
        $id = $this->create($author);

        $row = $this->postJson($this->followUps($id), ['customer_id' => $mine, 'note' => 'Mine.'], $this->bearer($author))
            ->assertStatus(201)
            ->json('data.id');
        self::assertIsString($row);

        $this->postJson($this->followUps($id), ['customer_id' => $theirs, 'note' => 'Not mine.'], $this->bearer($author))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'customer_id');
        $this->patchJson($this->followUps($id).'/'.$row, ['customer_id' => $theirs], $this->bearer($author))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'customer_id');

        self::assertSame([$mine], DB::table('report_follow_ups')->where('report_id', $id)->pluck('customer_id')->all());
    }

    /** `D-138`: a Team Leader author names the team's customers, never one owned by a user with no leader — the same 422. */
    public function test_that_a_team_leader_author_cannot_name_a_customer_outside_the_team(): void
    {
        $leader = $this->user(RoleName::TeamLeader);
        $member = $this->user(RoleName::IndoorSales);
        $member->forceFill(['team_leader_id' => $leader->id])->save();
        $members = $this->customer('Nile Trading', $member);
        $outsiders = $this->customer('Delta Foods', $this->user(RoleName::OutdoorSales));
        $id = $this->create($leader);

        $this->postJson($this->followUps($id), ['customer_id' => $members, 'note' => 'Team.'], $this->bearer($leader))
            ->assertStatus(201);
        $this->postJson($this->followUps($id), ['customer_id' => $outsiders, 'note' => 'Not ours.'], $this->bearer($leader))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'customer_id');

        self::assertSame([$members], DB::table('report_follow_ups')->where('report_id', $id)->pluck('customer_id')->all());
    }

    /**
     * `D-131`, gap 1: an archived customer is out of reach even when the author owns
     * it — the same 422 `error` as a customer outside the scope (`meta.request_id` is per request), on add and on edit,
     * with no row and no audit entry written.
     */
    public function test_that_an_archived_customer_in_the_authors_scope_is_refused_like_one_outside_it(): void
    {
        $author = $this->user(RoleName::IndoorSales, 'a');
        $colleague = $this->user(RoleName::IndoorSales, 'b');
        $mine = $this->customer('Nile Trading', $author);
        $archived = $this->customer('Old Delta', $author);
        DB::table('customers')->where('id', $archived)->update(['is_archived' => true]);
        $theirs = $this->customer('Delta Foods', $colleague);
        $id = $this->create($author);
        $row = $this->postJson($this->followUps($id), ['customer_id' => $mine, 'note' => 'Mine.'], $this->bearer($author))
            ->assertStatus(201)->json('data.id');
        self::assertIsString($row);
        $audited = DB::table('audit_log')->where('event', 'like', 'REPORT_FOLLOW_UP_%')->count();

        $outside = $this->postJson($this->followUps($id), ['customer_id' => $theirs, 'note' => 'x'], $this->bearer($author))
            ->assertStatus(422)->json('error');
        $this->postJson($this->followUps($id), ['customer_id' => $archived, 'note' => 'x'], $this->bearer($author))
            ->assertStatus(422)
            ->assertJsonPath('error', $outside);

        $outside = $this->patchJson($this->followUps($id).'/'.$row, ['customer_id' => $theirs], $this->bearer($author))
            ->assertStatus(422)->json('error');
        $this->patchJson($this->followUps($id).'/'.$row, ['customer_id' => $archived], $this->bearer($author))
            ->assertStatus(422)
            ->assertJsonPath('error', $outside);

        self::assertSame([$mine], DB::table('report_follow_ups')->where('report_id', $id)->pluck('customer_id')->all());
        self::assertSame($audited, DB::table('audit_log')->where('event', 'like', 'REPORT_FOLLOW_UP_%')->count());
    }

    /** `D-131`, gap 3a: a customer that does not exist and one outside the scope are answered alike — no hint either exists. */
    public function test_that_a_missing_customer_and_one_outside_the_scope_get_the_same_422(): void
    {
        $author = $this->user(RoleName::IndoorSales, 'a');
        $theirs = $this->customer('Delta Foods', $this->user(RoleName::IndoorSales, 'b'));
        $id = $this->create($author);

        $outside = $this->postJson($this->followUps($id), ['customer_id' => $theirs, 'note' => 'x'], $this->bearer($author))
            ->assertStatus(422)->json('error');
        $missing = $this->postJson($this->followUps($id), ['customer_id' => (string) Str::uuid7(), 'note' => 'x'], $this->bearer($author))
            ->assertStatus(422)->json('error');

        self::assertSame($outside, $missing);
        $this->assertDatabaseCount('report_follow_ups', 0);
    }

    // ── notes ───────────────────────────────────────────────────────────────

    public function test_that_the_author_adds_edits_and_removes_a_note_and_each_is_audited(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $id = $this->create($author);

        $note = $this->postJson($this->notes($id), ['body' => 'Morning calls.'], $this->bearer($author))
            ->assertStatus(201)
            ->assertJsonPath('data.body', 'Morning calls.')
            ->json('data.id');
        self::assertIsString($note);
        $this->assertDatabaseHas('report_notes', ['id' => $note, 'report_id' => $id, 'deleted_at' => null]);
        $added = $this->audit('REPORT_NOTE_ADDED', $note, $author);
        self::assertNull($added['old']);
        self::assertSame('Morning calls.', $added['new']['body'] ?? null);

        $this->patchJson($this->notes($id).'/'.$note, ['body' => 'Two visits.'], $this->bearer($author))
            ->assertStatus(200)
            ->assertJsonPath('data.body', 'Two visits.');
        $updated = $this->audit('REPORT_NOTE_UPDATED', $note, $author);
        self::assertIsArray($updated['old']);
        self::assertSame('Morning calls.', $updated['old']['body'] ?? null);
        self::assertSame('Two visits.', $updated['new']['body'] ?? null);

        $this->deleteJson($this->notes($id).'/'.$note, [], $this->bearer($author))->assertStatus(204);
        self::assertNotNull(DB::table('report_notes')->where('id', $note)->value('deleted_at'), 'DB-01: the note was not soft-deleted.');
        $removed = $this->audit('REPORT_NOTE_REMOVED', $note, $author);
        self::assertIsArray($removed['old']);
        self::assertSame('Two visits.', $removed['old']['body'] ?? null);

        $this->deleteJson($this->notes($id).'/'.$note, [], $this->bearer($author))->assertStatus(404);
    }

    public function test_that_a_blank_or_long_body_is_422(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $id = $this->create($author);

        foreach ([[], ['body' => '   '], ['body' => str_repeat('a', 5001)], ['body' => ['not', 'text']]] as $payload) {
            $this->postJson($this->notes($id), $payload, $this->bearer($author))
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonPath('error.details.0.field', 'body');
        }
        $this->assertDatabaseCount('report_notes', 0);

        $note = $this->postJson($this->notes($id), ['body' => str_repeat('a', 5000)], $this->bearer($author))
            ->assertStatus(201)
            ->json('data.id');
        self::assertIsString($note);

        // `D-131`: a cleared note is not saved; the stored text stays.
        $this->patchJson($this->notes($id).'/'.$note, ['body' => ''], $this->bearer($author))
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'body');
        self::assertSame(str_repeat('a', 5000), DB::table('report_notes')->where('id', $note)->value('body'));
    }

    // ── refusals ────────────────────────────────────────────────────────────

    /** The author succeeds where the other user is answered 404 — otherwise a missing route passes this. */
    public function test_that_another_users_report_is_not_found(): void
    {
        $author = $this->user(RoleName::IndoorSales, 'a');
        $intruder = $this->user(RoleName::IndoorSales, 'b');
        $nile = $this->customer('Nile Trading', $author);
        $mine = $this->customer('Delta Foods', $intruder);
        $id = $this->create($author);
        $row = $this->postJson($this->followUps($id), ['customer_id' => $nile, 'note' => 'Mine.'], $this->bearer($author))
            ->assertStatus(201)->json('data.id');
        $note = $this->postJson($this->notes($id), ['body' => 'Mine.'], $this->bearer($author))
            ->assertStatus(201)->json('data.id');
        self::assertIsString($row);
        self::assertIsString($note);

        $this->postJson($this->followUps($id), ['customer_id' => $mine, 'note' => 'x'], $this->bearer($intruder))->assertStatus(404);
        $this->patchJson($this->followUps($id).'/'.$row, ['note' => 'x'], $this->bearer($intruder))->assertStatus(404);
        $this->deleteJson($this->followUps($id).'/'.$row, [], $this->bearer($intruder))->assertStatus(404);
        $this->postJson($this->notes($id), ['body' => 'x'], $this->bearer($intruder))->assertStatus(404);
        $this->patchJson($this->notes($id).'/'.$note, ['body' => 'x'], $this->bearer($intruder))->assertStatus(404);
        $this->deleteJson($this->notes($id).'/'.$note, [], $this->bearer($intruder))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'resource_not_found');

        // A row of another report is not this report's.
        $other = $this->create($intruder);
        $this->patchJson($this->followUps($other).'/'.$row, ['note' => 'x'], $this->bearer($intruder))->assertStatus(404);
        $this->deleteJson($this->notes($other).'/'.$note, [], $this->bearer($intruder))->assertStatus(404);

        $this->assertDatabaseHas('report_follow_ups', ['id' => $row, 'note' => 'Mine.', 'deleted_at' => null]);
        $this->assertDatabaseHas('report_notes', ['id' => $note, 'body' => 'Mine.', 'deleted_at' => null]);
    }

    /** `D-131`: only while the report is a draft or returned. */
    public function test_that_a_submitted_report_is_not_editable(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $nile = $this->customer('Nile Trading', $author);
        [$id, $row, $note] = $this->withRows($author, $nile);
        $this->submit($author, $id, [$manager])->assertStatus(200);

        $this->assertNotEditable($author, $id, $row, $note, $nile);
    }

    /** §11.4: "After Acknowledged the report is permanently read-only, even for its author." */
    public function test_that_an_acknowledged_report_is_not_editable(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $nile = $this->customer('Nile Trading', $author);
        [$id, $row, $note] = $this->withRows($author, $nile);
        $this->submit($author, $id, [$manager])->assertStatus(200);
        $this->patchJson(self::ENDPOINT.'/'.$id.'/acknowledge', [], [...$this->bearer($manager), 'If-Match' => $this->etag($id)])
            ->assertStatus(200);

        $this->assertNotEditable($author, $id, $row, $note, $nile);
    }

    /**
     * `D-137`: follow-ups are the daily report's (`D-131`); on any other type the API refuses all
     * three writes with 422 — nothing written, nothing audited. The weekly draft is the only
     * other type there is; the row it holds is placed straight in the table.
     */
    public function test_that_follow_up_writes_on_a_weekly_report_are_422_and_leave_nothing_behind(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $nile = $this->customer('Nile Trading', $author);
        [$id, $row] = $this->weekly($author, $nile);
        $calls = [
            fn () => $this->postJson($this->followUps($id), ['customer_id' => $nile, 'note' => 'Not on a weekly.'], $this->bearer($author)),
            fn () => $this->patchJson($this->followUps($id).'/'.$row, ['note' => 'Not on a weekly.'], $this->bearer($author)),
            fn () => $this->deleteJson($this->followUps($id).'/'.$row, [], $this->bearer($author)),
        ];
        foreach ($calls as $call) {
            $call()->assertStatus(422)
                ->assertJsonPath('error.code', 'business_rule_blocked')
                ->assertJsonPath('error.details.0.code', 'report_follow_up_not_allowed')
                ->assertJsonPath('error.details.0.field', 'type');
        }

        self::assertSame(1, DB::table('report_follow_ups')->where('report_id', $id)->count(), 'D-137: no row was added.');
        $this->assertDatabaseHas('report_follow_ups', ['id' => $row, 'note' => 'Placed by the test.', 'deleted_at' => null]);
        self::assertSame(0, DB::table('audit_log')->where('event', 'like', 'REPORT_FOLLOW_UP_%')->count(), 'D-137: a refused write is not audited.');
    }

    /**
     * `D-137`: the type refusal must not leak a weekly report to a stranger — reach comes first, so anyone
     * but the author gets the report's own 404, not the 422 that would confirm it exists and is weekly.
     */
    public function test_that_a_non_author_gets_404_not_422_on_follow_up_writes_to_a_weekly_report(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        [$id] = $this->weekly($author, $this->customer('Nile Trading', $author));
        $row = (string) Str::uuid7();

        foreach ([$this->user(RoleName::IndoorSales, 'other'), $this->user(RoleName::Manager)] as $stranger) {
            $this->postJson($this->followUps($id), ['customer_id' => $this->customer('Delta Foods', $stranger), 'note' => 'Hello.'], $this->bearer($stranger))->assertStatus(404);
            $this->patchJson($this->followUps($id).'/'.$row, ['note' => 'Hello.'], $this->bearer($stranger))->assertStatus(404);
            $this->deleteJson($this->followUps($id).'/'.$row, [], $this->bearer($stranger))->assertStatus(404);
        }
    }

    /** `D-137`: "notes stay allowed on every type". */
    public function test_that_a_note_on_a_weekly_report_is_still_accepted(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        [$id] = $this->weekly($author, $this->customer('Nile Trading', $author));

        $this->postJson($this->notes($id), ['body' => 'A weekly note.'], $this->bearer($author))
            ->assertStatus(201)
            ->assertJsonPath('data.body', 'A weekly note.');
        $this->assertDatabaseHas('report_notes', ['report_id' => $id, 'body' => 'A weekly note.', 'deleted_at' => null]);
    }

    /** `D-131`: a returned report is editable again — its rows with it. */
    public function test_that_a_returned_report_is_editable_again(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $nile = $this->customer('Nile Trading', $author);
        [$id, $row, $note] = $this->withRows($author, $nile);
        $this->submit($author, $id, [$manager])->assertStatus(200);
        $this->patchJson(self::ENDPOINT.'/'.$id.'/return', ['reason' => 'Add Delta.'], [...$this->bearer($manager), 'If-Match' => $this->etag($id)])
            ->assertStatus(200);

        $this->patchJson($this->followUps($id).'/'.$row, ['note' => 'Edited after return.'], $this->bearer($author))->assertStatus(200);
        $this->patchJson($this->notes($id).'/'.$note, ['body' => 'Edited after return.'], $this->bearer($author))->assertStatus(200);
        $this->postJson($this->notes($id), ['body' => 'Added after return.'], $this->bearer($author))->assertStatus(201);
    }

    /**
     * `D-131`, gap 3b: a recipient never reads the live rows. A draft never sent is
     * a 404 to them; a returned report shows them the submitted copy, not the rows
     * the author edits or adds after the return.
     */
    public function test_that_a_recipient_never_reads_a_drafts_live_rows(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $nile = $this->customer('Nile Trading', $author);
        $delta = $this->customer('Delta Foods', $author);

        // Login is limited to 10 a minute per user (`identity.rate_limit.login`): the draft takes one.
        $this->getJson(self::ENDPOINT.'/received/'.$this->create($author), $this->bearer($manager))->assertStatus(404);

        [$id, $row, $note] = $this->withRows($author, $nile);
        $this->submit($author, $id, [$manager])->assertStatus(200);
        $this->patchJson(self::ENDPOINT.'/'.$id.'/return', ['reason' => 'Add Delta.'], [...$this->bearer($manager), 'If-Match' => $this->etag($id)])
            ->assertStatus(200);
        $this->patchJson($this->followUps($id).'/'.$row, ['note' => 'Live edit.'], $this->bearer($author))->assertStatus(200);
        $this->postJson($this->followUps($id), ['customer_id' => $delta, 'note' => 'Live add.'], $this->bearer($author))->assertStatus(201);
        $this->patchJson($this->notes($id).'/'.$note, ['body' => 'Live edit.'], $this->bearer($author))->assertStatus(200);
        $this->postJson($this->notes($id), ['body' => 'Live add.'], $this->bearer($author))->assertStatus(201);

        $read = $this->getJson(self::ENDPOINT.'/received/'.$id, $this->bearer($manager))->assertStatus(200);
        self::assertSame(['Before submit.'], $read->json('data.follow_ups.*.note'));
        self::assertSame(['Before submit.'], $read->json('data.notes.*.body'));
    }

    public function test_that_an_unauthenticated_caller_is_refused(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $nile = $this->customer('Nile Trading', $author);
        $id = $this->create($author);
        $row = (string) Str::uuid7();
        // The request guard keeps the user it resolved for create(); drop it so the calls below carry no identity.
        $this->app['auth']->forgetGuards();

        $this->postJson($this->followUps($id), ['customer_id' => $nile, 'note' => 'x'])->assertStatus(401);
        $this->patchJson($this->followUps($id).'/'.$row, ['note' => 'x'])->assertStatus(401);
        $this->deleteJson($this->followUps($id).'/'.$row)->assertStatus(401);
        $this->postJson($this->notes($id), ['body' => 'x'])->assertStatus(401);
        $this->patchJson($this->notes($id).'/'.$row, ['body' => 'x'])->assertStatus(401);
        $this->deleteJson($this->notes($id).'/'.$row)->assertStatus(401);
    }

    /** `D-131` replaces the one notes box; its old field is refused, as `D-122` did for customers. */
    public function test_that_the_old_notes_field_is_refused(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $id = $this->create($author);

        $this->patchJson(self::ENDPOINT.'/'.$id, ['notes' => 'The old box.'], [...$this->bearer($author), 'If-Match' => $this->etag($id)])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'notes');

        self::assertNull(DB::table('reports')->where('id', $id)->value('notes'));
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function assertNotEditable(User $author, string $id, string $row, string $note, string $customer): void
    {
        $calls = [
            fn () => $this->postJson($this->followUps($id), ['customer_id' => $customer, 'note' => 'Too late.'], $this->bearer($author)),
            fn () => $this->patchJson($this->followUps($id).'/'.$row, ['note' => 'Too late.'], $this->bearer($author)),
            fn () => $this->deleteJson($this->followUps($id).'/'.$row, [], $this->bearer($author)),
            fn () => $this->postJson($this->notes($id), ['body' => 'Too late.'], $this->bearer($author)),
            fn () => $this->patchJson($this->notes($id).'/'.$note, ['body' => 'Too late.'], $this->bearer($author)),
            fn () => $this->deleteJson($this->notes($id).'/'.$note, [], $this->bearer($author)),
        ];
        foreach ($calls as $call) {
            $call()->assertStatus(409)->assertJsonPath('error.details.0.code', 'report_not_editable');
        }

        $this->assertDatabaseHas('report_follow_ups', ['id' => $row, 'note' => 'Before submit.', 'deleted_at' => null]);
        $this->assertDatabaseHas('report_notes', ['id' => $note, 'body' => 'Before submit.', 'deleted_at' => null]);
        self::assertSame(1, DB::table('report_follow_ups')->where('report_id', $id)->count());
        self::assertSame(1, DB::table('report_notes')->where('report_id', $id)->count());
    }

    /** @return array{string, string} a weekly draft of the author and a follow-up on it, written straight to the tables */
    private function weekly(User $author, string $customer): array
    {
        $id = (string) Str::uuid7();
        $row = (string) Str::uuid7();
        DB::table('reports')->insert([
            'id' => $id, 'code' => 'RPT-WK-2026-'.substr($id, -6), 'type' => 'weekly', 'status' => 'draft',
            'author_id' => $author->id, 'period_start' => '2026-10-04', 'period_end' => '2026-10-08',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('report_follow_ups')->insert([
            'id' => $row, 'report_id' => $id, 'customer_id' => $customer, 'note' => 'Placed by the test.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$id, $row];
    }

    /** @return array{string, string, string} the report, one follow-up and one note, written while a draft */
    private function withRows(User $author, string $customer): array
    {
        $id = $this->create($author);
        $row = $this->postJson($this->followUps($id), ['customer_id' => $customer, 'note' => 'Before submit.'], $this->bearer($author))
            ->assertStatus(201)->json('data.id');
        $note = $this->postJson($this->notes($id), ['body' => 'Before submit.'], $this->bearer($author))
            ->assertStatus(201)->json('data.id');
        self::assertIsString($row);
        self::assertIsString($note);

        return [$id, $row, $note];
    }

    /** @return array{old: ?array<string, mixed>, new: array<string, mixed>} */
    private function audit(string $event, string $entityId, User $user): array
    {
        $entry = DB::table('audit_log')->where('event', $event)->where('entity_id', $entityId)->first();
        self::assertNotNull($entry, "AUD-01: no {$event} entry.");
        self::assertSame($user->id, $entry->user_id);

        $old = is_string($entry->old_values) ? json_decode($entry->old_values, true) : null;
        $new = is_string($entry->new_values) ? json_decode($entry->new_values, true) : [];
        self::assertTrue($old === null || is_array($old));
        self::assertIsArray($new);

        /** @var array{old: ?array<string, mixed>, new: array<string, mixed>} */
        return ['old' => $old, 'new' => $new];
    }

    private function followUps(string $reportId): string
    {
        return self::ENDPOINT.'/'.$reportId.'/follow-ups';
    }

    private function notes(string $reportId): string
    {
        return self::ENDPOINT.'/'.$reportId.'/notes';
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
            self::ENDPOINT.'/'.$id.'/submit',
            ['recipient_ids' => array_map(static fn (User $u): string => $u->id, $recipients)],
            [...$this->bearer($author), 'If-Match' => $this->etag($id)],
        );
    }

    private function create(User $author): string
    {
        // 7.3: a second same-day daily is a warning; these fixtures mean a second report, so they confirm it.
        $id = $this->postJson(self::ENDPOINT, ['type' => 'daily', 'confirm_duplicate' => true], $this->bearer($author))
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
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(201)->json('data.token');
        self::assertIsString($token);

        return ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => Uuid::uuid4()->toString()];
    }
}
