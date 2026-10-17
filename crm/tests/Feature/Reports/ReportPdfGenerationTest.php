<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Modules\Admin\Domain\Contracts\SettingsCacheInterface;
use App\Modules\Admin\Domain\Contracts\SettingsRepositoryInterface;
use App\Modules\Admin\Domain\Settings\SystemSetting;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Pdf\Application\Generation\RenderReportPdf;
use App\Modules\Pdf\Domain\Contracts\PdfRendererInterface;
use App\Modules\Pdf\Domain\Rendering\PdfRenderingFailed;
use App\Modules\Pdf\Presentation\RenderReportPdfJob;
use App\Modules\Reports\Presentation\GenerateWeeklyReportsJob;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\StorageServiceInterface;
use App\Modules\Storage\Domain\ValueObjects\StoragePath;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Feature\Pdf\InsertsQuotationRows;
use Tests\Feature\Storage\RemovesOnlyFilesItStored;
use Tests\TestCase;

/**
 * Module 13, slice 4 · 4.1 red — the stored PDF of a submitted report
 * (`D-132`, spec `2026-10-16-report-pdf-design.md` §4.1, §4.2, §6).
 *
 * - Queueing: a submit commits, then queues one `report_pdf_generations` row and a
 *   `RenderReportPdfJob` on the `pdf` queue; a refused submit queues nothing; v2 never touches v1's.
 * - Rendering: the fake renderer stands for Chromium (`P-01`'s real render is point 4.2's).
 *   Every attempt below is one `RenderReportPdf::handle()` — what the worker does between retries.
 * - Content: the frozen copy's (`D-126`, `D-130`, `D-131`, `D-133`), never cost, margin or profit.
 */
final class ReportPdfGenerationTest extends TestCase
{
    use InsertsQuotationRows;
    use RefreshDatabase;
    use RemovesOnlyFilesItStored;

    private const PASSWORD = 'Passw0rd123';

    private const ENDPOINT = '/api/v1/reports';

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n";

    private ReportPdfFakeRenderer $renderer;

    /** @var array<string, string> one login per user per test — the login limit counts every call */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(9, 0));
        $this->rememberStoredFiles(AttachmentParent::Report);

        Queue::fake();
        $this->renderer = new ReportPdfFakeRenderer(self::PDF);
        $this->app->instance(PdfRendererInterface::class, $this->renderer);
    }

    protected function tearDown(): void
    {
        $this->removeFilesStoredSince(AttachmentParent::Report);

        parent::tearDown();
    }

    // ── queueing ────────────────────────────────────────────────────────────

    public function test_that_a_submit_queues_exactly_one_generation_on_the_pdf_queue(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $id = $this->create($author);

        $this->submit($author, $id, [$this->user(RoleName::Manager)])->assertStatus(200);

        $row = DB::table('report_pdf_generations')->where('report_id', $id)->sole();
        self::assertSame(1, $row->version);
        self::assertSame('queued', $row->status, 'The row exists as soon as the submit returns (spec §4.2).');
        self::assertNull($row->file_id);
        Queue::assertPushed(RenderReportPdfJob::class, 1);
        Queue::assertPushedOn('pdf', RenderReportPdfJob::class, fn (RenderReportPdfJob $job): bool => $job->generationId === $row->id);
    }

    public function test_that_a_refused_submit_queues_nothing(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $id = $this->create($author);

        // No recipients: the submit rolls back (D-127), and so does anything it would have queued.
        $this->submit($author, $id, [])->assertStatus(422);

        self::assertSame(0, DB::table('report_pdf_generations')->count());
        Queue::assertNothingPushed();
    }

    public function test_that_return_and_resubmit_queues_v2_and_leaves_v1_as_it_was(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $id = $this->submitted($author, [$manager]);
        $v1 = DB::table('report_pdf_generations')->where('report_id', $id)->where('version', 1)->sole();
        self::assertIsString($v1->id);
        $this->render($v1->id);
        $v1Done = DB::table('report_pdf_generations')->where('id', $v1->id)->first();
        self::assertNotNull($v1Done);
        self::assertSame('completed', $v1Done->status);

        $this->patchJson(self::ENDPOINT.'/'.$id.'/return', ['reason' => 'Add Delta.'], [...$this->bearer($manager), 'If-Match' => $this->etag($id)])->assertStatus(200);
        $this->submit($author, $id, [$manager])->assertStatus(200);

        self::assertSame(2, DB::table('report_pdf_generations')->where('report_id', $id)->count());
        $v2 = DB::table('report_pdf_generations')->where('report_id', $id)->where('version', 2)->sole();
        self::assertSame('queued', $v2->status);
        self::assertNull($v2->file_id);
        self::assertNotSame($v1->id, $v2->id);
        Queue::assertPushed(RenderReportPdfJob::class, 2);

        // D-132: v2's PDF never replaces v1's.
        $v1After = DB::table('report_pdf_generations')->where('id', $v1->id)->first();
        self::assertEquals($v1Done, $v1After, 'v1 row changed when v2 was queued.');
        self::assertSame(1, DB::table('files')->where('id', $v1Done->file_id)->whereNull('deleted_at')->count());
        self::assertSame(1, DB::table('report_files')->where('report_id', $id)->where('file_id', $v1Done->file_id)->count());
    }

    // ── rendering ───────────────────────────────────────────────────────────

    public function test_that_a_render_is_stored_under_the_report_attached_scanned_and_completed(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $id = $this->submitted($author, [$this->user(RoleName::Manager)]);
        $generation = $this->generation($id, 1);

        $this->render($generation);

        $row = DB::table('report_pdf_generations')->where('id', $generation)->first();
        self::assertNotNull($row);
        self::assertSame('completed', $row->status);
        self::assertSame(1, $row->attempts);
        self::assertNotNull($row->finished_at);
        self::assertNull($row->failure_reason);

        $file = DB::table('files')->where('id', $row->file_id)->first();
        self::assertNotNull($file);
        self::assertSame($this->code('reports', $id).'.pdf', $file->original_name);
        self::assertSame('application/pdf', $file->mime_type);
        self::assertSame(strlen(self::PDF), $file->size_bytes);
        self::assertSame('clean', $file->scan_status, 'Scanned like any other file (ScanStoredFile), not inserted as clean.');
        self::assertIsString($file->storage_path);
        self::assertStringContainsString('/'.AttachmentParent::Report->value.'/', $file->storage_path, 'Stored under the report as parent.');
        self::assertSame(self::PDF, $this->storedBytes($file->storage_path));
        self::assertSame(1, DB::table('report_files')->where('report_id', $id)->where('file_id', $file->id)->count());
        self::assertSame(['ar'], $this->renderer->locales, 'D-132: Arabic only.');
        self::assertSame(1, $this->renderer->calls);
    }

    public function test_that_a_second_run_stores_no_second_file(): void
    {
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$this->user(RoleName::Manager)]);
        $generation = $this->generation($id, 1);

        $this->render($generation);
        $this->render($generation, 2);

        self::assertSame(1, $this->renderer->calls, 'A redelivered job for a completed row renders nothing (spec §4.2 step 1).');
        self::assertSame(1, DB::table('files')->count());
        self::assertSame(1, DB::table('report_files')->where('report_id', $id)->count());
        self::assertSame(1, DB::table('report_pdf_generations')->where('id', $generation)->value('attempts'));
    }

    public function test_that_a_render_failure_ends_failed_with_a_reason_and_no_file(): void
    {
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$this->user(RoleName::Manager)]);
        $generation = $this->generation($id, 1);
        $this->renderer->failTimes(3);

        $last = new RuntimeException('unreachable');
        foreach ([1, 2, 3] as $attempt) {
            try {
                $this->render($generation, $attempt);
                self::fail("Attempt {$attempt} was expected to throw, so the worker would retry it.");
            } catch (PdfRenderingFailed $failure) {
                $last = $failure;
            }
        }
        // What the worker does once `--tries=3` is spent.
        (new RenderReportPdfJob($generation))->failed($last);

        $row = DB::table('report_pdf_generations')->where('id', $generation)->first();
        self::assertNotNull($row);
        self::assertSame('failed', $row->status);
        self::assertNotNull($row->failure_reason);
        self::assertNotSame('', $row->failure_reason);
        self::assertNotNull($row->finished_at);
        self::assertNull($row->file_id);
        self::assertSame(0, DB::table('files')->count());
        self::assertSame(0, DB::table('report_files')->where('report_id', $id)->count());
        self::assertSame('submitted', DB::table('reports')->where('id', $id)->value('status'), 'A failed PDF never unwinds the submit.');
    }

    // ── content ─────────────────────────────────────────────────────────────

    /** `D-126`, `D-130`, `D-131`: the frozen copy's codes, counts, notes and selling totals, in Arabic RTL. */
    public function test_that_the_html_holds_the_frozen_codes_counts_notes_and_selling_totals(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $dealCodes = [];
        for ($i = 0; $i < 7; $i++) {
            $deal = $this->dealOf($this->insertQuotation());
            $dealCodes[] = $this->code('deals', $deal);
            $this->activity($author->id, 'DEAL_CREATED', 'deal', '2026-10-15 07:'.sprintf('%02d', $i).':00', $deal);
        }
        $sent = $this->quotationWith('sent', '1234');
        $this->activity($author->id, 'QUOTATION_SENT', 'quotation', '2026-10-15 08:00:00', $sent);
        $id = $this->create($author);
        $this->postJson(self::ENDPOINT.'/'.$id.'/notes', ['body' => 'ملاحظة اليوم الأولى'], $this->bearer($author))->assertStatus(201);
        $this->submit($author, $id, [$this->user(RoleName::Manager)])->assertStatus(200);

        $html = $this->renderedHtml($id);

        self::assertStringContainsString('dir="rtl"', $html, 'P-01: an Arabic, right-to-left document.');
        self::assertStringContainsString($this->code('reports', $id), $html);
        foreach ($dealCodes as $code) {
            self::assertStringContainsString($code, $html);
        }
        self::assertStringContainsString($this->code('quotations', $sent), $html);
        self::assertStringContainsString('ملاحظة اليوم الأولى', $html);
        self::assertMatchesRegularExpression('/1[,٬ ]?234/u', $html, 'The sent quotation’s selling total.');
        self::assertMatchesRegularExpression('/EGP|ج\.م|جنيه/u', $html, 'The total carries its currency.');

        // The deals-created count is a lone 7 once every code is taken out of the text.
        $text = str_replace([...$dealCodes, $this->code('quotations', $sent), $this->code('reports', $id)], '', $this->textOf($html));
        self::assertMatchesRegularExpression('/(?<![\d.,٫٬])[7٧](?![\d.,٫٬])/u', $text, 'The count of 7 deals created is not in the PDF.');
    }

    /** `D-133`, `D-132`: customer, note and the first-save time, in the company's zone, never the submit time. */
    public function test_that_a_follow_ups_customer_note_and_first_save_time_are_in_the_html(): void
    {
        $this->app->make(SettingsRepositoryInterface::class)->put(SystemSetting::Timezone, 'Asia/Riyadh');   // UTC+3, no DST
        $author = $this->user(RoleName::IndoorSales);
        $nile = $this->customer('Nile Trading', $author);
        $id = $this->create($author);

        // First saved at 09:00 UTC (12:00 in Riyadh); submitted an hour and a half later.
        $this->postJson(self::ENDPOINT.'/'.$id.'/follow-ups', ['customer_id' => $nile, 'note' => 'Wants a visit on Sunday.'], $this->bearer($author))->assertStatus(201);
        $this->travelTo(now()->setTime(10, 30));
        $this->submit($author, $id, [$this->user(RoleName::Manager)])->assertStatus(200);

        $text = $this->textOf($this->renderedHtml($id));

        self::assertStringContainsString('Nile Trading', $text);
        self::assertStringContainsString('Wants a visit on Sunday.', $text);
        self::assertMatchesRegularExpression('/1[2٢]:[0٠][0٠]/u', $text, 'The first-save time, 09:00 UTC, shown as 12:00 in the company zone.');
        self::assertDoesNotMatchRegularExpression('/[0٠][9٩]:[0٠][0٠]/u', $text, 'The UTC time leaked into the PDF unconverted.');
    }

    /** `D-132`: the PDF never shows cost, margin or profit. */
    public function test_that_the_html_shows_no_cost_margin_or_profit(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $quotation = $this->quotationWith('sent', '1234');
        DB::table('quotations')->where('id', $quotation)->update(['default_margin' => '37.5']);
        $this->activity($author->id, 'QUOTATION_SENT', 'quotation', '2026-10-15 08:00:00', $quotation);
        $id = $this->submitted($author, [$this->user(RoleName::Manager)]);

        $html = $this->renderedHtml($id);
        self::assertStringContainsString($this->code('quotations', $quotation), $html, 'The fixture must reach the PDF, or this test proves nothing.');

        $text = $this->textOf($html);
        self::assertDoesNotMatchRegularExpression('/cost|margin|profit|تكلفة|هامش|ربح/iu', $text);
        self::assertStringNotContainsString('37.5', $html, 'The quotation’s margin leaked into the PDF.');
    }

    /** Acceptance criterion 1: "unchanged even if the data changes" — the PDF reads the frozen copy, not the live rows. */
    public function test_that_a_deal_changed_after_submit_does_not_change_the_pdf(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $dealId = $this->dealOf($this->insertQuotation());
        $this->activity($author->id, 'DEAL_CREATED', 'deal', '2026-10-15 07:00:00', $dealId);
        $sent = $this->quotationWith('sent', '1234');
        $this->activity($author->id, 'QUOTATION_SENT', 'quotation', '2026-10-15 08:00:00', $sent);
        $nile = $this->customer('Nile Trading', $author);
        $id = $this->create($author);
        $this->postJson(self::ENDPOINT.'/'.$id.'/follow-ups', ['customer_id' => $nile, 'note' => 'Wants a visit.'], $this->bearer($author))->assertStatus(201);
        $this->submit($author, $id, [$this->user(RoleName::Manager)])->assertStatus(200);

        DB::table('customers')->where('id', DB::table('deals')->where('id', $dealId)->value('customer_id'))->update(['name' => 'Renamed Later']);
        DB::table('customers')->where('id', $nile)->update(['name' => 'Also Renamed']);
        DB::table('quotations')->where('id', $sent)->update(['subtotal' => '9999', 'tax_base' => '9999', 'net_amount' => '9999', 'total_before_round' => '9999', 'final_total' => '9999', 'status' => 'accepted']);
        DB::table('deals')->where('id', $dealId)->update(['status' => 'contacted']);

        $text = $this->textOf($this->renderedHtml($id));

        self::assertStringContainsString('Nile Trading', $text);
        self::assertStringContainsString($this->code('deals', $dealId), $text);
        self::assertMatchesRegularExpression('/1[,٬ ]?234/u', $text);
        self::assertStringNotContainsString('Renamed Later', $text);
        self::assertStringNotContainsString('Also Renamed', $text);
        self::assertDoesNotMatchRegularExpression('/9[,٬ ]?999/u', $text);
    }

    /** The owner's defect: a status move names its from and to status, in Arabic, so two moves of one deal differ. */
    public function test_that_a_status_move_shows_its_from_and_to_status_in_arabic(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $dealId = $this->dealOf($this->insertQuotation());
        $this->activity($author->id, 'DEAL_STATUS_CHANGED', 'deal', '2026-10-15 07:00:00', $dealId, ['status' => 'negotiations'], ['status' => 'won']);
        $this->activity($author->id, 'DEAL_STATUS_CHANGED', 'deal', '2026-10-15 07:30:00', $dealId, null, ['status' => 'lead']);
        $id = $this->submitted($author, [$this->user(RoleName::Manager)]);

        $text = (string) preg_replace('/\\s+/u', ' ', $this->textOf($this->renderedHtml($id)));

        self::assertStringContainsString('تفاوض', $text, 'The from-status label.');
        self::assertStringContainsString('ناجحة', $text, 'The to-status label.');
        self::assertStringContainsString('مبدئي', $text, 'A move with no from-status still shows its to-status.');
        self::assertStringContainsString('من', $text);
        self::assertStringContainsString('إلى', $text);
    }

    public function test_that_a_response_row_shows_its_response_in_arabic_and_an_unknown_status_prints_raw(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $quotation = $this->quotationWith('sent', '500');
        $this->activity($author->id, 'QUOTATION_REJECTED', 'quotation', '2026-10-15 08:00:00', $quotation);
        $dealId = $this->dealOf($this->insertQuotation());
        $this->activity($author->id, 'DEAL_STATUS_CHANGED', 'deal', '2026-10-15 07:00:00', $dealId, ['status' => 'legacy_x'], ['status' => 'won']);
        $id = $this->submitted($author, [$this->user(RoleName::Manager)]);

        $text = $this->textOf($this->renderedHtml($id));

        self::assertStringContainsString('رفض', $text, 'The response label.');
        self::assertStringContainsString('legacy_x', $text, 'An unknown status prints its raw code and does not crash.');
    }

    /** The owner's defect on RPT-DY-2026-0014: a hidden author (§3.1) has no name in the directory, and the label printed beside nothing. */
    public function test_that_a_hidden_author_prints_a_dash_and_not_an_empty_value(): void
    {
        $author = $this->user(RoleName::SuperAdmin);
        $id = $this->submitted($author, [$this->user(RoleName::Manager)]);

        $text = (string) preg_replace('/\\s+/u', ' ', $this->textOf($this->renderedHtml($id)));

        self::assertMatchesRegularExpression('/مقدَّم من: —/u', $text);
    }

    // ── the weekly PDF (6.5, `D-126`, `D-134`, `D-137`) ─────────────────────

    /** Week Sunday 2026-10-11 → Thursday 2026-10-15; the fixtures sit on Tuesday and Thursday. */
    public function test_that_a_weekly_pdf_holds_the_week_labels_its_daily_reports_the_po_count_and_the_new_customers(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $manager = $this->user(RoleName::Manager);
        $daily = $this->submitted($author, [$manager]);   // Thursday 2026-10-15, submitted
        DB::table('purchase_orders')->insert([
            'id' => (string) Str::uuid7(), 'quotation_id' => $this->insertQuotation($author->id),
            'po_number' => 'PO-2026-0001', 'customer_po_reference' => 'REF-PO-2026-0001', 'po_date' => '2026-10-13', 'created_by' => $author->id,
            'created_at' => '2026-10-13 09:00:00', 'updated_at' => '2026-10-13 09:00:00',
        ]);
        $this->customer('Nile New', $author);

        $id = $this->weekly($author);
        $this->submit($author, $id, [$manager])->assertStatus(200);
        $raw = DB::table('report_versions')->where('report_id', $id)->value('snapshot');
        self::assertIsString($raw);
        /** @var array{daily_reports?: list<array{code: string}>, purchase_orders_received?: int, new_customers?: array{items: list<array{name: string}>}}|null $snapshot */
        $snapshot = json_decode($raw, true);
        self::assertIsArray($snapshot);
        self::assertSame(
            [[$this->code('reports', $daily)], 1, ['Nile New']],
            [array_column($snapshot['daily_reports'] ?? [], 'code'), $snapshot['purchase_orders_received'] ?? null, array_column($snapshot['new_customers']['items'] ?? [], 'name')],
            'The fixtures must reach the frozen copy, or this test proves nothing.',
        );

        $text = (string) preg_replace('/\\s+/u', ' ', $this->textOf($this->renderedHtml($id)));

        foreach (['تقرير أسبوعي', 'الأسبوع:', 'ملخص الأسبوع', 'التقارير اليومية لهذا الأسبوع', $this->code('reports', $daily), 'مُرسَل', 'أوامر شراء وصلت على صفقاته', 'العملاء الجدد هذا الأسبوع', 'Nile New'] as $expected) {
            self::assertStringContainsString($expected, $text);
        }
        foreach (['اليوم:', 'ملخص اليوم', 'التواصل والمتابعة'] as $daySide) {
            self::assertStringNotContainsString($daySide, $text, 'A weekly PDF printed a daily label or the follow-ups section.');
        }
    }

    public function test_that_a_daily_pdf_holds_no_weekly_label_or_section(): void
    {
        $id = $this->submitted($this->user(RoleName::IndoorSales), [$this->user(RoleName::Manager)]);

        $text = (string) preg_replace('/\\s+/u', ' ', $this->textOf($this->renderedHtml($id)));

        foreach (['اليوم:', 'ملخص اليوم', 'التواصل والمتابعة'] as $expected) {
            self::assertStringContainsString($expected, $text);
        }
        foreach (['الأسبوع:', 'ملخص الأسبوع', 'التقارير اليومية لهذا الأسبوع', 'أوامر شراء وصلت على صفقاته', 'العملاء الجدد هذا الأسبوع'] as $weekSide) {
            self::assertStringNotContainsString($weekSide, $text);
        }
    }

    public function test_that_an_empty_weekly_pdf_prints_its_three_empty_lines(): void
    {
        $author = $this->user(RoleName::IndoorSales);
        $id = $this->weekly($author);
        $this->submit($author, $id, [$this->user(RoleName::Manager)])->assertStatus(200);

        $text = (string) preg_replace('/\\s+/u', ' ', $this->textOf($this->renderedHtml($id)));

        foreach (['لا تقارير يومية في هذا الأسبوع.', 'لا أوامر شراء هذا الأسبوع.', 'لم يُضف أي عميل هذا الأسبوع.'] as $empty) {
            self::assertStringContainsString($empty, $text);
        }
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /**
     * J-05 (`D-136`) drafts the author's weekly report for 2026-10-11 → 2026-10-15,
     * run as the worker runs it, early on Sunday 2026-10-18. Three days on, every
     * bearer token is past `D-29`'s eight idle hours, so each user logs in again.
     */
    private function weekly(User $author): string
    {
        // As `UpdateSettings` does: the earlier requests cached the settings map without the go-live date.
        $this->app->make(SettingsRepositoryInterface::class)->put(SystemSetting::ReportsGoLiveDate, '2026-10-11');
        $this->app->make(SettingsCacheInterface::class)->forgetSettings();
        $this->travelTo(now()->setDate(2026, 10, 18)->setTime(3, 0));
        $this->tokens = [];
        $this->app->instance('request', Request::create('http://localhost'));
        $this->app->call([new GenerateWeeklyReportsJob, 'handle']);

        $id = DB::table('reports')->where('type', 'weekly')->where('author_id', $author->id)->value('id');
        self::assertIsString($id, 'J-05 drafted no weekly report for the author.');
        self::assertSame(['2026-10-11', '2026-10-15'], [DB::table('reports')->where('id', $id)->value('period_start'), DB::table('reports')->where('id', $id)->value('period_end')]);

        return $id;
    }

    /** Renders the report's v1 and answers the HTML the renderer was handed. */
    private function renderedHtml(string $reportId, int $version = 1): string
    {
        $this->render($this->generation($reportId, $version));
        self::assertCount(1, $this->renderer->htmls, 'One render, one document.');

        return $this->renderer->htmls[0];
    }

    /** Visible text only: no `<style>` (it says `margin`), no tags, entities decoded. */
    private function textOf(string $html): string
    {
        $withoutStyle = preg_replace('#<(style|script)\b.*?</\1>#s', '', $html);

        return html_entity_decode(strip_tags((string) $withoutStyle), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function render(string $generationId, int $attempt = 1): void
    {
        $this->app->make(RenderReportPdf::class)->handle($generationId, $attempt);
    }

    /** The generation the submit queued, read back from the table. */
    private function generation(string $reportId, int $version): string
    {
        $id = DB::table('report_pdf_generations')->where('report_id', $reportId)->where('version', $version)->value('id');
        self::assertIsString($id, "The submit queued no v{$version} generation.");

        return $id;
    }

    private function storedBytes(string $storagePath): string
    {
        $stream = $this->app->make(StorageServiceInterface::class)->readStream(StoragePath::fromStored($storagePath));

        try {
            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    /** @param list<User> $recipients */
    private function submitted(User $author, array $recipients): string
    {
        $id = $this->create($author);
        $this->submit($author, $id, $recipients)->assertStatus(200);

        return $id;
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

    /**
     * One index entry for a record the author touched that day (`D-130`).
     *
     * @param  array<string, string>|null  $old
     * @param  array<string, string>|null  $new
     */
    private function activity(string $userId, string $event, string $entityType, string $at, string $entityId, ?array $old = null, ?array $new = null): void
    {
        DB::table('audit_log')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $userId, 'event' => $event,
            'entity_type' => $entityType, 'entity_id' => $entityId, 'created_at' => $at,
            'old_values' => $old === null ? null : json_encode($old), 'new_values' => $new === null ? null : json_encode($new),
        ]);
    }

    private function dealOf(string $quotationId): string
    {
        $dealId = DB::table('quotations')->where('id', $quotationId)->value('deal_id');
        self::assertIsString($dealId);

        return $dealId;
    }

    /** A quotation (Module 9's fixture) at a status and a selling total. */
    private function quotationWith(string $status, string $total): string
    {
        $id = $this->insertQuotation();
        DB::table('quotations')->where('id', $id)->update([
            'status' => $status, 'quotation_date' => '2026-10-15',
            'subtotal' => $total, 'tax_base' => $total, 'net_amount' => $total,
            'total_before_round' => $total, 'final_total' => $total,
        ]);

        return $id;
    }

    private function code(string $table, string $id): string
    {
        $code = DB::table($table)->where('id', $id)->value('code');
        self::assertIsString($code);

        return $code;
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
