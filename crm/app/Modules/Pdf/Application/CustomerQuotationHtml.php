<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application;

use App\Modules\Pdf\Domain\Contracts\PdfAssetsInterface;
use App\Modules\Pdf\Domain\View\CustomerQuotationView;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use InvalidArgumentException;

/**
 * `CustomerQuotationView` → the HTML `PdfRendererInterface` turns into a PDF —
 * Module 9, Point 2.4, and `D-89`'s layout.
 *
 * ── The locale is passed, never taken from the request ────────────────────
 *
 * The document's language belongs to the customer who receives it, not to the
 * employee who pressed the button, and Step 3 renders on a queue where there is
 * no request at all. So every label is resolved through `Translator::get()`
 * with an explicit locale rather than `__()`, which would read whatever
 * `app()->setLocale()` last happened to be — the failure mode
 * `SetLocaleFromRequest`'s own docblock warns about.
 *
 * ── The template receives nothing it could misuse ─────────────────────────
 *
 * It gets the view, the two `data:` URIs, the font CSS and `$t`. It is handed
 * no repository, no request and no quotation of Module 7's, so a template
 * cannot reach past the model 1.1 built to be safe.
 */
final readonly class CustomerQuotationHtml
{
    private const LOCALES = ['ar' => 'rtl', 'en' => 'ltr'];

    public function __construct(
        private ViewFactory $views,
        private Translator $translator,
        private PdfAssetsInterface $assets,
    ) {}

    /**
     * @param  string  $locale  `ar` or `en` — §1's two languages, and no third
     *
     * @throws InvalidArgumentException when the locale is not one of them
     */
    public function render(CustomerQuotationView $view, string $locale): string
    {
        $direction = self::LOCALES[$locale] ?? throw new InvalidArgumentException(
            "The customer PDF renders in ar or en, not {$locale}."
        );

        return $this->views->make('pdf.customer-quotation', [
            'view' => $view,
            'locale' => $locale,
            'direction' => $direction,
            'fontFaceCss' => $this->assets->fontFaceCss(),
            'logo' => $this->assets->logo(),
            'footerBand' => $this->assets->footerBand(),
            'watermark' => $this->assets->watermark(),
            't' => fn (string $key, array $replace = []): string => (string) $this->translator->get(
                'pdf.'.$key,
                $replace,
                $locale,
            ),
        ])->render();
    }
}
