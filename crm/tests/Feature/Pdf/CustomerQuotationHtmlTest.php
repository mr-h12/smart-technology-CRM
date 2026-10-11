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
 * Module 9, Point 2.4 — the customer quotation, laid out as `D-107`'s
 * *Commercial Offer* since F-40 · 1.5, rendered from one template in both
 * languages.
 *
 * The HTML is checked here rather than the PDF: where each word lands on the
 * page is 1.6's comparison against the reference PDFs, but every rule with a
 * citation behind it — the labels coming from lang files, the percentages
 * coming from the quotation, no tax column or row on an exempt one, no
 * supplier or cost value anywhere — is a property of the markup, and the
 * markup can be read without a browser.
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

        self::assertStringContainsString((string) Lang::get('pdf.info.subject', [], 'ar'), $ar);
        self::assertStringContainsString((string) Lang::get('pdf.info.subject', [], 'en'), $en);
        self::assertStringNotContainsString((string) Lang::get('pdf.info.subject', [], 'en'), $ar);
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

    public function test_that_the_page_opens_with_the_commercial_offer_title_and_its_info_block_in_order(): void
    {
        // D-107: the logo, the centred title, then Offer No. · Issue Date ·
        // Prepared For · Att. · Subject, each label followed by its value.
        foreach (['ar', 'en'] as $locale) {
            $body = (string) strstr($this->html(locale: $locale), '<body');
            $label = static fn (string $key): string => preg_quote(e((string) Lang::get('pdf.'.$key, [], $locale)), '/');

            self::assertMatchesRegularExpression('/class="title"[^>]*>\s*'.$label('heading').'\s*</u', $body, "The {$locale} page has no Commercial Offer title.");

            self::assertMatchesRegularExpression(
                '/'.$label('info.offer_no').'.*?QT-2026-0001'
                .'.*?'.$label('info.issue_date').'.*?2026-07-14'
                .'.*?'.$label('info.prepared_for').'.*?'.preg_quote(e($locale === 'ar' ? 'شركة فيجاترون' : 'Vegatrone'), '/')
                .'.*?'.$label('info.attention')
                .'.*?'.$label('info.subject').'/su',
                $this->html(locale: $locale, customerName: $locale === 'ar' ? 'شركة فيجاترون' : 'Vegatrone'),
                "The {$locale} info block is missing a line or out of D-107's order.",
            );
        }
    }

    public function test_that_the_attention_line_carries_the_contacts_title_in_each_language(): void
    {
        // D-104 through D-107: the English page prints the stored English
        // title before the name; the Arabic page prints the fixed «أ.».
        self::assertMatchesRegularExpression('/id="contact-line"[^>]*>.*?Mr\. Medhat\s*</su', $this->html(locale: 'en'));
        self::assertMatchesRegularExpression('/id="contact-line"[^>]*>.*?أ\. مدحت\s*</su', $this->html(locale: 'ar', customerContact: 'مدحت'));

        // No title: the name alone, in both languages.
        foreach (['ar', 'en'] as $locale) {
            $html = $this->html(locale: $locale, customerContactTitle: null);
            self::assertMatchesRegularExpression('/id="contact-line"[^>]*>.*?<\/span>\s*Medhat\s*</su', $html, "The {$locale} attention line invented a title.");
        }
    }

    public function test_that_the_validity_date_prints_only_in_its_term(): void
    {
        // D-107: "Valid until prints only in the validity term".
        foreach (['ar', 'en'] as $locale) {
            $html = $this->html(locale: $locale);

            self::assertStringNotContainsString('id="valid-until-line"', $html);
            self::assertSame(1, substr_count($html, '2026-08-13'), "The {$locale} page prints the validity date outside its term.");
        }
    }

    public function test_that_the_table_has_d107s_six_columns_in_order(): void
    {
        self::assertSame(
            ['description' => 'Description', 'unit' => 'Unit', 'quantity' => 'Qty', 'unit_price' => 'Unit Price', 'vat' => 'VAT', 'total' => 'Total'],
            Lang::get('pdf.table', [], 'en'),
            'The column titles are not the ones D-107 names.',
        );

        foreach (['ar', 'en'] as $locale) {
            $titles = array_map(
                static fn (string $key): string => '<th[^>]*>\s*'.preg_quote(e((string) Lang::get('pdf.table.'.$key, [], $locale)), '/').'\s*<\/th>',
                ['description', 'unit', 'quantity', 'unit_price', 'vat', 'total'],
            );
            self::assertMatchesRegularExpression('/'.implode('\s*', $titles).'/u', $this->html(locale: $locale), "The {$locale} column titles are missing or out of order.");
        }
    }

    public function test_that_each_line_prints_its_unit_vat_and_total_with_a_dash_for_no_unit(): void
    {
        // F-40 · 1.4's figures, printed: the unit in the document's language,
        // `—` when the item has none; the line's VAT; the discounted line + VAT.
        $cells = static fn (string ...$values): string => '/'.implode('\s*', array_map(
            static fn (string $v): string => '<td[^>]*>\s*'.preg_quote(e($v), '/').'\s*<\/td>',
            $values,
        )).'/u';

        $en = $this->html(locale: 'en');
        self::assertMatchesRegularExpression($cells('Formatter M428dw', 'Pcs', '2', '5219.30', '1388.33', '11305.00'), $en);
        self::assertMatchesRegularExpression($cells('Toner CF259A', 'Box', '3', '6332.50', '2526.67', '20574.29'), $en);
        self::assertMatchesRegularExpression($cells('Fuser RM2-5399', '—', '1', '5418.00', '720.59', '5867.69'), $en);

        $ar = $this->html(locale: 'ar');
        self::assertMatchesRegularExpression($cells('Formatter M428dw', 'قطعة', '2', '5219.30', '1388.33', '11305.00'), $ar, 'The Arabic page does not print the Arabic unit.');
        self::assertMatchesRegularExpression($cells('Fuser RM2-5399', '—', '1', '5418.00', '720.59', '5867.69'), $ar);
    }

    public function test_that_an_additional_item_is_a_table_row_with_a_dash_unit_quantity_one_and_no_vat(): void
    {
        // D-107's default: unit `—`, quantity 1, VAT `0.00` (D-62), the amount
        // as both the unit price and the total.
        foreach (['ar', 'en'] as $locale) {
            self::assertMatchesRegularExpression(
                '/<td[^>]*>\s*Delivery &amp; Installation\s*<\/td>\s*<td[^>]*>\s*—\s*<\/td>\s*<td[^>]*>\s*1\s*<\/td>\s*<td[^>]*>\s*250\.00\s*<\/td>\s*<td[^>]*>\s*0\.00\s*<\/td>\s*<td[^>]*>\s*250\.00\s*<\/td>/u',
                $this->html(locale: $locale),
                "The {$locale} additional item is not a row of the table.",
            );
        }
    }

    public function test_that_an_exempt_quotation_prints_no_vat_column_no_vat_row_and_no_vat_term(): void
    {
        // D-63 and D-107: "an exempt quotation prints no VAT column, no VAT row
        // and no The price includes VAT term".
        $exempt = [
            'taxPercent' => null,
            'taxAmount' => null,
            'lines' => [new CustomerQuotationLine(1, 'Formatter M428dw', '2', '5219.30', '10438.60', 'Pcs', 'قطعة', null, '9916.670000')],
        ];

        foreach (['ar', 'en'] as $locale) {
            $vat = preg_quote(e((string) Lang::get('pdf.table.vat', [], $locale)), '/');
            $html = $this->html($locale, ...$exempt);

            self::assertDoesNotMatchRegularExpression('/<th[^>]*>\s*'.$vat.'\s*<\/th>/u', $html, "The exempt {$locale} page has a VAT column.");
            self::assertStringNotContainsString('id="tax-row"', $html);
            self::assertStringNotContainsString('id="vat-included"', $html);
            self::assertStringNotContainsString(e((string) Lang::get('pdf.conditions.vat_included', [], $locale)), $html);
            // Five cells a row: the additional item drops its 0.00 VAT cell too.
            self::assertMatchesRegularExpression('/<td[^>]*>\s*1\s*<\/td>\s*<td[^>]*>\s*250\.00\s*<\/td>\s*<td[^>]*>\s*250\.00\s*<\/td>/u', $html);
            self::assertMatchesRegularExpression('/<td[^>]*>\s*5219\.30\s*<\/td>\s*<td[^>]*>\s*9916\.67\s*<\/td>/u', $html);

            $taxed = $this->html(locale: $locale);
            self::assertMatchesRegularExpression('/<th[^>]*>\s*'.$vat.'\s*<\/th>/u', $taxed, 'A taxed quotation must still show the column.');
            self::assertStringContainsString('id="tax-row"', $taxed);
            self::assertStringContainsString('id="vat-included"', $taxed);
        }
    }

    public function test_that_the_totals_are_d107s_rows_in_order(): void
    {
        // Total Amount Excl. VAT · Discount (no percentage) · VAT n% · Total
        // with the currency after the figure.
        foreach (['ar', 'en'] as $locale) {
            $row = static fn (string $label, string $value): string => '<td[^>]*>\s*'.preg_quote(e($label), '/').'\s*<\/td>\s*<td[^>]*>\s*'.preg_quote($value, '/').'\s*<\/td>';
            $t = static fn (string $key, array $replace = []): string => (string) Lang::get('pdf.totals.'.$key, $replace, $locale);

            self::assertMatchesRegularExpression(
                '/'.$row($t('excl_vat'), '35104.10').'.*?'.$row($t('discount'), '1742.71')
                .'.*?'.$row($t('tax', ['percent' => '14']), '4635.59').'.*?class="final".*?'.$row($t('final'), '37996.98 EGP').'/su',
                $this->html(locale: $locale),
                "The {$locale} totals are missing a row or out of D-107's order.",
            );
        }

        self::assertStringNotContainsString(':percent', (string) Lang::get('pdf.totals.discount', [], 'en'), 'D-107: the discount label carries no percentage.');
    }

    public function test_that_the_percentages_come_from_the_quotation_and_not_from_the_label(): void
    {
        // The defect found against the source document on 2026-09-13: P-01's
        // dictionaries carried "Discount 5%" and "14% VAT" as literal strings.
        $html = $this->html(taxPercent: '10');

        self::assertStringContainsString(e((string) Lang::get('pdf.totals.tax', ['percent' => '10'], 'en')), $html);

        $source = (string) file_get_contents(base_path(self::TEMPLATE));
        self::assertStringNotContainsString('5%', $source);
        self::assertStringNotContainsString('14%', $source);
    }

    public function test_that_the_optional_lines_are_absent_rather_than_empty(): void
    {
        $bare = $this->html(
            subject: null,
            signatoryName: null,
            signatoryTitleEn: null,
            customerContact: null,
            terms: [],
            companyAddress: null,
            companyPhones: null,
            companyEmail: null,
            quotationDate: null,
            validUntil: null,
        );

        $optional = ['subject-line', 'signatory', 'signatory-title', 'contact-line', 'issue-date', 'validity'];

        foreach ($optional as $id) {
            self::assertStringNotContainsString('id="'.$id.'"', $bare, "{$id} was rendered with nothing in it.");
        }

        $full = $this->html();
        foreach ($optional as $id) {
            self::assertStringContainsString('id="'.$id.'"', $full);
        }

        // The company's optional lines are the footer's (F-40 · 1.6).
        $footer = $this->app->make(CustomerQuotationHtml::class);
        $bareFooter = $footer->footer(CustomerQuotationViewFixture::make(['companyAddress' => null, 'companyPhones' => null, 'companyEmail' => null]), 'en');
        $fullFooter = $footer->footer(CustomerQuotationViewFixture::make(), 'en');
        foreach (['company-address', 'company-phones', 'company-email'] as $id) {
            self::assertStringNotContainsString('id="'.$id.'"', $bareFooter, "{$id} was rendered with nothing in it.");
            self::assertStringContainsString('id="'.$id.'"', $fullFooter);
        }
    }

    public function test_that_the_terms_are_a_numbered_list_currency_first_validity_then_vat_last(): void
    {
        // D-103's ruling 5 and D-107: the offer currency, then the employee's
        // terms in their order as "Name: body", the offer validity, then — on
        // a taxed quotation — "The price includes VAT". Ruling 4: a ready term
        // with no name prints the PDF's own label in the PDF's language, and a
        // typed name prints exactly as typed. The numbers are the list's own
        // (`<ol>`, as the reference HTML): Latin digits in both languages.
        $terms = [
            new CustomerTerm('warranty', null, 'One year.'),
            new CustomerTerm(null, 'Spare parts', 'Six months.'),
            new CustomerTerm('payment_terms', 'Payment schedule', 'Net 30.'),
            new CustomerTerm('delivery_terms', null, 'Within two weeks.'),
        ];
        $labels = ['en' => ['Warranty', 'Delivery'], 'ar' => ['الضمان', 'التسليم']];

        foreach (['ar', 'en'] as $locale) {
            self::assertSame([
                Lang::get('pdf.conditions.currency', ['currency' => 'EGP'], $locale),
                $labels[$locale][0].': One year.',
                'Spare parts: Six months.',
                'Payment schedule: Net 30.',
                $labels[$locale][1].': Within two weeks.',
                Lang::get('pdf.conditions.validity', ['date' => '2026-08-13'], $locale),
                Lang::get('pdf.conditions.vat_included', [], $locale),
            ], self::termLines($this->html(locale: $locale, terms: $terms)), "The {$locale} terms are not in D-107's order.");
        }
    }

    public function test_that_the_payment_label_is_the_pdfs_own_in_each_language(): void
    {
        $terms = [new CustomerTerm('payment_terms', null, 'Net 30.')];

        self::assertSame('Payment: Net 30.', self::termLines($this->html(locale: 'en', terms: $terms))[1]);
        self::assertSame('الدفع: Net 30.', self::termLines($this->html(locale: 'ar', terms: $terms))[1]);
    }

    public function test_that_no_terms_leave_only_the_currency_the_validity_and_the_vat_line(): void
    {
        foreach (['ar', 'en'] as $locale) {
            self::assertSame([
                Lang::get('pdf.conditions.currency', ['currency' => 'EGP'], $locale),
                Lang::get('pdf.conditions.validity', ['date' => '2026-08-13'], $locale),
                Lang::get('pdf.conditions.vat_included', [], $locale),
            ], self::termLines($this->html(locale: $locale, terms: [])));

            self::assertSame(
                [Lang::get('pdf.conditions.currency', ['currency' => 'EGP'], $locale)],
                self::termLines($this->html(locale: $locale, terms: [], validUntil: null, taxPercent: null, taxAmount: null)),
                'With no validity date and no tax, the currency is the only line.',
            );
        }
    }

    /**
     * The terms list's items as a reader sees them: tags, entities and the
     * direction isolates gone.
     *
     * @return list<string>
     */
    private static function termLines(string $html): array
    {
        $block = (string) strstr((string) strstr($html, 'class="terms"'), '</ol>', true);
        self::assertNotSame('', $block, 'The page has no numbered terms list.');

        preg_match_all('/<li\b[^>]*>(.*?)<\/li>/su', $block, $matches);

        return array_map(
            static fn (string $line): string => trim((string) preg_replace(
                ['/[\x{2066}-\x{2069}]/u', '/\s+/u'],
                ['', ' '],
                html_entity_decode(strip_tags($line), ENT_QUOTES | ENT_HTML5),
            )),
            $matches[1],
        );
    }

    public function test_that_the_signer_prints_with_the_title_in_the_documents_language_beside_the_sales_signature(): void
    {
        // D-107: Best Regards, the signer's name and job title at the start,
        // a Sales Signature line at the end. The title is the one in the
        // document's language, omitted when that one is blank.
        foreach (['en' => 'Sales Manager', 'ar' => 'مدير المبيعات'] as $locale => $title) {
            $html = $this->html(locale: $locale);

            self::assertMatchesRegularExpression('/id="signatory"[^>]*>\s*Ahmed Essam\s*<.*?id="signatory-title"[^>]*>\s*'.preg_quote($title, '/').'\s*</su', $html, "The {$locale} signer's title is missing.");
            self::assertStringContainsString(e((string) Lang::get('pdf.signature', [], $locale)), $html);
        }

        self::assertStringNotContainsString('id="signatory-title"', $this->html(locale: 'ar', signatoryTitleAr: null), 'The Arabic page fell back to the English title.');
        self::assertStringNotContainsString('Sales Manager', $this->html(locale: 'ar', signatoryTitleAr: null));
    }

    public function test_that_the_footer_carries_the_company_and_the_page_number_on_every_page(): void
    {
        // D-107: "a footer, always in English, on every page": the company
        // name, Address, Tel, Email — text from Settings (§14.6) — and, by the
        // owner's ruling (4), the page number, small. So it is Chrome's footer,
        // drawn in every page's bottom margin, not a block at the end of the
        // document (F-40 · 1.6). English on both pages, the page number too,
        // and the number at the end of the block's last line: a line of its
        // own under the block was clipped by Chrome (the owner, 2026-10-11).
        //
        // Each value is wrapped in a first-strong isolate (U+2068 … U+2069),
        // found on the first real Arabic PDF: bidi printed `5-El-Fath St, …`
        // as `El-Fath St, … Egypt-5` and swapped the two phone numbers.
        $html = $this->app->make(CustomerQuotationHtml::class);

        foreach (['ar', 'en'] as $locale) {
            $footer = $html->footer(CustomerQuotationViewFixture::make(), $locale);

            self::assertStringStartsWith('<div dir="ltr" lang="en"', $footer, "The {$locale} footer is not English.");
            self::assertStringContainsString(e('Smart Technology for Integrated Systems'), $footer);
            foreach (['address', 'tel', 'email'] as $key) {
                self::assertStringContainsString(e((string) Lang::get('pdf.footer.'.$key, [], 'en')), $footer, "The {$locale} footer has no English pdf.footer.{$key}.");
            }
            self::assertMatchesRegularExpression('/id="company-address".*?\x{2068}'.preg_quote(e('5 El-Fath St, Wezarra Station, Boulkly, Alexandria, Egypt'), '/').'\x{2069}/su', $footer);
            self::assertMatchesRegularExpression('/id="company-phones".*?\x{2068}'.preg_quote(e('035829952 · 01070764779'), '/').'\x{2069}/su', $footer);
            self::assertMatchesRegularExpression('/id="company-email".*?info@smarttechegy\.com/su', $footer);

            // The page number after the company lines, in English.
            self::assertMatchesRegularExpression('/id="company-email".*Page <span class="pageNumber"><\/span> of <span class="totalPages"><\/span>/su', $footer, "The {$locale} page number is not an English line under the company.");
            self::assertDoesNotMatchRegularExpression('/\p{Arabic}/u', strip_tags($footer), "Arabic reached the {$locale} footer.");

            // The reference's footer measures (QT-2026-0003-commercial-offer-en.html).
            // `top: 4.6mm` is measured: Chrome anchors the footer to the foot of
            // its band, and that shift puts "Smart" at the reference's 273.2 mm.
            foreach (['border-top: 0.75pt solid #bfbfbf', 'padding-top: 3mm', 'font-size: 8.5pt', 'line-height: 1.6', 'font-size: 9.5pt', 'width: 16mm', 'margin: 0 22mm',
                'width: 100%; position: relative; top: 4.6mm', 'justify-content: space-between; align-items: flex-end'] as $measure) {
                self::assertStringContainsString($measure, $footer, "The {$locale} footer does not take the reference's {$measure}.");
            }
        }

        // The page no longer closes on a block of its own.
        self::assertStringNotContainsString('<footer', $this->html(locale: 'ar'));
        self::assertStringNotContainsString('<footer', $this->html(locale: 'en'));
    }

    public function test_that_each_language_takes_the_reference_htmls_measures(): void
    {
        // D-107: "every size, space and position is that CSS". The values both
        // reference pages share, then the ones the Arabic page changes —
        // `diff` of QT-2026-0003-commercial-offer-{en,ar}.html.
        $shared = ['width: 75mm', 'font-size: 14pt', 'margin-top: 8mm', 'margin: 12mm 0 4mm',
            'border: 0.75pt solid #bfbfbf', 'background: #f2f2f2', 'font-size: 10.5pt', 'margin-top: 6mm',
            'width: 60mm', 'height: 16mm']; // the footer's 8.5pt is `footer()`'s (F-40 · 1.6)
        $own = [
            'en' => ['font-size: 11pt', 'padding: 2.4mm 2mm', 'margin-top: 10.6mm', 'margin: -2.6mm 0 3.7mm', 'padding-top: 11.55mm', 'margin-left: 1.4mm'],
            'ar' => ['font-size: 11.5pt', 'padding: 2.4mm 1.4mm', 'margin-top: 10.9mm', 'margin: -2.9mm 0 0.5mm', 'padding-top: 11.75mm', 'margin-right: 1.27mm'],
        ];

        foreach ($own as $locale => $measures) {
            $css = (string) strstr($this->html(locale: $locale), '</style>', true);
            foreach ([...$shared, ...$measures] as $measure) {
                self::assertStringContainsString($measure, $css, "The {$locale} page does not take the reference's {$measure}.");
            }
        }
    }

    public function test_that_the_side_margins_are_inside_the_page_where_chrome_does_not_clip(): void
    {
        // Reported by the owner on the first PDFs of D-100's layout: the item
        // table's right border was missing, in both languages. Chrome clips the
        // page at the margins the renderer sets, and a full-width table with
        // collapsed borders draws half of its outer border outside its own box.
        // So the side margins are padding inside the page — D-107's 22 mm —
        // and the renderer's left and right margins are 0.
        $template = (string) file_get_contents(base_path(self::TEMPLATE));
        self::assertMatchesRegularExpression('/\bbody\s*\{[^}]*padding:\s*0 22mm/', $template, 'The side margins are not padding inside the page.');

        $renderer = (string) file_get_contents(base_path('app/Modules/Pdf/Infrastructure/BrowsershotPdfRenderer.php'));
        self::assertStringContainsString('->margins(41.7, 0, 27.5, 0)', $renderer, 'The renderer still sets side margins, where Chrome clips.');
    }

    public function test_that_page_one_begins_8_mm_down_and_later_pages_41_7_mm_down(): void
    {
        // D-107 and the reference PDFs: page one's logo 8 mm from the top; a
        // later page's content 33.7 mm below that 8 mm, 41.7 mm down. The
        // renderer's top margin is the later pages', and `@page :first` gives
        // page one its own — measured in a real Chrome PDF on 2026-10-11 (the
        // first page's text 29.6 mm higher than the next pages').
        $template = (string) file_get_contents(base_path(self::TEMPLATE));
        self::assertMatchesRegularExpression('/@page\s*:first\s*\{\s*margin-top:\s*8mm;?\s*\}/', $template, 'Page one does not begin 8 mm down.');

        $renderer = (string) file_get_contents(base_path('app/Modules/Pdf/Infrastructure/BrowsershotPdfRenderer.php'));
        self::assertMatchesRegularExpression('/->margins\(41\.7,/', $renderer, 'Later pages do not begin 41.7 mm down.');
    }

    public function test_that_a_multi_page_document_takes_the_longer_offers_spacing(): void
    {
        // QT-2026-0006-commercial-offer-en.html, the two-page reference: the
        // contact sentence 21 mm under the terms and the signature block 3 mm
        // under it; one page keeps 10.6 / -2.6 mm (10.9 / -2.9 mm in Arabic).
        // The same values in both languages on a longer offer (the owner,
        // 2026-10-11: no Arabic two-page reference exists).
        $html = $this->app->make(CustomerQuotationHtml::class);

        foreach (['ar', 'en'] as $locale) {
            $one = $html->render(CustomerQuotationViewFixture::make(), $locale);
            $multi = $html->render(CustomerQuotationViewFixture::make(), $locale, multiPage: true);

            self::assertMatchesRegularExpression('/<body>/', $one, "The {$locale} one-page document is marked multi-page.");
            self::assertMatchesRegularExpression('/<body class="multi-page">/', $multi, "The {$locale} multi-page document is not marked.");

            foreach (['.multi-page .contact { margin-top: 21mm; }', '.multi-page .closing { margin: 3mm 0 3.7mm; }'] as $rule) {
                self::assertStringContainsString($rule, $multi, "The {$locale} page has no {$rule}");
            }
        }

        // The totals and the signature block are never split (the reference's
        // `break-inside: avoid`), beside the rows D-79 already guards.
        // Read from the rendered CSS: the source's `{{ … }}` sit inside the rules.
        $css = (string) strstr($this->html(), '</style>', true);
        self::assertMatchesRegularExpression('/\.totals\s*\{[^}]*break-inside:\s*avoid/', $css, 'The totals can split across pages.');
        self::assertMatchesRegularExpression('/\.closing\s*\{[^}]*break-inside:\s*avoid/', $css, 'The signature block can split across pages.');
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
        // D-100's second ruling, kept by D-107: no footer band and no faded
        // S.T.I.S mark, so the logo — a PNG — is the one image.
        $html = $this->html();

        self::assertSame(1, substr_count($html, '<img'), 'Something besides the logo is drawn on the page.');
        self::assertStringNotContainsString('data:image/jpeg', $html, 'The footer band or the watermark is still embedded.');
    }

    public function test_that_the_lang_files_hold_d107s_labels_and_nothing_unread(): void
    {
        // The three labels no template ever read (a debt row), and the ones
        // D-100's layout read and D-107's does not, are gone; both languages
        // carry the same keys.
        foreach (['ar', 'en'] as $locale) {
            foreach (['totals.additional', 'totals.tax_base', 'totals.net', 'totals.subtotal', 'parties', 'header', 'table.serial', 'table.item'] as $gone) {
                self::assertFalse(Lang::has('pdf.'.$gone, $locale, false), "pdf.{$gone} is still in the {$locale} lang file.");
            }
        }

        $keys = static function (string $locale): array {
            $flat = [];
            $walk = static function (array $labels, string $prefix) use (&$walk, &$flat): void {
                foreach ($labels as $key => $value) {
                    is_array($value) ? $walk($value, $prefix.$key.'.') : $flat[] = $prefix.$key;
                }
            };
            $walk((array) Lang::get('pdf', [], $locale), '');
            sort($flat);

            return $flat;
        };
        self::assertSame($keys('en'), $keys('ar'), 'The two pdf lang files carry different keys.');
    }

    public function test_that_the_text_is_black(): void
    {
        // Every word black (D-100, kept by D-107). Read from the template's own
        // CSS: a colour is a declaration, and the rendered markup carries the
        // same text.
        $source = (string) file_get_contents(base_path(self::TEMPLATE));

        preg_match_all('/(?<![\w-])color\s*:\s*([^;}\s]+)/i', $source, $colours);
        self::assertNotSame([], $colours[1], 'The template sets no text colour at all.');
        foreach ($colours[1] as $colour) {
            self::assertSame('#000', strtolower($colour), "A text colour of {$colour} is not black.");
        }

        // The page number is text on the page too.
        $html = $this->app->make(CustomerQuotationHtml::class);
        foreach (['ar', 'en'] as $locale) {
            preg_match_all('/(?<![\w-])color\s*:\s*([^;"]+)/i', $html->footer(CustomerQuotationViewFixture::make(), $locale), $footerColours);
            self::assertNotSame([], $footerColours[1], "The {$locale} footer sets no text colour.");
            foreach ($footerColours[1] as $colour) {
                self::assertSame('#000', strtolower(trim($colour)), "A {$locale} footer colour of {$colour} is not black.");
            }
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

        foreach (['QT-2026-0001', 'Vegatrone', '5219.30', '11305.00', '35104.10', '37996.98', 'Formatter M428dw', 'Delivery & Installation'] as $shown) {
            self::assertStringContainsString($shown, $text);
        }
    }

    public function test_that_the_page_number_is_left_to_chrome_and_the_footer_stays_small(): void
    {
        // Point 2.5, and D-79's carried-forward item: the count varies per
        // quotation, so the numbering belongs to Chrome's footerTemplate and
        // not to the document.
        $html = $this->app->make(CustomerQuotationHtml::class);

        foreach (['en', 'ar'] as $locale) {
            $footer = $html->footer(CustomerQuotationViewFixture::make(), $locale);

            self::assertStringContainsString('<span class="pageNumber"></span>', $footer);
            self::assertStringContainsString('<span class="totalPages"></span>', $footer);
            // No embedded face here: Browsershot passes this template as a
            // command argument, and a base64 face makes the command too long
            // for the OS. Inter is the face the rendering image installs.
            self::assertStringNotContainsString('data:font/woff2;base64,', $footer);
            self::assertStringContainsString("font-family: 'Inter'", $footer);
            self::assertLessThan(2000, strlen($footer), 'A footer this large risks Chrome\'s command-line limit.');
        }

        // The label keeps both numbers as placeholders, in both lang files.
        self::assertSame('Page :current of :total', Lang::get('pdf.footer.page', [], 'ar'));
        self::assertSame('Page :current of :total', Lang::get('pdf.footer.page', [], 'en'));
    }

    public function test_that_an_unknown_locale_is_refused_by_both_halves(): void
    {
        $html = $this->app->make(CustomerQuotationHtml::class);

        foreach (['fr', 'en-GB', ''] as $locale) {
            try {
                $html->footer(CustomerQuotationViewFixture::make(), $locale);
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
        // NUMERIC(18,6) — and every cell is matched whole: a substring check
        // finds `110.00` inside `110.000000` and passes the defect.
        // `100.125000`, `1000.005000` and `2000.005000` sit on the half:
        // truncation and half-even would print `.12` / `.00`, half-up prints
        // `.13` / `.01`. `-0.125000` is the negative half: half away from zero
        // prints `-0.13`, a half-up toward +∞ would print `-0.12`.
        foreach (['ar', 'en'] as $locale) {
            $html = $this->html(
                locale: $locale,
                lines: [new CustomerQuotationLine(1, 'Formatter M428dw', '1.0000', '110.000000', '100.125000', 'Pcs', 'قطعة', '1000.005000', '100.125000')],
                additionalItems: [new CustomerAdditionalLine(1, 'Delivery & Installation', '250.000000')],
                totalExcludingVat: '2000.005000',
                discountAmount: '50.000500',
                taxAmount: '133.994999',
                roundingDiff: '-0.125000',
                finalTotal: '695.000000',
            );

            foreach ([
                'unit price' => '110.00',
                'line VAT' => '1000.01',
                'line total' => '100.13',
                'additional item' => '250.00',
                'total excl. VAT' => '2000.01',
                'discount' => '50.00',
                'tax' => '133.99',
                'rounding difference' => '-0.13',
                'final total' => '695.00 EGP',
            ] as $figure => $value) {
                self::assertMatchesRegularExpression('/<td[^>]*>\s*'.preg_quote($value, '/').'\s*<\/td>/', $html, "The {$figure} on the {$locale} page is not at two places, half-up (D-99).");
            }
        }
    }

    public function test_that_quantities_and_percentages_drop_their_trailing_zeros(): void
    {
        // D-99: `1.0000` → `1`, `2.5000` → `2.5`, `14.000` → `14`. Only zeros
        // after a point go: `10.0000` keeps its `10`, and a value with no point
        // is left alone — `20` is not `2`. No additional item, whose quantity
        // `1` would pass for a line's. The lines carry their VAT: a taxed page
        // refuses a line without one rather than printing a zero.
        foreach (['ar', 'en'] as $locale) {
            $html = $this->html(
                locale: $locale,
                lines: [
                    new CustomerQuotationLine(6, 'Formatter M428dw', '1.0000', '110.000000', '110.000000', null, null, '14.630000', '124.630000'),
                    new CustomerQuotationLine(7, 'Toner CF259A', '2.5000', '110.000000', '275.000000', null, null, '36.575000', '311.575000'),
                    new CustomerQuotationLine(8, 'Fuser RM2-5399', '10.0000', '110.000000', '1100.000000', null, null, '146.300000', '1246.300000'),
                    new CustomerQuotationLine(9, 'Drum CF232A', '20', '110.000000', '2200.000000', null, null, '292.600000', '2492.600000'),
                ],
                additionalItems: [],
                taxPercent: '14.000',
            );

            foreach (['1', '2.5', '10', '20'] as $quantity) {
                self::assertMatchesRegularExpression('/<td[^>]*>\s*—\s*<\/td>\s*<td[^>]*>\s*'.preg_quote($quantity, '/').'\s*<\/td>/', $html, "The quantity {$quantity} on the {$locale} page kept its trailing zeros or lost a digit.");
            }

            self::assertStringContainsString(e((string) Lang::get('pdf.totals.tax', ['percent' => '14'], $locale)), $html, "The {$locale} tax percentage kept its trailing zeros.");
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

        $this->html(totalExcludingVat: '1e5');
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
