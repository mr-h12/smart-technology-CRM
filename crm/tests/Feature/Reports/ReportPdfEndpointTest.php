<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Pdf\Application\Generation\RenderReportPdf;
use App\Modules\Pdf\Domain\Contracts\PdfRendererInterface;
use App\Modules\Pdf\Presentation\RenderReportPdfJob;
use App\Modules\Reports\Application\ReportAccess;
use App\Modules\Storage\Domain\AttachmentParent;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Storage\RemovesOnlyFilesItStored;
use Tests\TestCase;

/**
 * Module 13, slice 4 · 4.1 red — who reaches a submitted version's PDF (`D-132`,
 * spec §4.3): `GET /api/v1/reports/{report}/versions/{version}/pdf` and
 * `GET /api/v1/files/{file}/download`.
 *
 * - A caller reaches it only with `report.export` (a refusal is `403`, from the route)
 *   and as the report's author or a live recipient (anything else is `404`, as opening the report is).
 * - No sign-in is `401`. A draft, a missing version and a version with no generation row are `404`.
 * - `Team` and `Out` open nothing by themselves: a Team Leader who is not a recipient is `404`, even one who
 *   leads the author (7.5d).
 */
final class ReportPdfEndpointTest extends TestCase
{
    use InsertsMonthlyReport;
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

    // ── status ──────────────────────────────────────────────────────────────

    public function test_that_the_author_and_a_recipient_read_a_queued_version(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($author, [$manager]);

        foreach ([$author, $manager] as $reader) {
            $this->pdfStatus($reader, $id, 1)
                ->assertStatus(200)
                ->assertJsonPath('data.status', 'queued')
                ->assertJsonPath('data.file_id', null)
                ->assertJsonPath('data.failure_reason', null)
                ->assertJsonPath('data.finished_at', null)
                ->assertJsonStructure(['data' => ['status', 'file_id', 'failure_reason', 'finished_at']]);
        }
    }

    public function test_that_a_completed_version_answers_its_file_and_a_failed_one_its_reason(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($author, [$manager]);
        $generation = $this->generation($id, 1);
        $this->render($generation);

        $fileId = DB::table('report_pdf_generations')->where('id', $generation)->value('file_id');
        self::assertIsString($fileId, 'The render did not complete, so there is no file to answer.');
        $this->pdfStatus($manager, $id, 1)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.file_id', $fileId)
            ->assertJsonPath('data.failure_reason', null);
        self::assertNotNull($this->pdfStatus($manager, $id, 1)->json('data.finished_at'));

        DB::table('report_pdf_generations')->where('id', $generation)->update([
            'status' => 'failed', 'file_id' => null, 'failure_reason' => 'The PDF could not be rendered.',
        ]);
        $this->pdfStatus($author, $id, 1)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.file_id', null)
            ->assertJsonPath('data.failure_reason', 'The PDF could not be rendered.');
    }

    /** @return array<string, array{RoleName}> */
    public static function outsiders(): array
    {
        return [
            'another sales employee' => [RoleName::OutdoorSales],
            'Procurement (Own: not its report)' => [RoleName::Procurement],
            'a Manager the report was not sent to' => [RoleName::Manager],
            'the CEO, when not a recipient' => [RoleName::Ceo],
            'a Team Leader who is not a recipient (D-129)' => [RoleName::TeamLeader],
        ];
    }

    #[DataProvider('outsiders')]
    public function test_that_anyone_else_gets_a_404_for_the_status_and_the_download(RoleName $role): void
    {
        $author = $this->user(RoleName::IndoorSales);
        // The recipient is a second Manager, so the Manager outsider above is a different user.
        $recipient = $this->user(RoleName::Manager, 'recipient');
        $id = $this->submitted($author, [$recipient]);
        $generation = $this->generation($id, 1);
        $this->render($generation);
        $fileId = DB::table('report_pdf_generations')->where('id', $generation)->value('file_id');
        self::assertIsString($fileId, 'The render did not complete.');

        $outsider = $this->user($role, 'outsider');

        $this->pdfStatus($outsider, $id, 1)->assertStatus(404)->assertJsonPath('error.code', 'resource_not_found');
        $this->get('/api/v1/files/'.$fileId.'/download', $this->bearer($outsider))->assertNotFound();
    }

    /** 7.5d, gap 5: leading the author is not being sent the report — the PDF stays a 404. */
    public function test_that_a_team_leader_without_a_recipient_row_cannot_open_a_members_report(): void
    {
        $leader = $this->user(RoleName::TeamLeader);
        $member = $this->user(RoleName::IndoorSales, 'member');
        $member->forceFill(['team_leader_id' => $leader->id])->save();
        $id = $this->submitted($member, [$this->user(RoleName::Manager)]);
        $generation = $this->generation($id, 1);
        $this->render($generation);
        $fileId = DB::table('report_pdf_generations')->where('id', $generation)->value('file_id');
        self::assertIsString($fileId, 'The render did not complete.');

        // The control: the author reaches it.
        $this->pdfStatus($member, $id, 1)->assertStatus(200);

        $this->pdfStatus($leader, $id, 1)->assertStatus(404)->assertJsonPath('error.code', 'resource_not_found');
        $this->pdfDownload($leader, $id, 1)->assertNotFound();
        $this->get('/api/v1/files/'.$fileId.'/download', $this->bearer($leader))->assertNotFound();
    }

    public function test_that_a_caller_who_is_not_signed_in_gets_a_401(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $id = $this->submitted($author, [$this->user(RoleName::Manager)]);
        // The request guard keeps the user it resolved before; drop it so the call carries no identity.
        $this->app['auth']->forgetGuards();

        $this->getJson(self::ENDPOINT.'/'.$id.'/versions/1/pdf')->assertStatus(401);
    }

    /** `report.export` is checked by the route, ahead of any row rule: a role with no grants is `403`, not `404`. */
    public function test_that_a_role_without_report_export_gets_a_403(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $id = $this->submitted($author, [$this->user(RoleName::Manager)]);

        $user = $this->userWithoutGrants();

        $this->pdfStatus($user, $id, 1)->assertStatus(403);
    }

    public function test_that_a_draft_a_missing_version_and_a_version_with_no_generation_are_404(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);

        $draft = $this->create($author);
        $this->pdfStatus($author, $draft, 1)->assertStatus(404);

        $id = $this->submitted($author, [$manager]);
        $this->pdfStatus($author, $id, 9)->assertStatus(404);

        // A version submitted before slice 4 has no row, and nothing back-fills it (the owner's answer (a)).
        DB::table('report_pdf_generations')->where('report_id', $id)->delete();
        $this->pdfStatus($author, $id, 1)->assertStatus(404);
        $this->pdfStatus($manager, $id, 1)->assertStatus(404);
    }

    // ── download ────────────────────────────────────────────────────────────

    /** The files route is the only download (`D-38`); `Report` joins its composite permission (spec §4.3). */
    public function test_that_the_author_and_a_recipient_download_the_stored_pdf(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($author, [$manager]);
        $generation = $this->generation($id, 1);
        $this->render($generation);
        $fileId = DB::table('report_pdf_generations')->where('id', $generation)->value('file_id');
        self::assertIsString($fileId, 'The render did not complete.');

        foreach ([$author, $manager] as $reader) {
            $response = $this->get('/api/v1/files/'.$fileId.'/download', $this->bearer($reader));

            $response->assertOk();
            self::assertSame(self::PDF, $response->streamedContent());
        }
    }

    /** Half of `ReportPdfAttachmentPermission::mayView` is `report.export`: losing it after the render closes the download. */
    public function test_that_an_author_who_loses_report_export_gets_a_404_on_the_download(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $id = $this->submitted($author, [$this->user(RoleName::Manager)]);
        $generation = $this->generation($id, 1);
        $this->render($generation);
        $fileId = DB::table('report_pdf_generations')->where('id', $generation)->value('file_id');
        self::assertIsString($fileId);
        $this->get('/api/v1/files/'.$fileId.'/download', $this->bearer($author))->assertOk();

        DB::table('role_permissions')
            ->where('role_id', $author->role_id)
            ->whereIn('permission_id', DB::table('permissions')->where('resource', 'report')->where('action', 'export')->pluck('id'))
            ->update(['deleted_at' => now()]);

        $this->get('/api/v1/files/'.$fileId.'/download', $this->bearer($author))->assertNotFound();
    }

    /** A recipient removed after the submit (the row soft-deleted, as `D-127`'s replace does) loses both reads. */
    public function test_that_a_recipient_removed_after_submit_gets_a_404_on_the_status_and_the_download(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($author, [$manager]);
        $generation = $this->generation($id, 1);
        $this->render($generation);
        $fileId = DB::table('report_pdf_generations')->where('id', $generation)->value('file_id');
        self::assertIsString($fileId);
        $this->pdfStatus($manager, $id, 1)->assertOk();

        DB::table('report_recipients')->where('report_id', $id)->where('user_id', $manager->id)->update(['deleted_at' => now()]);

        $this->pdfStatus($manager, $id, 1)->assertStatus(404);
        $this->get('/api/v1/files/'.$fileId.'/download', $this->bearer($manager))->assertNotFound();
    }

    /** @return array<string, array{0: string}> */
    public static function reportTypes(): array
    {
        return ['daily' => ['daily'], 'monthly' => ['monthly']];
    }

    /**
     * `OpenAPI §3.2` ("export/download permission must never exceed view permission"), §3.10: a recipient reaches a
     * report only while holding `report.view_received`. The Manager it was sent to, moved to Indoor Sales — no
     * *view received* (§3.10), but `report.export` at Own, so the PDF routes' own gate lets the call through — gets
     * 404 on the status, on both downloads and, once the render has failed, on the retry — and neither a download
     * nor a retry is audited. The received read answers 403 at its route (`permission:report.view_received`) before
     * `ReportAccess` runs; `reachReceived` is pinned below.
     */
    #[DataProvider('reportTypes')]
    public function test_that_a_recipient_moved_to_a_role_without_view_received_gets_404_on_the_pdf_status_and_downloads(string $type): void
    {
        $recipient = $this->user(RoleName::Manager, 'recipient');
        $id = $this->submittedOfType($type, $recipient);
        $generation = $this->generation($id, 1);
        $this->render($generation);
        $fileId = DB::table('report_pdf_generations')->where('id', $generation)->value('file_id');
        self::assertIsString($fileId, 'The render did not complete.');
        $this->pdfStatus($recipient, $id, 1)->assertOk();

        $this->moveTo($recipient, RoleName::IndoorSales);

        $this->getJson(self::ENDPOINT.'/received/'.$id, $this->bearer($recipient))->assertStatus(403);
        $this->assertNoPdfReach($recipient, $id, $generation, $fileId);
    }

    /** `OpenAPI §3.2`: the one rule every received route shares — `ReportAccess::reachReceived` refuses a recipient without `report.view_received`. */
    #[DataProvider('reportTypes')]
    public function test_that_reach_received_refuses_a_recipient_who_no_longer_holds_view_received(string $type): void
    {
        $recipient = $this->user(RoleName::Manager, 'recipient');
        $id = $this->submittedOfType($type, $recipient);
        $this->moveTo($recipient, RoleName::IndoorSales);

        $this->assertThrowsNotFound(
            fn () => $this->app->make(ReportAccess::class)->reachReceived($id, $recipient->id),
            "OpenAPI §3.2: a {$type} report's recipient without report.view_received",
        );
    }

    /**
     * `D-141` (B-1): the author reaches a monthly report's PDF only while an active Manager holding `deal.view` and
     * `quotation.view` at All — moved to Indoor Sales (which keeps `report.export` at Own), the author gets 404 on
     * the stored PDF's status, both downloads and the retry, none of them audited, as on the report itself
     * (`MonthlyReportsGenerationTest`).
     */
    public function test_that_a_monthly_author_moved_out_of_manager_gets_404_on_the_pdf_status_and_downloads(): void
    {
        $author = $this->user(RoleName::Manager);
        $id = $this->submitted($author, [$this->user(RoleName::Ceo)], $this->monthlyRow($author));
        $generation = $this->generation($id, 1);
        $this->render($generation);
        $fileId = DB::table('report_pdf_generations')->where('id', $generation)->value('file_id');
        self::assertIsString($fileId, 'The render did not complete.');
        $this->pdfStatus($author, $id, 1)->assertOk();

        $this->moveTo($author, RoleName::IndoorSales);

        $this->assertNoPdfReach($author, $id, $generation, $fileId);
    }

    /**
     * Out of reach, a v1 PDF answers 404 on its status and both downloads; failed, it answers 404 on the retry,
     * stays failed and queues nothing; no download and no retry is audited.
     */
    private function assertNoPdfReach(User $caller, string $id, string $generation, string $fileId): void
    {
        $this->pdfStatus($caller, $id, 1)->assertStatus(404)->assertJsonPath('error.code', 'resource_not_found');
        $this->pdfDownload($caller, $id, 1)->assertStatus(404);
        $this->get('/api/v1/files/'.$fileId.'/download', $this->bearer($caller))->assertNotFound();
        self::assertSame([], $this->audits('REPORT_PDF_DOWNLOADED', $id), 'A refused download writes no audit entry.');

        $this->markFailed($generation);
        Queue::fake();   // forget the job the submit pushed: only a retry would push another
        $this->pdfRetry($caller, $id, 1)->assertStatus(404)->assertJsonPath('error.code', 'resource_not_found');
        self::assertSame('failed', DB::table('report_pdf_generations')->where('id', $generation)->value('status'));
        self::assertSame([], $this->audits('REPORT_PDF_RETRIED', $id), 'A refused retry writes no audit entry.');
        Queue::assertNotPushed(RenderReportPdfJob::class);
    }

    // ── retry (7.2, `D-132`'s deferred items) ───────────────────────────────

    /**
     * `D-132`, point 7.2: `POST …/versions/{version}/pdf/retry` puts a `failed` version back to `queued`,
     * pushes one `RenderReportPdfJob` on `pdf`, answers `202` with the status read's shape, and audits it.
     */
    public function test_that_a_failed_version_is_requeued_once_on_retry_and_audited(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $id = $this->submitted($author, [$this->user(RoleName::Manager)]);
        $generation = $this->generation($id, 1);
        $this->markFailed($generation);
        Queue::fake();   // forget the job the submit pushed: the retry's is the one counted

        $this->pdfRetry($author, $id, 1)
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.file_id', null)
            ->assertJsonPath('data.failure_reason', null)
            ->assertJsonPath('data.finished_at', null)
            ->assertJsonStructure(['data' => ['status', 'file_id', 'failure_reason', 'finished_at']]);

        $row = DB::table('report_pdf_generations')->where('id', $generation)->first();
        self::assertNotNull($row);
        self::assertSame('queued', $row->status);
        self::assertNull($row->failure_reason);
        self::assertNull($row->finished_at);
        Queue::assertPushed(RenderReportPdfJob::class, 1);
        Queue::assertPushedOn('pdf', RenderReportPdfJob::class, fn (RenderReportPdfJob $job): bool => $job->generationId === $generation);

        $audit = $this->audits('REPORT_PDF_RETRIED', $id);
        self::assertCount(1, $audit);
        self::assertSame($author->id, $audit[0]['user_id']);
        self::assertSame('report', $audit[0]['entity_type']);
        self::assertSame(1, $audit[0]['new']['version'] ?? null);

        // The row is a real queued row again: the worker's next run completes it.
        $this->render($generation);
        self::assertSame('completed', DB::table('report_pdf_generations')->where('id', $generation)->value('status'));
    }

    /** `D-132`: the people who read the PDF may retry it — a recipient too — and a file already attached stays. */
    public function test_that_a_recipient_may_retry_and_an_attached_file_stays(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($author, [$manager]);
        $generation = $this->generation($id, 1);
        $this->render($generation);
        $fileId = DB::table('report_pdf_generations')->where('id', $generation)->value('file_id');
        self::assertIsString($fileId, 'The render did not complete.');
        $this->markFailed($generation);
        Queue::fake();

        $this->pdfRetry($manager, $id, 1)
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.file_id', $fileId);

        self::assertSame($fileId, DB::table('report_pdf_generations')->where('id', $generation)->value('file_id'));
        Queue::assertPushed(RenderReportPdfJob::class, 1);
        $audit = $this->audits('REPORT_PDF_RETRIED', $id);
        self::assertCount(1, $audit);
        self::assertSame($manager->id, $audit[0]['user_id']);
    }

    /** `D-132`: only a `failed` generation retries; a `queued` or `completed` one refuses and leaves everything as it was. */
    public function test_that_a_queued_or_completed_version_refuses_a_retry(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $id = $this->submitted($author, [$this->user(RoleName::Manager)]);
        $generation = $this->generation($id, 1);
        Queue::fake();

        $this->pdfRetry($author, $id, 1)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'business_rule_blocked')
            ->assertJsonPath('error.details.0.code', 'report_pdf_not_retryable');
        self::assertSame('queued', DB::table('report_pdf_generations')->where('id', $generation)->value('status'));

        $this->render($generation);
        $this->pdfRetry($author, $id, 1)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'business_rule_blocked')
            ->assertJsonPath('error.details.0.code', 'report_pdf_not_retryable');
        self::assertSame('completed', DB::table('report_pdf_generations')->where('id', $generation)->value('status'));

        Queue::assertNothingPushed();
        self::assertSame(0, DB::table('audit_log')->where('event', 'REPORT_PDF_RETRIED')->count());
    }

    /** The first retry takes the row out of `failed`, so a second one in a row is refused and pushes nothing. */
    public function test_that_a_second_retry_in_a_row_is_refused_and_pushes_nothing_more(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $id = $this->submitted($author, [$this->user(RoleName::Manager)]);
        $this->markFailed($this->generation($id, 1));
        Queue::fake();

        $this->pdfRetry($author, $id, 1)->assertStatus(202);
        $this->pdfRetry($author, $id, 1)->assertStatus(422)->assertJsonPath('error.details.0.code', 'report_pdf_not_retryable');

        Queue::assertPushed(RenderReportPdfJob::class, 1);
        self::assertCount(1, $this->audits('REPORT_PDF_RETRIED', $id));
    }

    /** `report.export` is the route's (403, ahead of any row rule); no sign-in is `401`. Neither changes the row. */
    public function test_that_a_retry_without_report_export_is_403_and_without_a_sign_in_401(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $id = $this->submitted($author, [$this->user(RoleName::Manager)]);
        $generation = $this->generation($id, 1);
        $this->markFailed($generation);
        Queue::fake();

        $this->pdfRetry($this->userWithoutGrants(), $id, 1)->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->postJson(self::ENDPOINT.'/'.$id.'/versions/1/pdf/retry')->assertStatus(401);

        self::assertSame('failed', DB::table('report_pdf_generations')->where('id', $generation)->value('status'));
        Queue::assertNothingPushed();
        self::assertSame(0, DB::table('audit_log')->where('event', 'REPORT_PDF_RETRIED')->count());
    }

    /** A removed recipient, a draft, a missing version and a version with no generation row: the report's `404`, never a `422`. */
    public function test_that_a_retry_by_a_removed_recipient_or_on_a_draft_a_missing_version_or_no_generation_is_404(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $draft = $this->create($author);
        $id = $this->submitted($author, [$manager]);
        $generation = $this->generation($id, 1);
        $this->markFailed($generation);
        Queue::fake();

        // Control: a live recipient may retry, so the 404s below are the rule's and not an unregistered route's.
        $this->pdfRetry($manager, $id, 1)->assertStatus(202);
        $this->markFailed($generation);
        Queue::fake();

        $this->pdfRetry($author, $draft, 1)->assertStatus(404);
        $this->pdfRetry($author, $id, 9)->assertStatus(404);

        DB::table('report_recipients')->where('report_id', $id)->where('user_id', $manager->id)->update(['deleted_at' => now()]);
        $this->pdfRetry($manager, $id, 1)->assertStatus(404);

        DB::table('report_pdf_generations')->where('report_id', $id)->delete();
        $this->pdfRetry($author, $id, 1)->assertStatus(404);

        Queue::assertNothingPushed();
        self::assertCount(1, $this->audits('REPORT_PDF_RETRIED', $id), 'Only the control retry was audited.');
    }

    // ── the audited download (7.2) ──────────────────────────────────────────

    /**
     * `D-132`: `GET …/versions/{version}/pdf/download` streams the stored PDF to the author or a live recipient
     * and writes one `REPORT_PDF_DOWNLOADED` entry per download — the shared files route stays unaudited.
     */
    public function test_that_a_download_writes_one_audit_entry_and_a_refusal_writes_none(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($author, [$manager]);
        $generation = $this->generation($id, 1);
        $this->render($generation);
        $fileId = DB::table('report_pdf_generations')->where('id', $generation)->value('file_id');
        self::assertIsString($fileId, 'The render did not complete.');

        $response = $this->pdfDownload($author, $id, 1);
        $response->assertOk();
        self::assertStringStartsWith('application/pdf', (string) $response->headers->get('Content-Type'));
        self::assertSame(self::PDF, $response->streamedContent());
        $audit = $this->audits('REPORT_PDF_DOWNLOADED', $id);
        self::assertCount(1, $audit);
        self::assertSame($author->id, $audit[0]['user_id']);
        self::assertSame('report', $audit[0]['entity_type']);
        self::assertSame(1, $audit[0]['new']['version'] ?? null);
        self::assertSame($fileId, $audit[0]['new']['file_id'] ?? null);

        $recipient = $this->pdfDownload($manager, $id, 1);
        $recipient->assertOk();
        self::assertSame(self::PDF, $recipient->streamedContent());
        self::assertSame([$author->id, $manager->id], array_column($this->audits('REPORT_PDF_DOWNLOADED', $id), 'user_id'));

        // A refusal writes nothing: no report.export, no sign-in.
        $this->pdfDownload($this->userWithoutGrants(), $id, 1)->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->getJson(self::ENDPOINT.'/'.$id.'/versions/1/pdf/download')->assertStatus(401);
        self::assertCount(2, $this->audits('REPORT_PDF_DOWNLOADED', $id));
    }

    /** Nothing is stored for a `queued` or `failed` version: a `404`, and no audit entry for a download that did not happen. */
    public function test_that_a_queued_or_failed_version_has_no_download_and_no_audit(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $id = $this->submitted($author, [$this->user(RoleName::Manager)]);

        $this->pdfDownload($author, $id, 1)->assertStatus(404);

        $generation = $this->generation($id, 1);
        $this->markFailed($generation);
        $this->pdfDownload($author, $id, 1)->assertStatus(404);
        self::assertSame(0, DB::table('audit_log')->where('event', 'REPORT_PDF_DOWNLOADED')->count());

        // Control: once retried and rendered, the same route answers the file — so the 404s above were the status's.
        $this->pdfRetry($author, $id, 1)->assertStatus(202);
        $this->render($generation);
        $this->pdfDownload($author, $id, 1)->assertOk();
        self::assertCount(1, $this->audits('REPORT_PDF_DOWNLOADED', $id));
    }

    /** The row rule is the status route's: a draft, a missing version, no generation row and a removed recipient are all `404`. */
    public function test_that_a_download_by_a_removed_recipient_or_on_a_draft_a_missing_version_or_no_generation_is_404(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $draft = $this->create($author);
        $id = $this->submitted($author, [$manager]);
        $this->render($this->generation($id, 1));

        // Control: a live recipient is served, so the 404s below are the rule's and not an unregistered route's.
        $this->pdfDownload($manager, $id, 1)->assertOk();

        $this->pdfDownload($author, $draft, 1)->assertStatus(404);
        $this->pdfDownload($author, $id, 9)->assertStatus(404);

        DB::table('report_recipients')->where('report_id', $id)->where('user_id', $manager->id)->update(['deleted_at' => now()]);
        $this->pdfDownload($manager, $id, 1)->assertStatus(404);

        DB::table('report_pdf_generations')->where('report_id', $id)->delete();
        $this->pdfDownload($author, $id, 1)->assertStatus(404);

        self::assertCount(1, $this->audits('REPORT_PDF_DOWNLOADED', $id), 'Only the control download was audited.');
    }

    #[DataProvider('outsiders')]
    public function test_that_anyone_else_gets_a_404_on_the_retry_and_the_audited_download(RoleName $role): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $recipient = $this->user(RoleName::Manager, 'recipient');
        $id = $this->submitted($author, [$recipient]);
        $generation = $this->generation($id, 1);
        $this->render($generation);
        $outsider = $this->user($role, 'outsider');

        $this->pdfDownload($outsider, $id, 1)->assertStatus(404);

        // Control: the author is served and may retry, so the outsider's 404s are the rule's and not an unregistered route's.
        $this->pdfDownload($author, $id, 1)->assertOk();
        $this->markFailed($generation);
        $this->pdfRetry($author, $id, 1)->assertStatus(202);
        $this->markFailed($generation);
        Queue::fake();

        $this->pdfRetry($outsider, $id, 1)->assertStatus(404);

        self::assertSame('failed', DB::table('report_pdf_generations')->where('id', $generation)->value('status'));
        Queue::assertNothingPushed();
        self::assertSame(0, DB::table('audit_log')->whereIn('event', ['REPORT_PDF_DOWNLOADED', 'REPORT_PDF_RETRIED'])->where('user_id', $outsider->id)->count());
    }

    // ── the version the payload shows ───────────────────────────────────────

    /** The SPA builds `…/versions/{version}/pdf` from `data.version` (spec §4.4: the label names its version). */
    public function test_that_the_detail_reads_carry_the_version_they_show(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->create($author);

        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))->assertOk()->assertJsonPath('data.version', 1);

        $this->patchJson(self::ENDPOINT.'/'.$id.'/submit', ['recipient_ids' => [$manager->id]], [...$this->bearer($author), 'If-Match' => 'report:'.$id.':1'])->assertOk();
        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))->assertOk()->assertJsonPath('data.version', 1);
        $this->getJson(self::ENDPOINT.'/received/'.$id, $this->bearer($manager))->assertOk()->assertJsonPath('data.version', 1);

        // Return → the author's draft is v2 while the recipient still reads v1's frozen copy.
        $this->patchJson(self::ENDPOINT.'/'.$id.'/return', ['reason' => 'Add Delta.'], [...$this->bearer($manager), 'If-Match' => $this->etag($id)])->assertOk();
        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson(self::ENDPOINT.'/received/'.$id, $this->bearer($manager))->assertOk()->assertJsonPath('data.version', 1);
        // The list rows decide "frozen" by another path (`! isEditable()`, `true`); they must show the detail's version.
        $this->getJson(self::ENDPOINT, $this->bearer($author))->assertOk()->assertJsonPath('data.0.version', 2);
        $this->getJson(self::ENDPOINT.'/received', $this->bearer($manager))->assertOk()->assertJsonPath('data.0.version', 1);

        $this->patchJson(self::ENDPOINT.'/'.$id.'/submit', ['recipient_ids' => [$manager->id]], [...$this->bearer($author), 'If-Match' => $this->etag($id)])->assertOk();
        $this->getJson(self::ENDPOINT.'/'.$id, $this->bearer($author))->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson(self::ENDPOINT.'/received/'.$id, $this->bearer($manager))->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson(self::ENDPOINT, $this->bearer($author))->assertOk()->assertJsonPath('data.0.version', 2);
        $this->getJson(self::ENDPOINT.'/received', $this->bearer($manager))->assertOk()->assertJsonPath('data.0.version', 2);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function etag(string $id): string
    {
        $token = DB::table('reports')->where('id', $id)->value('version_token');
        self::assertIsInt($token);

        return 'report:'.$id.':'.$token;
    }

    /** @return TestResponse<\Illuminate\Http\JsonResponse> */
    private function pdfStatus(User $caller, string $reportId, int $version): TestResponse
    {
        return $this->getJson(self::ENDPOINT.'/'.$reportId.'/versions/'.$version.'/pdf', $this->bearer($caller));
    }

    /** @return TestResponse<\Illuminate\Http\JsonResponse> */
    private function pdfRetry(User $caller, string $reportId, int $version): TestResponse
    {
        return $this->postJson(self::ENDPOINT.'/'.$reportId.'/versions/'.$version.'/pdf/retry', [], $this->bearer($caller));
    }

    /** @return TestResponse<\Symfony\Component\HttpFoundation\Response> */
    private function pdfDownload(User $caller, string $reportId, int $version): TestResponse
    {
        return $this->get(self::ENDPOINT.'/'.$reportId.'/versions/'.$version.'/pdf/download', $this->bearer($caller));
    }

    /** What the worker leaves after `--tries=3` is spent: failed, with a reason and a finish time (a file already attached stays). */
    private function markFailed(string $generationId): void
    {
        DB::table('report_pdf_generations')->where('id', $generationId)->update([
            'status' => 'failed', 'failure_reason' => 'The PDF could not be rendered.', 'finished_at' => now(),
        ]);
    }

    /** @return list<array{user_id: string, entity_type: string, new: array<string, mixed>}> */
    private function audits(string $event, string $reportId): array
    {
        $rows = [];
        foreach (DB::table('audit_log')->where('event', $event)->where('entity_id', $reportId)->orderBy('created_at')->orderBy('id')->get() as $row) {
            $new = is_string($row->new_values) ? json_decode($row->new_values, true) : [];
            self::assertIsArray($new);
            /** @var array<string, mixed> $new */
            $rows[] = ['user_id' => is_string($row->user_id) ? $row->user_id : '', 'entity_type' => is_string($row->entity_type) ? $row->entity_type : '', 'new' => $new];
        }

        return $rows;
    }

    /** A role with no grants: it reaches no route that names a permission. */
    private function userWithoutGrants(): User
    {
        $role = new Role;
        $role->fill(['slug' => 'auditor_without_grants', 'name' => 'Auditor', 'is_editable' => true]);
        $role->save();
        $user = new User;
        $user->fill([
            'name' => 'Grantless', 'email' => 'grantless@example.test', 'password' => self::PASSWORD,
            'role_id' => $role->id, 'is_active' => true, 'is_hidden' => false,
        ]);
        $user->save();

        return $user;
    }

    private function render(string $generationId): void
    {
        $this->app->make(RenderReportPdf::class)->handle($generationId, 1);
    }

    private function generation(string $reportId, int $version): string
    {
        $id = DB::table('report_pdf_generations')->where('report_id', $reportId)->where('version', $version)->value('id');
        self::assertIsString($id, "The submit queued no v{$version} generation.");

        return $id;
    }

    /**
     * `$id` submitted by its author — a new daily report unless a draft is given (an RPT-MN from `monthlyRow()`).
     *
     * @param  list<User>  $recipients
     */
    private function submitted(User $author, array $recipients, ?string $id = null): string
    {
        $id ??= $this->create($author);
        $this->patchJson(
            self::ENDPOINT.'/'.$id.'/submit',
            ['recipient_ids' => array_map(static fn (User $u): string => $u->id, $recipients)],
            [...$this->bearer($author), 'If-Match' => $this->etag($id)],
        )->assertStatus(200);

        return $id;
    }

    /** A submitted report sent to `$recipient`: a daily by an Indoor Sales author, or a monthly by a Manager author (`D-140`). */
    private function submittedOfType(string $type, User $recipient): string
    {
        if ($type === 'daily') {
            return $this->submitted($this->user(RoleName::IndoorSales), [$recipient]);
        }
        $author = $this->user(RoleName::Manager, 'author');

        return $this->submitted($author, [$recipient], $this->monthlyRow($author));
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
