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
 * ── Why optional prose is absent rather than blank ────────────────────────
 *
 * A nullable field whose empty case is `''` would let a template render a
 * heading over nothing and still satisfy every test. So the mapper passes
 * `null`, and this constructor refuses a string that is present but blank: the
 * field is either genuinely there or genuinely absent, with no third state for
 * a template to get wrong. The terms keep the same rule one level down — a
 * term with no body is not in the list (`D-103`, {@see CustomerTerm}).
 *
 * ── Why `companyPhones` is one string ─────────────────────────────────────
 *
 * §13 screen 4 stores the company's phone numbers as one free-text setting
 * (`SystemSetting::CompanyPhones`), the way §4.2 stores a customer's. Splitting
 * it here would mean guessing at separators the Super Admin never agreed to,
 * so the view carries it as stored and the template prints it as typed.
 *
 * ── `subject` and `signatoryName` ─────────────────────────────────────────
 *
 * `D-89`'s header carries a Subject and its closing one signatory. Neither
 * lives on the quotation: the subject is the deal's title and the signatory is
 * whoever created the quotation, so both are absent when unknown — a deal need
 * not be titled, and Identity does not name the hidden Super Admin.
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
     * @param  list<CustomerTerm>  $terms  D-103, in the employee's order
     */
    public function __construct(
        public string $code,
        public ?string $quotationDate,
        public ?string $validUntil,
        public string $currencyCode,
        public string $customerName,
        public ?string $customerContact,
        /** F-40 · 1.4: `D-104`'s English title label; the Arabic page prints «أ.». */
        public ?string $customerContactTitle,
        public string $companyName,
        public ?string $companyAddress,
        public ?string $companyPhones,
        /** F-40 · 1.2's `company.email`, for `D-107`'s footer. */
        public ?string $companyEmail,
        public ?string $subject,
        public ?string $signatoryName,
        /** `D-107` ruling (2), F-40 · 1.3's columns. */
        public ?string $signatoryTitleEn,
        public ?string $signatoryTitleAr,
        public array $lines,
        public array $additionalItems,
        public string $subtotal,
        public string $additionalTotal,
        /** `D-107`: *Total Amount Excl. VAT* = subtotal + additional total. */
        public string $totalExcludingVat,
        public string $discountPercent,
        public string $discountAmount,
        public string $taxBase,
        public ?string $taxPercent,
        public ?string $taxAmount,
        public string $netAmount,
        public string $finalTotal,
        public string $roundingDiff,
        public array $terms,
    ) {
        // A line with a label and nothing after it is a rendering defect, so
        // present-but-blank is refused here rather than left to the template.
        self::refuseBlank('customerContact', $customerContact);
        self::refuseBlank('companyAddress', $companyAddress);
        self::refuseBlank('companyPhones', $companyPhones);
        self::refuseBlank('subject', $subject);
        self::refuseBlank('signatoryName', $signatoryName);
        self::refuseBlank('customerContactTitle', $customerContactTitle);
        self::refuseBlank('companyEmail', $companyEmail);
        self::refuseBlank('signatoryTitleEn', $signatoryTitleEn);
        self::refuseBlank('signatoryTitleAr', $signatoryTitleAr);
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
