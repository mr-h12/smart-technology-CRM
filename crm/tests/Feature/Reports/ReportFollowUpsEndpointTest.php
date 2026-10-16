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
        $id = $this->postJson(self::ENDPOINT, ['type' => 'daily'], $this->bearer($author))
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
