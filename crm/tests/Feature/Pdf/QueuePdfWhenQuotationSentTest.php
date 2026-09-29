<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Modules\Admin\Domain\Contracts\SettingsRepositoryInterface;
use App\Modules\Admin\Domain\Settings\SystemSetting;
use App\Modules\Audit\Infrastructure\RequestAuditContext;
use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Pdf\Application\Generation\RenderQuotationPdf;
use App\Modules\Pdf\Domain\Contracts\PdfRendererInterface;
use App\Modules\Pdf\Domain\Rendering\PdfRenderingFailed;
use App\Modules\Pdf\Presentation\RenderQuotationPdfJob;
use App\Modules\Quotations\Domain\Writing\QuotationSent;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Fixtures\CustomerQuotationViewFixture;
use Tests\TestCase;

/**
 * Module 9, Point 3.6 — Q14: a successful send queues one generation in the
 * sender's name, and the send does not wait (`D-90`). Module 7 dispatches
 * `QuotationSent` once the send commits (F-31 · 1.3); this is `Pdf`'s side of
 * that contract, driven the way Module 7 drives it.
 */
final class QueuePdfWhenQuotationSentTest extends TestCase
{
    use InsertsQuotationRows;
    use RefreshDatabase;
    use SignsInByRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingsSeeder::class);
        $this->app->make(SettingsRepositoryInterface::class)->put(SystemSetting::CompanyName, 'Smart Technology for Integrated Systems');

        Queue::fake();
    }

    public function test_that_a_send_queues_one_generation_in_the_senders_name(): void
    {
        $sender = $this->userWith(RoleName::IndoorSales);
        $quotationId = $this->insertQuotation($sender->id);
        $this->inTheSendersRequest($sender);
        $this->app->setLocale('ar');

        Event::dispatch(new QuotationSent($quotationId, $sender->id));

        $generation = DB::table('pdf_generations')->where('quotation_id', $quotationId)->sole();
        self::assertSame('queued', $generation->status);
        self::assertSame($sender->id, $generation->created_by);
        self::assertSame('ar', $generation->locale, 'Q15: no language was named, so the sender’s own.');
        Queue::assertPushedOn('pdf', RenderQuotationPdfJob::class, fn (RenderQuotationPdfJob $job): bool => $job->generationId === $generation->id);
        self::assertSame(1, DB::table('audit_log')->where('event', 'QUOTATION_PDF_REQUESTED')->where('entity_id', $quotationId)->where('user_id', $sender->id)->count());
    }

    public function test_that_a_quotation_that_cannot_be_printed_leaves_the_send_alone(): void
    {
        $this->app->make(SettingsRepositoryInterface::class)->put(SystemSetting::CompanyName, '');
        $sender = $this->userWith(RoleName::Manager);
        $quotationId = $this->insertQuotation();
        $this->inTheSendersRequest($sender);

        // The send has committed; a PDF that cannot be printed yet must not turn it into an error.
        Event::dispatch(new QuotationSent($quotationId, $sender->id));

        self::assertSame(0, DB::table('pdf_generations')->count());
        Queue::assertNothingPushed();
    }

    public function test_that_a_sender_whose_grant_cannot_print_it_leaves_the_send_alone(): void
    {
        // The Team Leader's `generate_pdf` is `Team`, refused by name under `D-a`.
        $sender = $this->userWith(RoleName::TeamLeader);
        $quotationId = $this->insertQuotation($this->userWith(RoleName::IndoorSales)->id);
        $this->inTheSendersRequest($sender);

        Event::dispatch(new QuotationSent($quotationId, $sender->id));

        self::assertSame(0, DB::table('pdf_generations')->count());
        Queue::assertNothingPushed();
    }

    public function test_that_a_failed_render_leaves_the_quotation_sent(): void
    {
        $sender = $this->userWith(RoleName::Manager);
        $quotationId = $this->insertQuotation();
        DB::table('quotations')->where('id', $quotationId)->update(['status' => 'sent']);
        $this->inTheSendersRequest($sender);

        Event::dispatch(new QuotationSent($quotationId, $sender->id));
        $generationId = DB::table('pdf_generations')->where('quotation_id', $quotationId)->value('id');
        self::assertIsString($generationId);

        $this->app->instance(PdfRendererInterface::class, new class implements PdfRendererInterface
        {
            public function render(string $html, ?string $footerHtml = null): string
            {
                throw PdfRenderingFailed::because(new RuntimeException('Chromium did not answer.'));
            }
        });

        foreach ([1, 2, 3] as $attempt) {
            try {
                $this->app->make(RenderQuotationPdf::class)->handle($generationId, CustomerQuotationViewFixture::make(), $attempt);
            } catch (PdfRenderingFailed $failure) {
                $last = $failure;
            }
        }
        (new RenderQuotationPdfJob($generationId, CustomerQuotationViewFixture::make()))->failed($last ?? new RuntimeException('unreachable'));

        self::assertSame('failed', DB::table('pdf_generations')->where('id', $generationId)->value('status'));
        self::assertSame('sent', DB::table('quotations')->where('id', $quotationId)->value('status'), 'D-90: the send never waits on, or unwinds for, the PDF.');
    }

    /**
     * Module 7 dispatches `QuotationSent` inside the sender's own HTTP request,
     * where the audit recorder finds its actor (`AUD-02`) — on a request the
     * request-id middleware has stamped, as every API request is.
     */
    private function inTheSendersRequest(User $sender): void
    {
        $this->actingAs($sender, 'api');

        $request = $this->app->make('request');
        $request->setUserResolver(fn (): User => $sender);
        $request->attributes->set(RequestAuditContext::REQUEST_ATTRIBUTE, 'send-request');
    }
}
