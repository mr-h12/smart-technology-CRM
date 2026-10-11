<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application;

use App\Modules\Admin\Domain\Money\Decimal;
use App\Modules\Pdf\Domain\Contracts\PdfAssetsInterface;
use App\Modules\Pdf\Domain\View\CustomerQuotationView;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use InvalidArgumentException;

/**
 * `CustomerQuotationView` → the HTML `PdfRendererInterface` turns into a PDF —
 * Module 9, Point 2.4, laid out as `D-100` (F-34 · 1.2).
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
 * It gets the view, the logo's `data:` URI, the font CSS, `$t` and the three
 * display formatters (`ltr`, `money`, `plain`). It is handed no repository,
 * no request and no quotation of Module 7's, so a template cannot reach past
 * the model 1.1 built to be safe.
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
     * The footer Chrome draws in every page's bottom margin — `D-107`: the
     * company's name, address, phones and e-mail, and the page number (Point
     * 2.5) small at the end of their last line, in English on both pages
     * (F-40 · 1.6).
     *
     * A separate document from the page above it: Chrome renders
     * `footerTemplate` on its own, so it inherits none of the template's CSS.
     * Its measures are the reference footer's
     * (`QT-2026-0003-commercial-offer-en.html`); the renderer's bottom margin
     * is the band they fill.
     *
     * ⚠️ **This is the one place the document does not embed its faces**, and
     * not by choice: Browsershot passes the template to Chrome as a command
     * argument, so a base64 face in it makes the command exceed the OS limit —
     * `proc_open(): posix_spawn() failed: Argument list too long`, measured.
     * Inter is the face `docker/php/Dockerfile` installs in the image that
     * renders (`fonts-inter`), which under `D-66` is the production
     * environment too, with `fonts.conf` pinning how a family name resolves.
     *
     * The two spans are Chrome's own: it replaces their contents per page.
     * Each Settings value is isolated (U+2068 … U+2069) as on the page: inside
     * a line, bidi otherwise reorders `5-El-Fath St, …` and the phone numbers.
     *
     * @throws InvalidArgumentException when the locale is not `ar` or `en`
     */
    public function footer(CustomerQuotationView $view, string $locale): string
    {
        self::LOCALES[$locale] ?? throw new InvalidArgumentException(
            "The customer PDF renders in ar or en, not {$locale}."
        );

        $t = fn (string $key): string => e((string) $this->translator->get('pdf.footer.'.$key, [], $locale));
        $line = static fn (string $id, string $label, ?string $value): string => $value === null ? ''
            : "<div id=\"{$id}\"><span class=\"k\">{$label}</span>".e(self::isolate($value)).'</div>';

        $page = (string) $this->translator->get('pdf.footer.page', [
            'current' => '<span class="pageNumber"></span>',
            'total' => '<span class="totalPages"></span>',
        ], $locale);

        // Chrome anchors the footer to the foot of its band and clips below
        // it, so the block is lowered onto the reference's lines (`top`,
        // measured) and the page number shares its last line, at the end —
        // a line of its own under the block was clipped (the owner, 2026-10-11).
        return '<div dir="ltr" lang="en" style="width: 100%; position: relative; top: 4.6mm"><style>'
            .'#f { margin: 0 22mm; border-top: 0.75pt solid #bfbfbf; padding-top: 3mm; color: #000;'
            ." font-family: 'Inter'; font-size: 8.5pt; line-height: 1.6;"
            .' display: flex; justify-content: space-between; align-items: flex-end; }'
            .' #f .co { font-weight: 700; font-size: 9.5pt; margin-bottom: 0.5mm; }'
            .' #f .k { display: inline-block; width: 16mm; font-weight: 700; }'
            .' #f .pg { font-size: 7pt; }'
            .'</style><div id="f"><div>'
            .'<div class="co">'.e($view->companyName).'</div>'
            .$line('company-address', $t('address'), $view->companyAddress)
            .$line('company-phones', $t('tel'), $view->companyPhones)
            .$line('company-email', $t('email'), $view->companyEmail)
            .'</div><div class="pg">'.$page.'</div>'
            .'</div></div>';
    }

    /**
     * @param  string  $locale  `ar` or `en` — §1's two languages, and no third
     * @param  bool  $multiPage  the document already takes more than one page
     *
     * @throws InvalidArgumentException when the locale is not one of them
     */
    public function render(CustomerQuotationView $view, string $locale, bool $multiPage = false): string
    {
        $direction = self::LOCALES[$locale] ?? throw new InvalidArgumentException(
            "The customer PDF renders in ar or en, not {$locale}."
        );

        return $this->views->make('pdf.customer-quotation', [
            'view' => $view,
            'locale' => $locale,
            'direction' => $direction,
            // D-107: a document longer than a page takes the longer offer's
            // spacing; only a render tells, so `RenderQuotationPdf` asks twice.
            'multiPage' => $multiPage,
            'fontFaceCss' => $this->assets->fontFaceCss(),
            'logo' => $this->assets->logo(),
            't' => fn (string $key, array $replace = []): string => (string) $this->translator->get(
                'pdf.'.$key,
                $replace,
                $locale,
            ),
            // A date is Latin digits with neutral separators, so in an RTL
            // paragraph bidi reorders its parts: `2026-08-13` was rendering as
            // `13-08-2026` on the Arabic page — the same value, printed in an
            // order the reader cannot tell apart from a day-first date. The
            // isolate characters (U+2066 … U+2069) pin it, and they are
            // invisible text rather than markup, so Blade still escapes the
            // value itself.
            'ltr' => static fn (?string $value): string => $value === null ? '' : "\u{2066}{$value}\u{2069}",
            // D-99. The view carries each figure at the scale D-68 stores it
            // (`110.000000`, `1.0000`, `14.000`). Money prints at two places,
            // rounded half away from zero (`bcround`'s default mode) by BCMath
            // on the checked string — display only, never a float (DB-07) and
            // never fed back into a total (D-06). Quantities and percentages
            // lose the zeros after their point, and the point with them; a
            // value without one is left alone, so `20` stays `20`.
            'money' => static fn (string $value): string => bcround(Decimal::of($value, 'a PDF money figure'), 2),
            'plain' => static fn (string $value): string => str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value,
        ])->render();
    }

    /**
     * F-34 · 1.2. A Settings value is typed in either script, so it is isolated
     * without a forced direction (U+2068 FSI): the first letter decides, and a
     * line of digits has none and reads left to right. Unisolated,
     * `5-El-Fath St, …` printed as `… Egypt-5` and two phone numbers swapped.
     */
    private static function isolate(?string $value): string
    {
        return $value === null ? '' : "\u{2068}{$value}\u{2069}";
    }
}
