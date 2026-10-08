<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Modules\Pdf\Application\CustomerQuotationHtml;
use App\Modules\Pdf\Domain\View\CustomerAdditionalLine;
use App\Modules\Pdf\Domain\View\CustomerQuotationLine;
use App\Modules\Pdf\Domain\View\CustomerTerm;
use Illuminate\Support\Facades\Lang;
use Tests\Fixtures\CustomerQuotationViewFixture;
use Tests\TestCase;

/**
 * Module 9, Point 2.4 — the customer quotation, laid out as `D-100` since
 * F-34 · 1.2, rendered from one template in both languages.
 *
 * The HTML is checked here rather than the PDF: what a person must eventually
 * look at is 2.7's job, but every rule with a citation behind it — the labels
 * coming from lang files, the percentages coming from the quotation, no tax row
 * on an exempt one, no supplier or cost value anywhere — is a property of the
 * markup, and the markup can be read without a browser.
 */
final class CustomerQuotationHtmlTest extends TestCase
{
    private const TEMPLATE = 'resources/views/pdf/customer-quotation.blade.php';

    public function test_that_each_language_renders_its_own_direction_and_labels(): void
    {
        $ar = $this->html(locale: 'ar');
        $en = $this->html(locale: 'en');

        self::assertStringContainsString('<html lang="ar" dir="rtl"', $ar);
        self::assertStringContainsString('<html lang="en" dir="ltr"', $en);

        self::assertStringContainsString((string) Lang::get('pdf.header.subject', [], 'ar'), $ar);
        self::assertStringContainsString((string) Lang::get('pdf.header.subject', [], 'en'), $en);
        self::assertStringNotContainsString((string) Lang::get('pdf.header.subject', [], 'en'), $ar);
    }

    public function test_that_the_template_carries_no_user_facing_text_of_its_own(): void
    {
        // Module 0's rule, and §14.6's "no string literals". Checked against the
        // lang file itself rather than a hand-written word list: every English
        // label the customer can read must be absent from the template once its
        // Blade comments and expressions are stripped — `{{ $view->finalTotal }}`
        // contains "Total", and a naive scan flags that instead of a real label.
        $source = self::templateWithoutBladeExpressions();

        foreach (self::englishLabels() as $label) {
            self::assertStringNotContainsString($label, $source, "'{$label}' is written into the template instead of the lang file.");
        }

        self::assertGreaterThan(10, substr_count((string) file_get_contents(base_path(self::TEMPLATE)), '$t('), 'The template barely uses the translator.');
    }

    /**
     * Every English label long enough to be unambiguous and free of placeholders.
     *
     * @return list<string>
     */
    private static function englishLabels(): array
    {
        $flat = [];
        $labels = (array) Lang::get('pdf', [], 'en');
        array_walk_recursive(
            $labels,
            static function (mixed $value) use (&$flat): void {
                // Only a `:placeholder` disqualifies a label — not any colon,
                // which would have excluded the intro line ending in one and
                // let a hard-coded copy of it through (found by probe).
                if (is_string($value) && mb_strlen($value) >= 5 && preg_match('/:[a-z_]+/', $value) !== 1) {
                    $flat[] = $value;
                }
            },
        );

        self::assertGreaterThan(8, count($flat), 'The lang file gave the scanner almost nothing to look for.');

        return $flat;
    }

    private static function templateWithoutBladeExpressions(): string
    {
        $source = (string) file_get_contents(base_path(self::TEMPLATE));

        // Blade comments, echoes and directives carry property names and keys,
        // not text the customer reads.
        $stripped = preg_replace(['/\{\{--.*?--\}\}/s', '/\{\{.*?\}\}/s', '/\{!!.*?!!\}/s', '/@\w+\s*\([^)]*\)/s'], '', $source);

        return (string) $stripped;
    }

    public function test_that_the_percentages_come_from_the_quotation_and_not_from_the_label(): void
    {
        // The defect found against the source document on 2026-09-13: P-01's
        // dictionaries carried "Discount 5%" and "14% VAT" as literal strings.
        $html = $this->html(discountPercent: '7.5', taxPercent: '10');

        self::assertStringContainsString('7.5', $html);
        self::assertStringContainsString('10', $html);

        $source = (string) file_get_contents(base_path(self::TEMPLATE));
        self::assertStringNotContainsString('5%', $source);
        self::assertStringNotContainsString('14%', $source);
    }

    public function test_that_an_exempt_quotation_renders_no_tax_row_at_all(): void
    {
        // D-63: "an exempt quotation renders no tax line at all, not a zero line".
        $html = $this->html(taxPercent: null, taxAmount: null);

        self::assertStringNotContainsString((string) Lang::get('pdf.totals.tax', ['percent' => ''], 'en'), $html);
        self::assertStringNotContainsString('id="tax-row"', $html);

        self::assertStringContainsString('id="tax-row"', $this->html(), 'A taxed quotation must still show the row.');
    }

    public function test_that_the_optional_lines_are_absent_rather_than_empty(): void
    {
        $bare = $this->html(
            subject: null,
            signatoryName: null,
            customerContact: null,
            terms: [],
            companyAddress: null,
            companyPhones: null,
            quotationDate: null,
            validUntil: null,
        );

        $optional = ['subject-line', 'signatory', 'contact-line',
            'company-address', 'company-phones', 'issue-date', 'valid-until-line'];

        foreach ($optional as $id) {
            self::assertStringNotContainsString('id="'.$id.'"', $bare, "{$id} was rendered with nothing in it.");
        }

        $full = $this->html();
        foreach ($optional as $id) {
            self::assertStringContainsString('id="'.$id.'"', $full);
        }
    }

    public function test_that_the_terms_are_numbered_lines_currency_first_and_validity_last(): void
    {
        // D-103's ruling 5: 1. the offer currency, then the employee's terms
        // in their order as "Name: body", the offer validity last. Ruling 4:
        // a ready term with no name prints the PDF's own label in the PDF's
        // language, and a typed name prints exactly as typed — the ready
        // payment term renamed here included.
        $terms = [
            new CustomerTerm('warranty', null, 'One year.'),
            new CustomerTerm(null, 'Spare parts', 'Six months.'),
            new CustomerTerm('payment_terms', 'Payment schedule', 'Net 30.'),
            new CustomerTerm('delivery_terms', null, 'Within two weeks.'),
        ];
        $labels = ['en' => ['Warranty', 'Delivery'], 'ar' => ['الضمان', 'التسليم']];

        foreach (['ar', 'en'] as $locale) {
            self::assertSame([
                '1. '.Lang::get('pdf.conditions.currency', ['currency' => 'EGP'], $locale),
                '2. '.$labels[$locale][0].': One year.',
                '3. Spare parts: Six months.',
                '4. Payment schedule: Net 30.',
                '5. '.$labels[$locale][1].': Within two weeks.',
                '6. '.Lang::get('pdf.conditions.validity', ['date' => '2026-08-13'], $locale),
            ], self::termLines($this->html(locale: $locale, terms: $terms)), "The {$locale} terms are not numbered in D-103's order.");
        }

        // The owner's ruling, 2026-10-08: Latin digits in both languages, and
        // on the Arabic page the number takes the line's own direction, so
        // the dot sits between the number and the text (".1" read from the
        // left), the way Word numbers an Arabic list. A direction isolate
        // around the number would turn it into "1." — so the line opens with
        // the bare number, nothing before it.
        $ar = $this->html(locale: 'ar', terms: $terms);
        foreach (range(1, 6) as $n) {
            self::assertMatchesRegularExpression('/<p\b[^>]*>\s*'.$n.'\. /u', $ar, "Arabic term {$n} does not open with its bare number.");
        }
    }

    public function test_that_the_payment_label_is_the_pdfs_own_in_each_language(): void
    {
        $terms = [new CustomerTerm('payment_terms', null, 'Net 30.')];

        self::assertSame('2. Payment: Net 30.', self::termLines($this->html(locale: 'en', terms: $terms))[1]);
        self::assertSame('2. الدفع: Net 30.', self::termLines($this->html(locale: 'ar', terms: $terms))[1]);
    }

    public function test_that_no_terms_leave_only_the_currency_and_the_validity(): void
    {
        foreach (['ar', 'en'] as $locale) {
            self::assertSame([
                '1. '.Lang::get('pdf.conditions.currency', ['currency' => 'EGP'], $locale),
                '2. '.Lang::get('pdf.conditions.validity', ['date' => '2026-08-13'], $locale),
            ], self::termLines($this->html(locale: $locale, terms: [])));

            self::assertSame(
                ['1. '.Lang::get('pdf.conditions.currency', ['currency' => 'EGP'], $locale)],
                self::termLines($this->html(locale: $locale, terms: [], validUntil: null)),
                'With no validity date, the currency is the only line.',
            );
        }
    }

    /**
     * The terms block's lines as a reader sees them: tags, entities and the
     * direction isolates gone.
     *
     * @return list<string>
     */
    private static function termLines(string $html): array
    {
        $block = (string) strstr((string) strstr($html, 'class="terms"'), 'class="totals"', true);
        self::assertNotSame('', $block, 'The page has no terms block before the totals.');

        preg_match_all('/<p\b[^>]*>(.*?)<\/p>/su', $block, $matches);

        return array_map(
            static fn (string $line): string => trim((string) preg_replace(
                ['/[\x{2066}-\x{2069}]/u', '/\s+/u'],
                ['', ' '],
                html_entity_decode(strip_tags($line), ENT_QUOTES | ENT_HTML5),
            )),
            $matches[1],
        );
    }

    public function test_that_the_header_prints_the_company_from_the_settings(): void
    {
        // D-100's first ruling and §14.6's "company logo and details from
        // settings": the name, the address and the phones are text from
        // Settings, the phones as typed. The template before D-100 printed none
        // of the three — the footer band carried them as pixels. There is no
        // e-mail line because no setting holds one.
        //
        // Each line is wrapped in a first-strong isolate (U+2068 … U+2069),
        // found on the first real Arabic PDF: inside the RTL page, bidi printed
        // `5-El-Fath St, …` as `El-Fath St, … Egypt-5` and swapped the two
        // phone numbers. The isolate takes its direction from the text itself —
        // a Latin address reads left to right, an Arabic one right to left, and
        // a line of digits, having no letter, left to right.
        foreach (['ar', 'en'] as $locale) {
            $html = $this->html(locale: $locale);

            self::assertStringContainsString(e('Smart Technology for Integrated Systems'), $html, "The {$locale} header has no company name.");
            self::assertMatchesRegularExpression('/id="company-address"[^>]*>\s*\x{2068}'.preg_quote(e('5 El-Fath St, Wezarra Station, Boulkly, Alexandria, Egypt'), '/').'\x{2069}\s*</u', $html, "The {$locale} address is missing or not isolated.");
            self::assertMatchesRegularExpression('/id="company-phones"[^>]*>\s*\x{2068}'.preg_quote(e('035829952 · 01070764779'), '/').'\x{2069}\s*</u', $html, "The {$locale} phones are missing or not isolated.");
        }
    }

    public function test_that_the_side_margins_are_inside_the_page_where_chrome_does_not_clip(): void
    {
        // Reported by the owner on the first PDFs of D-100's layout: the item
        // table's right border was missing, in both languages. Chrome clips the
        // page at the margins the renderer sets, and a full-width table with
        // collapsed borders draws half of its outer border outside its own box,
        // so the right half was cut. The layout before D-100 lost it too, under
        // a darker border (measured on a PDF from main). So the side margins are
        // padding inside the page, and the renderer's left and right margins are
        // 0. Measured after the change, at 300 dpi: both borders drawn, the
        // table at 294..2186 px like the prototype. No rasteriser ships in the
        // image, so this test pins the arrangement rather than the pixels.
        $template = (string) file_get_contents(base_path(self::TEMPLATE));
        self::assertMatchesRegularExpression('/\bbody\s*\{[^}]*padding:\s*0 25mm/', $template, 'The side margins are not padding inside the page.');

        $renderer = (string) file_get_contents(base_path('app/Modules/Pdf/Infrastructure/BrowsershotPdfRenderer.php'));
        self::assertStringContainsString('->margins(22, 0, 20, 0)', $renderer, 'The renderer still sets side margins, where Chrome clips.');
    }

    public function test_that_the_page_renders_in_standards_mode(): void
    {
        // Found on the first real PDF: without a doctype Chrome renders the
        // page in quirks mode, where a table does not inherit the body's font
        // size — the item table printed at 12pt against the prototype's 9pt.
        foreach (['ar', 'en'] as $locale) {
            self::assertStringStartsWith('<!doctype html>', strtolower(ltrim($this->html(locale: $locale))), "The {$locale} page has no doctype.");
        }
    }

    public function test_that_the_logo_is_the_only_picture_on_the_page(): void
    {
        // D-100's second ruling: the footer band and the faded S.T.I.S mark are
        // gone, so the logo — a PNG — is the one image left.
        $html = $this->html();

        self::assertSame(1, substr_count($html, '<img'), 'Something besides the logo is drawn on the page.');
        self::assertStringNotContainsString('data:image/jpeg', $html, 'The footer band or the watermark is still embedded.');
    }

    public function test_that_the_layout_reads_its_labels_from_the_lang_file_in_both_languages(): void
    {
        // D-100's layout: the heading, the parties block and the terms heading
        // are new labels, and the column titles are the owner's own words.
        self::assertSame(
            ['serial' => '#', 'item' => 'Description', 'unit_price' => 'Unit Price', 'quantity' => 'Qty', 'line_total' => 'Total'],
            Lang::get('pdf.table', [], 'en'),
            'The column titles are not the ones D-100 names.',
        );

        foreach (['ar', 'en'] as $locale) {
            $html = $this->html(locale: $locale);
            // The page, not the <title>: "Quotation QT-2026-0001" there would
            // pass for a missing heading (found by removing it on purpose).
            $body = (string) strstr($html, '<body');

            foreach (['heading', 'parties.prepared_for', 'parties.issue_date', 'parties.valid_until', 'terms.heading', 'header.attention', 'header.subject'] as $key) {
                $label = (string) Lang::get('pdf.'.$key, [], $locale);
                self::assertNotSame('pdf.'.$key, $label, "pdf.{$key} is missing from the {$locale} lang file.");
                self::assertStringContainsString(e($label), $body, "The {$locale} page does not show pdf.{$key}.");
            }

            // The five titles, in the order the table prints them.
            $titles = array_map(
                static fn (string $key): string => '<th[^>]*>\s*'.preg_quote(e((string) Lang::get('pdf.table.'.$key, [], $locale)), '/').'\s*<\/th>',
                ['serial', 'item', 'unit_price', 'quantity', 'line_total'],
            );
            self::assertMatchesRegularExpression('/'.implode('\s*', $titles).'/u', $html, "The {$locale} column titles are missing or out of order.");

            // The terms are plain lines in the prototype, not a bulleted list.
            self::assertStringNotContainsString('<li', $html, "The {$locale} terms are still bullets.");
        }
    }

    public function test_that_the_text_is_black_and_the_table_lines_grey(): void
    {
        // The owner's changes to the Conta layout (D-100): every word black, the
        // column-title row #f2f2f2, the table lines #d9d9d9 — a little darker.
        // Read from the template's own CSS: a colour is a declaration, and the
        // rendered markup carries the same text.
        $source = (string) file_get_contents(base_path(self::TEMPLATE));

        preg_match_all('/(?<![\w-])color\s*:\s*([^;}\s]+)/i', $source, $colours);
        self::assertNotSame([], $colours[1], 'The template sets no text colour at all.');
        foreach ($colours[1] as $colour) {
            self::assertSame('#000', strtolower($colour), "A text colour of {$colour} is not black.");
        }

        self::assertMatchesRegularExpression('/\bth\b[^{}]*\{[^}]*background(-color)?\s*:\s*#f2f2f2/i', $source, 'The column-title row is not light grey.');
        self::assertMatchesRegularExpression('/\btd\b[^{}]*\{[^}]*border\s*:[^;]*#d9d9d9/i', $source, 'The table lines are not the darker grey.');

        // The page number is text on the page too.
        $html = $this->app->make(CustomerQuotationHtml::class);
        foreach (['ar', 'en'] as $locale) {
            preg_match_all('/(?<![\w-])color\s*:\s*([^;"]+)/i', $html->footer($locale), $footerColours);
            self::assertSame(['#000'], $footerColours[1], "The {$locale} page number is not black.");
        }
    }

    public function test_that_no_supplier_or_cost_value_can_reach_the_page(): void
    {
        // 1.2 proved the model cannot hold them; this proves the template does
        // not invent them. The same sixteen values, one layer later.
        $html = $this->html();

        foreach (['4111.17', '4222.28', '4333.39', '8222.34', '12666.84', '17.25', '18.35', '19.45', '21.75', '48.7310'] as $leak) {
            self::assertStringNotContainsString($leak, $html, "\"{$leak}\" is a cost or a margin (§3.12 rule 2, §14.6).");
        }
    }

    public function test_that_the_page_fetches_nothing_at_render_time(): void
    {
        $html = $this->html();

        self::assertStringContainsString('data:font/woff2;base64,', $html);
        self::assertStringContainsString('data:image/png;base64,', $html);
        self::assertStringNotContainsString('http://', $html);
        self::assertStringNotContainsString('https://', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function test_that_the_customers_own_figures_are_all_on_the_page(): void
    {
        $html = $this->html();

        // Decoded, because Blade escapes what it prints — "Delivery &amp;
        // Installation" on the page is the escaping working, not a defect.
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);

        foreach (['QT-2026-0001', 'Vegatrone', '5219.30', '10438.60', '34854.10', '37996.98', 'Formatter M428dw', 'Delivery & Installation'] as $shown) {
            self::assertStringContainsString($shown, $text);
        }
    }

    public function test_that_the_page_number_footer_is_localised_and_left_to_chrome(): void
    {
        // Point 2.5, and D-79's carried-forward item: the count varies per
        // quotation, so the numbering belongs to Chrome's footerTemplate and
        // not to the document.
        $html = $this->app->make(CustomerQuotationHtml::class);

        foreach (['en', 'ar'] as $locale) {
            $footer = $html->footer($locale);

            self::assertStringContainsString('<span class="pageNumber"></span>', $footer);
            self::assertStringContainsString('<span class="totalPages"></span>', $footer);
            // No embedded face here, and the docblock says why: Browsershot
            // passes this template as a command argument, and a base64 face
            // makes the command too long for the OS. The families are the ones
            // the rendering image installs.
            self::assertStringNotContainsString('data:font/woff2;base64,', $footer);
            // D-101: Inter first in both languages, so the page numbers are
            // Inter; Arabic words fall through to Naskh, the page's own face.
            self::assertStringContainsString("font-family:'Inter', 'Noto Naskh Arabic',sans-serif", $footer);
            self::assertLessThan(2000, strlen($footer), 'A footer this large risks Chrome\'s command-line limit.');
        }

        // The words come from the lang files, and Arabic puts them in the other
        // order — which is why the label takes both numbers as placeholders
        // rather than being concatenated around them.
        self::assertStringContainsString('Page', $html->footer('en'));
        self::assertStringContainsString('صفحة', $html->footer('ar'));
        self::assertStringContainsString('dir="rtl"', $html->footer('ar'));

        // The visible text only — `class="totalPages"` is Chrome's own hook and
        // contains "Page", which an assertion over the whole string would flag.
        $arabicText = strip_tags($html->footer('ar'));
        self::assertDoesNotMatchRegularExpression('/[A-Za-z]{2,}/', $arabicText, 'English reached the Arabic footer.');
    }

    public function test_that_an_unknown_locale_is_refused_by_both_halves(): void
    {
        $html = $this->app->make(CustomerQuotationHtml::class);

        foreach (['fr', 'en-GB', ''] as $locale) {
            try {
                $html->footer($locale);
                self::fail("The footer rendered in {$locale}.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('ar or en', $e->getMessage());
            }
        }
    }

    public function test_that_dates_keep_their_order_on_the_arabic_page(): void
    {
        // Found at Point 2.7 by looking at the rendered page: `2026-08-13` came
        // out as `13-08-2026` in Arabic. Bidi reorders a Latin-digit date's
        // parts inside an RTL paragraph, and the reader cannot tell which half
        // is the day. U+2066…U+2069 isolate it — invisible characters, so Blade
        // still escapes the value.
        $ar = $this->html(locale: 'ar');

        foreach (['2026-07-14', '2026-08-13'] as $date) {
            self::assertStringContainsString("\u{2066}{$date}\u{2069}", $ar, "The {$date} on the Arabic page is not isolated.");
        }

        // The English page carries the same isolates: one template, one rule,
        // and nothing to forget when a third language is added.
        self::assertStringContainsString("\u{2066}2026-07-14\u{2069}", $this->html(locale: 'en'));
    }

    public function test_that_money_prints_at_two_places_rounded_half_up(): void
    {
        // F-33 · 1.2, D-99. The figures arrive at the scale D-68 stores them —
        // NUMERIC(18,6) — and the page printed them so: `110.000000`. The
        // fixture's hand-typed `5219.30` is why no test saw it, so every value
        // here is at stored scale, and every cell is matched whole: a substring
        // check finds `110.00` inside `110.000000` and passes the defect.
        // `100.125000` and `1000.005000` sit on the half: truncation and
        // half-even would both print `.12` / `.00`, half-up prints `.13` / `.01`.
        // `-0.125000` is the negative half — a real rounding difference when
        // an EGP total of `1234.125` rounds to the unit 1: half away from zero
        // prints `-0.13`, and a half-up toward +∞ would print `-0.12`.
        foreach (['ar', 'en'] as $locale) {
            $html = $this->html(
                locale: $locale,
                lines: [new CustomerQuotationLine(1, 'Formatter M428dw', '1.0000', '110.000000', '100.125000')],
                additionalItems: [new CustomerAdditionalLine(1, 'Delivery & Installation', '250.000000')],
                subtotal: '1000.005000',
                discountAmount: '50.000500',
                taxAmount: '133.994999',
                roundingDiff: '-0.125000',
                finalTotal: '695.000000',
            );

            foreach ([
                'unit price' => '<td class="money">110.00</td>',
                'line total' => '<td class="money">100.13</td>',
                'subtotal' => '<td class="money">1000.01</td>',
                'additional item' => '<td class="money">250.00</td>',
                'discount' => '<td class="money">50.00</td>',
                'tax' => '<td class="money">133.99</td>',
                'rounding difference' => '<td class="money">-0.13</td>',
                'final total' => '<td class="money">695.00 EGP</td>',
            ] as $figure => $cell) {
                self::assertStringContainsString($cell, $html, "The {$figure} on the {$locale} page is not at two places, half-up (D-99).");
            }
        }
    }

    public function test_that_quantities_and_percentages_drop_their_trailing_zeros(): void
    {
        // D-99: `1.0000` → `1`, `2.5000` → `2.5`, `14.000` → `14`. Only zeros
        // after a point go: `10.0000` keeps its `10`, and a value with no point
        // is left alone — `20` is not `2`. The line numbers start at 6 so no
        // serial cell can pass for a quantity cell.
        foreach (['ar', 'en'] as $locale) {
            $html = $this->html(
                locale: $locale,
                lines: [
                    new CustomerQuotationLine(6, 'Formatter M428dw', '1.0000', '110.000000', '110.000000'),
                    new CustomerQuotationLine(7, 'Toner CF259A', '2.5000', '110.000000', '275.000000'),
                    new CustomerQuotationLine(8, 'Fuser RM2-5399', '10.0000', '110.000000', '1100.000000'),
                    new CustomerQuotationLine(9, 'Drum CF232A', '20', '110.000000', '2200.000000'),
                ],
                discountPercent: '7.500',
                taxPercent: '14.000',
            );

            foreach (['1', '2.5', '10', '20'] as $quantity) {
                self::assertStringContainsString('<td class="num">'.$quantity.'</td>', $html, "The quantity {$quantity} on the {$locale} page kept its trailing zeros or lost a digit.");
            }

            self::assertStringContainsString('<td>'.e((string) Lang::get('pdf.totals.discount', ['percent' => '7.5'], $locale)).'</td>', $html, "The {$locale} discount percentage kept its trailing zeros.");
            self::assertStringContainsString('<td>'.e((string) Lang::get('pdf.totals.tax', ['percent' => '14'], $locale)).'</td>', $html, "The {$locale} tax percentage kept its trailing zeros.");
        }
    }

    public function test_that_a_zero_rounding_difference_at_stored_scale_prints_no_rounding_row(): void
    {
        // D-65: with rounding off, rounding_diff is 0 — stored as `0.000000`,
        // which the template's `!== '0.00'` never matched, so every quotation
        // printed a zero rounding line. D-99 keys the row on the two-place
        // figure, so a difference too small to show is no row either, and
        // `-0.004000` must not come out as a `-0.00` row.
        foreach (['0.000000', '0.004000', '-0.004000'] as $zero) {
            foreach (['ar', 'en'] as $locale) {
                self::assertStringNotContainsString(
                    'id="rounding-row"',
                    $this->html(locale: $locale, roundingDiff: $zero),
                    "A rounding difference of {$zero} printed a rounding row on the {$locale} page.",
                );
            }
        }

        self::assertStringContainsString('id="rounding-row"', $this->html(roundingDiff: '-0.400000'), 'A real rounding difference must still show its row.');
    }

    public function test_that_a_money_figure_that_is_not_a_plain_decimal_is_refused_not_printed(): void
    {
        // DB-07 through `Decimal::of`: `1e5` is numeric to PHP but not a
        // decimal string, and a customer document refuses it rather than
        // printing whatever BCMath would make of it. The reason is asserted,
        // not the type: Blade wraps whatever a render throws in a
        // `ViewException` that keeps the message.
        $this->expectExceptionMessage('DB-07');

        $this->html(subtotal: '1e5');
    }

    private function html(
        string $locale = 'en',
        mixed ...$overrides,
    ): string {
        /** @var array<string, mixed> $overrides */
        return $this->app->make(CustomerQuotationHtml::class)->render(
            CustomerQuotationViewFixture::make($overrides),
            $locale,
        );
    }
}
