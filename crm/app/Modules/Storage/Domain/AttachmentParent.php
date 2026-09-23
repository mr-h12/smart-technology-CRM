<?php

declare(strict_types=1);

namespace App\Modules\Storage\Domain;

/**
 * The entities a file may hang from.
 *
 * §17 puts `{entity_type}` in the storage path; D-71 keeps it out of the
 * database, where a pivot table per parent carries the relationship with a real
 * foreign key. Both facts are spelled here so they cannot drift apart: the path
 * segment and the pivot table are one declaration, and adding a fifth parent
 * means adding a case, a pivot migration, and nothing else.
 */
enum AttachmentParent: string
{
    case Deal = 'deal';
    case SupplierQuotation = 'supplier_quotation';
    case PurchaseOrder = 'purchase_order';
    case Report = 'report';
    // Module 9, Point 1.3 — the customer PDF stored against its quotation
    // (§14.6). The fifth parent, and the first added after Module 0.
    case Quotation = 'quotation';

    /** The D-71 pivot that records this parent's attachments. */
    public function pivotTable(): string
    {
        return $this->value.'_files';
    }
}
