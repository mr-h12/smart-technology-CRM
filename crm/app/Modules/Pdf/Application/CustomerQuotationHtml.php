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
     * The page number Chrome draws in every page's bottom margin — Point 2.5.
     *
     * A separate document from the page above it: Chrome renders
     * `footerTemplate` on its own, so it inherits none of the template's CSS.
     *
     * ⚠️ **This is the one place the document does not embed its faces**, and
     * not by choice: Browsershot passes the template to Chrome as a command
     * argument, so a base64 face in it makes the command exceed the OS limit —
     * `proc_open(): posix_spawn() failed: Argument list too long`, measured.
     * The names below are the faces `docker/php/Dockerfile` installs in the
     * image that renders (`fonts-inter`, `fonts-noto-core`), which under `D-66`
     * is the production environment too, with `fonts.conf` pinning how a family
     * name resolves. The page above it still embeds everything, so what depends
     * on the image is the page number's shape, not the document's.
     *
     * The two spans are Chrome's own: it replaces their contents per page,
     * which is why the label's words arrive through `:current` and `:total`
     * rather than being concatenated — Arabic puts them in the other order.
     *
     * @throws InvalidArgumentException when the locale is not `ar` or `en`
     */
    public function footer(string $locale): string
    {
        $direction = self::LOCALES[$locale] ?? throw new InvalidArgumentException(
            "The customer PDF renders in ar or en, not {$locale}."
        );

        $label = (string) $this->translator->get('pdf.footer.page', [
            'current' => '<span class="pageNumber"></span>',
            'total' => '<span class="totalPages"></span>',
        ], $locale);

        $family = $direction === 'rtl' ? "'Noto Sans Arabic', 'Inter'" : "'Inter', 'Noto Sans Arabic'";

        return "<div dir=\"{$direction}\" style=\"width:100%;margin:0 12mm;font-size:8pt;text-align:center;"
            ."color:#4a4a52;font-family:{$family},sans-serif\">"
            .$label
            .'</div>';
    }

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
