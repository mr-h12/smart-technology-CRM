<?php

declare(strict_types=1);

namespace App\Modules\Pdf\Domain\View;

use RuntimeException;

/**
 * A quotation whose customer view cannot be completed from what the system
 * knows — no company name in Settings, a customer the lookup cannot name, a
 * line nothing describes.
 *
 * Refused rather than filled in: a PDF with a blank company, a customer
 * printed as a UUID, or an unlabelled line is a document the employee would
 * send without noticing. The reason names the missing fact so that Step 3's
 * failure record says what to fix, not merely that generation failed.
 */
final class CustomerViewIncomplete extends RuntimeException
{
    private function __construct(public readonly string $quotationId, public readonly string $reason)
    {
        parent::__construct("Quotation {$quotationId} cannot be rendered for the customer: {$reason}.");
    }

    public static function companyName(string $quotationId): self
    {
        return new self($quotationId, 'the company name is not set (§13 screen 4)');
    }

    public static function customerName(string $quotationId, string $customerId): self
    {
        return new self($quotationId, "customer {$customerId} has no name to print");
    }

    /**
     * @param  list<int>  $lineNumbers
     */
    public static function lineDescriptions(string $quotationId, array $lineNumbers): self
    {
        return new self($quotationId, 'no description for line(s) '.implode(', ', $lineNumbers));
    }
}
