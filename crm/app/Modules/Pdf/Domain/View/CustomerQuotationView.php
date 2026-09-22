<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Domain\View;

use InvalidArgumentException;

/**
 * Everything the customer quotation PDF may show, and nothing else.
 *
 * Module 9's acceptance criteria ask for two things this class answers
 * together: *"no supplier name or price anywhere in the PDF"* and *"rendering
 * consumes a customer-view model that **structurally cannot** contain
 * supplier, cost, or margin fields."* The second is how the first is kept.
 *
 * ── Why a second model rather than `QuotationDetail` ───────────────────────
 *
 * `QuotationDetail` is Module 7's read model and the only legitimate way into
 * a quotation, but it carries `defaultMargin`, and each of its
 * `QuotationLine`s carries `unitCost`, `unitCostCurrency`,
 * `unitCostFxRateAtTime`, `unitCostBase`, `marginPercent`, `lineCost` and
 * `supplierQuotationItemId`. So the only model this module is allowed to read
 * holds **every category of field the customer's document may never show**.
 *
 * Handing that to a template and trusting the template not to print it is the
 * arrangement §3.12 rule 2 exists to prevent: it makes a leak a one-line
 * mistake in a file nobody diffs closely. Mapping it into this class first
 * makes the leak impossible to write — there is no property to print.
 * `CustomerQuotationViewMapper` (Point 1.2) is the single place the two
 * vocabularies meet.
 *
 * ── Why the percentages are fields ────────────────────────────────────────
 *
 * `discountPercent` and `taxPercent` are here because `P-01`'s template
 * hard-codes them into its labels — `Discount 5%`, `14% VAT`, and their Arabic
 * pair. Correct for a prototype with fixed sample data; wrong for a document
 * whose rates are per-quotation and configurable, and against Module 0's rule
 * that no user-facing string is hard-coded. Step 2 interpolates these instead.
 *
 * ── Why `deliveryTerms` is absent rather than blank ───────────────────────
 *
 * §6.2 gives a quotation a "show delivery terms in PDF (yes/no)" flag, and the
 * criterion is that a `false` omits **the section**. A nullable field whose
 * empty case is `''` would let a template render a heading over an empty block
 * and still satisfy every test. So the mapper passes `null` when the flag is
 * off, and this constructor refuses a string that is present but blank: the
 * field is either genuinely there or genuinely absent, with no third state for
 * a template to get wrong.
 *
 * ── Why `companyPhones` is one string ─────────────────────────────────────
 *
 * §13 screen 4 stores the company's phone numbers as one free-text setting
 * (`SystemSetting::CompanyPhones`), the way §4.2 stores a customer's. Splitting
 * it here would mean guessing at separators the Super Admin never agreed to,
 * so the view carries it as stored and the template prints it as typed.
 *
 * ── What is deliberately not here ─────────────────────────────────────────
 *
 * No `unitCost*`, `marginPercent`, `lineCost`, `defaultMargin`,
 * `supplierQuotationItemId` or any supplier identity — removed, not hidden.
 * No `status`, `versionToken`, `createdBy`, `updatedBy`, `isSelfApproved` or
 * `rejectionReason` either: those are internal workflow, and a quotation's
 * approval history is not the customer's business. `code` is the document's
 * identity, the way PO #226 prints its number.
 */
final readonly class CustomerQuotationView
{
    /**
     * @param  list<CustomerQuotationLine>  $lines
     * @param  list<CustomerAdditionalLine>  $additionalItems
     */
    public function __construct(
        public string $code,
        public ?string $quotationDate,
        public ?string $validUntil,
        public string $currencyCode,
        public string $customerName,
        public ?string $customerContact,
        public string $companyName,
        public ?string $companyAddress,
        public ?string $companyPhones,
        public array $lines,
        public array $additionalItems,
        public string $subtotal,
        public string $additionalTotal,
        public string $discountPercent,
        public string $discountAmount,
        public string $taxBase,
        public ?string $taxPercent,
        public ?string $taxAmount,
        public string $netAmount,
        public string $finalTotal,
        public string $roundingDiff,
        public ?string $paymentTerms,
        public ?string $warranty,
        public ?string $deliveryTerms,
    ) {
        // Present-but-blank is the state the `show_delivery_terms` criterion
        // cannot survive, so it is refused here rather than left for a
        // template to handle. The same applies to the other optional prose:
        // a section with a heading and no body is a rendering defect whether
        // the flag caused it or a mapper did.
        self::refuseBlank('deliveryTerms', $deliveryTerms);
        self::refuseBlank('paymentTerms', $paymentTerms);
        self::refuseBlank('warranty', $warranty);
        self::refuseBlank('customerContact', $customerContact);
        self::refuseBlank('companyAddress', $companyAddress);
        self::refuseBlank('companyPhones', $companyPhones);
    }

    private static function refuseBlank(string $field, ?string $value): void
    {
        if ($value !== null && trim($value) === '') {
            throw new InvalidArgumentException(
                "{$field} must be absent (null) rather than blank: a section the customer PDF "
                .'omits has no value, and one it shows has content.'
            );
        }
    }
}
