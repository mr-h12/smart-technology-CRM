<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Modules\Pdf\Application\CustomerQuotationHtml;
use Illuminate\Support\Facades\Lang;
use Tests\Fixtures\CustomerQuotationViewFixture;
use Tests\TestCase;

/**
 * Module 9, Point 2.4 — `D-89`'s layout, rendered from one template in both
 * languages.
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
            deliveryTerms: null,
            warranty: null,
            paymentTerms: null,
        );

        foreach (['subject-line', 'signatory', 'contact-line', 'delivery-terms', 'warranty', 'payment-terms'] as $id) {
            self::assertStringNotContainsString('id="'.$id.'"', $bare, "{$id} was rendered with nothing in it.");
        }

        $full = $this->html();
        foreach (['subject-line', 'signatory', 'contact-line', 'delivery-terms', 'warranty', 'payment-terms'] as $id) {
            self::assertStringContainsString('id="'.$id.'"', $full);
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
