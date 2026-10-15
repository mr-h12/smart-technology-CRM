<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Contracts;

use App\Modules\Customers\Domain\Listing\CustomerListCriteria;
use App\Modules\Customers\Domain\Listing\CustomerPage;
use App\Modules\Customers\Domain\Notes\CustomerNote;

/**
 * `customer_notes` (`D-122`). No scope here: the caller reaches the customer
 * through {@see CustomerDirectoryInterface::find()} first, and every method is
 * keyed by that customer, so a note of another customer answers as absent.
 */
interface CustomerNotesInterface
{
    /**
     * Newest `note_date` first, undated last, newest added first; live notes only.
     *
     * @return CustomerPage<CustomerNote>
     */
    public function list(string $customerId, CustomerListCriteria $criteria): CustomerPage;

    public function find(string $customerId, string $noteId): ?CustomerNote;

    /** @param  string|null  $actorId  null when no person acts (never today; the J-15 shape) */
    public function add(string $customerId, string $body, ?string $noteDate, ?string $actorId): CustomerNote;

    public function update(string $customerId, string $noteId, string $body, ?string $noteDate, string $actorId): ?CustomerNote;

    /** Soft-deletes (`DB-01`); false when the note is absent, removed, or another customer's. */
    public function remove(string $customerId, string $noteId, string $actorId): bool;
}
