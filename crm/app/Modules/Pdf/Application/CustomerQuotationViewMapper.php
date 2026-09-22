<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Application;

use App\Modules\Admin\Domain\Contracts\SettingsRepositoryInterface;
use App\Modules\Admin\Domain\Settings\SystemSetting;
use App\Modules\Customers\Domain\Contracts\CustomerNamesInterface;
use App\Modules\Pdf\Domain\Contracts\LineDescriptionsInterface;
use App\Modules\Pdf\Domain\View\CustomerAdditionalLine;
use App\Modules\Pdf\Domain\View\CustomerQuotationLine;
use App\Modules\Pdf\Domain\View\CustomerQuotationView;
use App\Modules\Pdf\Domain\View\CustomerViewIncomplete;
use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Listing\QuotationAdditionalLine;
use App\Modules\Quotations\Domain\Listing\QuotationDetail;
use App\Modules\Quotations\Domain\Listing\QuotationLine;
use App\Modules\Quotations\Domain\Listing\QuotationNotFound;

/**
 * `QuotationDetail` → `CustomerQuotationView` — Module 9 Point 1.2, and the
 * only place Module 7's vocabulary and the customer's meet.
 *
 * ── What crosses, field by field ──────────────────────────────────────────
 *
 * Everything below is **copied by name, never spread**: no spreading a line's
 * row array, no loop over properties. A field Module 7 adds to `QuotationDetail` or
 * `QuotationLine` later therefore does not reach the customer until someone
 * writes it into this class by hand — the reason 1.1's view is a separate
 * vocabulary in the first place.
 *
 * - **From the quotation** (`QuotationDirectoryInterface`, never an Eloquent
 *   model of Module 7's): code, dates, currency code, the money chain §5 fixed
 *   when it was priced, the two percentages the labels need, and the prose.
 *   `defaultMargin`, `status`, the approval marks and every line's cost fields
 *   and `supplierQuotationItemId` stay behind.
 * - **From Settings** (`§13` screen 4, `§14.6`): company name, address, phones.
 *   Not hard-coded — the document is the company's, and the Super Admin owns
 *   what it says. No logo: Settings does not store one yet (`SystemSetting`'s
 *   own docblock), and that is Step 2's question, not this mapper's.
 * - **From Customers** (`CustomerNamesInterface`, `D-83`): the customer's name
 *   and nothing else of the customer.
 * - **From `LineDescriptionsInterface`**: what each line is. The supplier-quotation
 *   item id is *used* to ask, and is not carried into the view.
 *
 * ── Blank is absent ───────────────────────────────────────────────────────
 *
 * 1.1's view refuses present-but-blank prose so a template cannot print a
 * heading over nothing. This is where blank becomes absent: whitespace-only
 * terms, warranty, address or phones map to `null`, and delivery terms map to
 * `null` whenever `show_delivery_terms` is false, whatever they contain.
 *
 * ── Refused, not guessed ──────────────────────────────────────────────────
 *
 * No company name, a customer the lookup cannot name, or a line nothing
 * describes each throw `CustomerViewIncomplete` naming the gap. The API may
 * fall back to a customer id on a list row (`CustomerNamesInterface`'s own
 * contract); a document sent to that customer may not.
 *
 * `customerContact` is `null` for now: `QuotationDetail` carries no contact,
 * and choosing which of a customer's contacts the PDF addresses is a decision
 * nobody has made. Recorded against the point in `CHECKLIST.md`.
 */
final readonly class CustomerQuotationViewMapper
{
    public function __construct(
        private QuotationDirectoryInterface $quotations,
        private SettingsRepositoryInterface $settings,
        private CustomerNamesInterface $customerNames,
        private LineDescriptionsInterface $lineDescriptions,
    ) {}

    /**
     * @throws QuotationNotFound when the quotation does not exist
     * @throws CustomerViewIncomplete when a fact the customer's document needs is missing
     */
    public function forQuotation(string $quotationId): CustomerQuotationView
    {
        $quotation = $this->quotations->find($quotationId) ?? throw QuotationNotFound::of($quotationId);

        return $this->map($quotation);
    }

    /**
     * @throws CustomerViewIncomplete when a fact the customer's document needs is missing
     */
    public function map(QuotationDetail $quotation): CustomerQuotationView
    {
        $settings = $this->settings->all();
        $companyName = self::present($settings[SystemSetting::CompanyName->value] ?? null)
            ?? throw CustomerViewIncomplete::companyName($quotation->id);

        $customerName = self::present($this->customerNames->namesOf([$quotation->customerId])[$quotation->customerId] ?? null)
            ?? throw CustomerViewIncomplete::customerName($quotation->id, $quotation->customerId);

        return new CustomerQuotationView(
            code: $quotation->code,
            quotationDate: $quotation->quotationDate,
            validUntil: $quotation->validUntil,
            currencyCode: $quotation->currency,
            customerName: $customerName,
            customerContact: null,
            companyName: $companyName,
            companyAddress: self::present($settings[SystemSetting::CompanyAddress->value] ?? null),
            companyPhones: self::present($settings[SystemSetting::CompanyPhones->value] ?? null),
            lines: $this->lines($quotation),
            additionalItems: array_map(
                static fn (QuotationAdditionalLine $line): CustomerAdditionalLine => new CustomerAdditionalLine(
                    lineNo: $line->lineNo,
                    description: $line->description,
                    amount: $line->amount,
                ),
                $quotation->additionalItems,
            ),
            subtotal: $quotation->subtotal,
            additionalTotal: $quotation->additionalTotal,
            discountPercent: $quotation->discountPercent,
            discountAmount: $quotation->discountAmount,
            taxBase: $quotation->taxBase,
            taxPercent: $quotation->taxPercent,
            taxAmount: $quotation->taxAmount,
            netAmount: $quotation->netAmount,
            finalTotal: $quotation->finalTotal,
            roundingDiff: $quotation->roundingDiff,
            paymentTerms: self::present($quotation->paymentTerms),
            warranty: self::present($quotation->warranty),
            deliveryTerms: $quotation->showDeliveryTerms ? self::present($quotation->deliveryTerms) : null,
        );
    }

    /**
     * @return list<CustomerQuotationLine>
     */
    private function lines(QuotationDetail $quotation): array
    {
        $descriptions = $this->lineDescriptions->descriptionsOf(array_values(array_unique(array_map(
            static fn (QuotationLine $line): string => $line->supplierQuotationItemId,
            $quotation->items,
        ))));

        $lines = [];
        $undescribed = [];

        foreach ($quotation->items as $line) {
            $description = self::present($descriptions[$line->supplierQuotationItemId] ?? null);

            if ($description === null) {
                $undescribed[] = $line->lineNo;

                continue;
            }

            $lines[] = new CustomerQuotationLine(
                lineNo: $line->lineNo,
                description: $description,
                quantity: $line->quantity,
                unitPrice: $line->unitPrice,
                lineTotal: $line->lineTotal,
            );
        }

        if ($undescribed !== []) {
            throw CustomerViewIncomplete::lineDescriptions($quotation->id, $undescribed);
        }

        return $lines;
    }

    private static function present(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
