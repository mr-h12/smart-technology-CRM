<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Pdf\Application\Generation\RenderReportPdf;
use App\Modules\Pdf\Domain\Contracts\PdfRendererInterface;
use App\Modules\Storage\Domain\AttachmentParent;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Storage\RemovesOnlyFilesItStored;
use Tests\TestCase;

/**
 * Module 13, slice 7 · 7.4 — what an archived report (`J-08`, §11.4: "still viewable
 * and exportable") looks like to its readers; the owner's rulings of 2026-10-18:
 *
 * - `GET /reports` ("mine") and `GET /reports/received` hide archived reports by default;
 *   `include_archived=1` shows them. (The flag is `include_archived`: no list in the
 *   project has an "include" flag, and `filter[is_archived]` on customers *selects* the
 *   archived rows, which is not what a list that merely adds them wants.)
 * - An archived report still opens — the author's `GET /reports/{id}`, the recipient's
 *   received detail (which does not mark it Viewed) — and its stored PDF still downloads.
 * - It is not editable: writes answer the rules that already hold for a non-editable report.
 *
 * The status is written straight to the row: J-08 itself is `ArchiveOldReportsTest`'s.
 */
final class ReportArchiveReadingTest extends TestCase
{
    use RefreshDatabase;
    use RemovesOnlyFilesItStored;

    private const PASSWORD = 'Passw0rd123';

    private const ENDPOINT = '/api/v1/reports';

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n";

    /** @var array<string, string> one login per user per test — the login limit counts every call */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(9, 0));
        $this->rememberStoredFiles(AttachmentParent::Report);

        Queue::fake();
        $this->app->instance(PdfRendererInterface::class, new ReportPdfFakeRenderer(self::PDF));
    }

    protected function tearDown(): void
    {
        $this->removeFilesStoredSince(AttachmentParent::Report);

        parent::tearDown();
    }

    // ── the lists ───────────────────────────────────────────────────────────

    public function test_that_my_reports_hides_the_archived_by_default_and_shows_them_with_the_flag(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $live = $this->row($author, 'submitted', '2026-10-14', [$manager]);
        $archived = $this->row($author, 'archived', '2026-04-10', [$manager]);

        $default = $this->getJson(self::ENDPOINT, $this->bearer($author))->assertStatus(200);
        self::assertSame([$live], array_column($this->rows($default), 'id'), 'The control (the live report) is listed; the archived one is not.');
        $default->assertJsonPath('meta.pagination.total', 1);

        $this->getJson(self::ENDPOINT.'?include_archived=0', $this->bearer($author))->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1);

        $with = $this->getJson(self::ENDPOINT.'?include_archived=1', $this->bearer($author))->assertStatus(200);
        $rows = $this->rows($with);
        self::assertSame([$live, $archived], array_column($rows, 'id'));
        self::assertSame('archived', $rows[1]['status']);
        $with->assertJsonPath('meta.pagination.total', 2);
    }

    public function test_that_received_reports_hides_the_archived_by_default_and_shows_them_with_the_flag(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $live = $this->row($author, 'submitted', '2026-10-14', [$manager]);
        $archived = $this->row($author, 'archived', '2026-04-10', [$manager]);

        $default = $this->getJson(self::ENDPOINT.'/received', $this->bearer($manager))->assertStatus(200);
        self::assertSame([$live], array_column($this->rows($default), 'id'), 'The control (the live report) is listed; the archived one is not.');
        $default->assertJsonPath('meta.pagination.total', 1);

        $this->getJson(self::ENDPOINT.'/received?include_archived=0', $this->bearer($manager))->assertStatus(200)
            ->assertJsonPath('meta.pagination.total', 1);

        $with = $this->getJson(self::ENDPOINT.'/received?include_archived=1', $this->bearer($manager))->assertStatus(200);
        $rows = $this->rows($with);
        self::assertSame([$live, $archived], array_column($rows, 'id'));
        self::assertSame('archived', $rows[1]['status']);
        $with->assertJsonPath('meta.pagination.total', 2);
    }

    /** `SEC-07`: the flag widens a list by status, never by reach — another manager still sees none of it. */
    public function test_that_the_flag_does_not_widen_reach(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager, 'a');
        $other = $this->user(RoleName::Manager, 'b');
        $this->row($author, 'archived', '2026-04-10', [$manager]);

        self::assertCount(1, $this->rows($this->getJson(self::ENDPOINT.'/received?include_archived=1', $this->bearer($manager))->assertStatus(200)), 'The control: the recipient sees it.');
        self::assertSame([], $this->rows($this->getJson(self::ENDPOINT.'/received?include_archived=1', $this->bearer($other))->assertStatus(200)));
        self::assertSame([], $this->rows($this->getJson(self::ENDPOINT.'?include_archived=1', $this->bearer($manager))->assertStatus(200)), 'A recipient is not the author.');
    }

    // ── opening ─────────────────────────────────────────────────────────────

    public function test_that_an_archived_report_still_opens_for_its_author_and_its_recipient_and_is_not_marked_viewed(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager, 'a');
        $other = $this->user(RoleName::Manager, 'b');
        $id = $this->submitted($author, [$manager], 'Submitted words.');
        $this->archive($id);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))
            ->assertStatus(200)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.notes.0.body', 'Submitted words.')
            ->assertJsonPath('data.recipients.0.id', $manager->id);
        $this->getJson(self::ENDPOINT.'/received/'.$id, $this->bearer($manager))
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.notes.0.body', 'Submitted words.');

        self::assertSame('archived', DB::table('reports')->where('id', $id)->value('status'), 'D-127: the first open marks Viewed only a Submitted report.');
        self::assertSame(0, DB::table('audit_log')->where('event', 'REPORT_VIEWED')->where('entity_id', $id)->count());
        // The control: this is the row-level rule, not an open door.
        $this->getJson(self::ENDPOINT.'/received/'.$id, $this->bearer($other))->assertStatus(404);
    }

    /** A Missed report has no frozen copy: it still has to open once archived. */
    public function test_that_an_archived_missed_report_still_opens_for_its_author_and_stops_being_editable(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->row($author, 'missed', '2026-04-10', []);

        $this->postJson(self::ENDPOINT.'/'.$id.'/notes', ['body' => 'Late words.'], $this->bearer($author))->assertStatus(201);    // the control: Missed is editable
        $this->archive($id);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'archived')
            ->assertJsonPath('data.notes.0.body', 'Late words.');
        $this->postJson(self::ENDPOINT.'/'.$id.'/notes', ['body' => 'Too late.'], $this->bearer($author))
            ->assertStatus(409)
            ->assertJsonPath('error.details.0.code', 'report_not_editable');
        $this->patchJson(self::ENDPOINT.'/'.$id.'/submit', ['recipient_ids' => [$manager->id]], [...$this->bearer($author), 'If-Match' => $this->etag($id)])
            ->assertStatus(409)
            ->assertJsonPath('error.details.0.code', 'report_not_editable');    // 7.3: one answer for a report that is no longer editable

        self::assertSame('archived', DB::table('reports')->where('id', $id)->value('status'));
        self::assertSame(1, DB::table('report_notes')->where('report_id', $id)->count());
    }

    public function test_that_a_recipient_cannot_acknowledge_or_return_an_archived_report(): void
    {
        $manager = $this->user(RoleName::Manager);
        $archived = $this->submitted($this->user(RoleName::IndoorSales, 'a'), [$manager]);
        $live = $this->submitted($this->user(RoleName::IndoorSales, 'b'), [$manager]);
        $this->archive($archived);

        $this->patchJson(self::ENDPOINT.'/'.$live.'/acknowledge', [], [...$this->bearer($manager), 'If-Match' => $this->etag($live)])->assertStatus(200);    // the control

        $this->patchJson(self::ENDPOINT.'/'.$archived.'/acknowledge', [], [...$this->bearer($manager), 'If-Match' => $this->etag($archived)])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.code', 'report_not_awaiting_answer');
        $this->patchJson(self::ENDPOINT.'/'.$archived.'/return', ['reason' => 'Too old.'], [...$this->bearer($manager), 'If-Match' => $this->etag($archived)])
            ->assertStatus(422)
            ->assertJsonPath('error.details.0.code', 'report_not_awaiting_answer');
        self::assertSame('archived', DB::table('reports')->where('id', $archived)->value('status'));
    }

    // ── the stored PDF ──────────────────────────────────────────────────────

    public function test_that_the_stored_pdf_of_an_archived_report_still_downloads_for_its_author_and_recipient(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager, 'a');
        $other = $this->user(RoleName::Manager, 'b');
        $id = $this->submitted($author, [$manager]);
        $generation = DB::table('report_pdf_generations')->where('report_id', $id)->where('version', 1)->value('id');
        self::assertIsString($generation, 'The submit queued no v1 generation.');
        $this->app->make(RenderReportPdf::class)->handle($generation, 1);
        $this->archive($id);

        foreach ([$author, $manager] as $reader) {
            $response = $this->get(self::ENDPOINT.'/'.$id.'/versions/1/pdf/download', $this->bearer($reader));
            $response->assertOk();
            self::assertSame(self::PDF, $response->streamedContent());
        }
        self::assertSame(2, DB::table('audit_log')->where('event', 'REPORT_PDF_DOWNLOADED')->where('entity_id', $id)->count());
        // The control: archiving did not open the file to a stranger.
        $this->get(self::ENDPOINT.'/'.$id.'/versions/1/pdf/download', $this->bearer($other))->assertNotFound();
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /**
     * @param  TestResponse<\Illuminate\Http\JsonResponse>  $response
     * @return list<array<string, mixed>>
     */
    private function rows(TestResponse $response): array
    {
        $rows = $response->json('data');
        self::assertIsArray($rows);

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    private function archive(string $id): void
    {
        DB::table('reports')->where('id', $id)->update(['status' => 'archived', 'version_token' => DB::raw('version_token + 1')]);
    }

    private function etag(string $id): string
    {
        $token = DB::table('reports')->where('id', $id)->value('version_token');
        self::assertIsInt($token);

        return 'report:'.$id.':'.$token;
    }

    /**
     * A report written straight to the table, with its recipients (the lists' fixture).
     *
     * @param  list<User>  $recipients
     */
    private function row(User $author, string $status, string $day, array $recipients): string
    {
        $id = (string) Str::uuid7();
        DB::table('reports')->insert([
            'id' => $id, 'code' => 'RPT-DY-2026-'.substr($id, -6), 'type' => 'daily', 'status' => $status,
            'author_id' => $author->id, 'period_start' => $day, 'period_end' => $day,
            'submitted_at' => $status === 'missed' ? null : $day.' 09:00:00+00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($recipients as $recipient) {
            DB::table('report_recipients')->insert([
                'id' => (string) Str::uuid7(), 'report_id' => $id, 'user_id' => $recipient->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    /**
     * A report made and submitted through the API, so it has its frozen copy and its PDF generation.
     *
     * @param  list<User>  $recipients
     */
    private function submitted(User $author, array $recipients, ?string $note = null): string
    {
        $id = $this->postJson(self::ENDPOINT, ['type' => 'daily'], $this->bearer($author))->assertStatus(201)->json('data.id');
        self::assertIsString($id);
        if ($note !== null) {
            $this->postJson(self::ENDPOINT.'/'.$id.'/notes', ['body' => $note], $this->bearer($author))->assertStatus(201);
        }
        $this->patchJson(
            self::ENDPOINT.'/'.$id.'/submit',
            ['recipient_ids' => array_map(static fn (User $u): string => $u->id, $recipients)],
            [...$this->bearer($author), 'If-Match' => $this->etag($id)],
        )->assertStatus(200);

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
            $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
                ->assertStatus(201)->json('data.token');
            self::assertIsString($token);
            $this->tokens[$user->id] = $token;
        }

        return ['Authorization' => 'Bearer '.$this->tokens[$user->id], 'Idempotency-Key' => (string) Str::uuid()];
    }
}
