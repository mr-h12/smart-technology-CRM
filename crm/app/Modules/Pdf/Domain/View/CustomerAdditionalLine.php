<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Domain\View;

/**
 * One additional item — §10's "the label the customer sees" — as the PDF shows
 * it: delivery, installation and the like.
 *
 * Never taxed (`D-62`, `OD-01`): delivery and installation sit outside the tax
 * base, which is why {@see CustomerQuotationView::$taxBase} and
 * {@see CustomerQuotationView::$additionalTotal} are separate figures rather
 * than one subtotal.
 *
 * Identical in shape to `QuotationAdditionalLine` minus its `id`, because an
 * additional item carries no cost or supplier to remove — it is a label and an
 * amount. It is restated here rather than reused so that the customer view is
 * one self-contained vocabulary, and so a column added to the internal class
 * later does not silently reach the customer's document.
 */
final readonly class CustomerAdditionalLine
{
    public function __construct(
        public int $lineNo,
        public string $description,
        public string $amount,
    ) {}
}
