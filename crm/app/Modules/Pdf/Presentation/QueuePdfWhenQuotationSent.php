<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Presentation;

use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Pdf\Application\Generation\RequestQuotationPdf;
use App\Modules\Pdf\Domain\View\CustomerViewIncomplete;
use App\Modules\Quotations\Domain\Listing\QuotationNotFound;
use App\Modules\Quotations\Domain\Writing\QuotationSent;
use Illuminate\Support\Facades\Log;

/**
 * Module 9, Point 3.6 — Q14: a successful send queues one generation in the
 * sender's name, and the send does not wait (`D-90`). Module 7 dispatches
 * `QuotationSent` after its commit (F-31 · 1.3), so this runs inside the
 * sender's request, in their language (Q15), through the same
 * `RequestQuotationPdf` the button uses — the same reach, snapshot and audit.
 *
 * A sender whose grant cannot print this quotation, or a quotation that
 * cannot be printed yet, leaves the send as it is: it has committed, and the
 * panel's *Generate* is where the reason is shown.
 */
final readonly class QueuePdfWhenQuotationSent
{
    public function __construct(private RequestQuotationPdf $request) {}

    public function handle(QuotationSent $event): void
    {
        try {
            $requested = $this->request->request($event->quotationId, self::locale(), $event->senderId);
        } catch (AuthorizationRefused|QuotationNotFound|CustomerViewIncomplete $refused) {
            Log::info("Q14: quotation {$event->quotationId} was sent without a PDF: {$refused->getMessage()}");

            return;
        }

        RenderQuotationPdfJob::dispatch($requested['generationId'], $requested['view']);
    }

    /**
     * Q15's fallback — the sender's own language, which `SetLocaleFromRequest`
     * settled; Arabic, the default locale, if it is ever anything else.
     *
     * @return 'ar'|'en'
     */
    private static function locale(): string
    {
        return app()->getLocale() === 'en' ? 'en' : 'ar';
    }
}
