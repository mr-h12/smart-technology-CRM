<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Domain\Contracts;

use App\Modules\Suppliers\Domain\Importing\ImportSummary;
use App\Modules\Suppliers\Domain\Listing\SupplierListCriteria;
use App\Modules\Suppliers\Domain\Listing\SupplierPage;
use App\Modules\Suppliers\Domain\Listing\SupplierSummary;
use App\Modules\Suppliers\Domain\Writing\SupplierDraft;

/**
 * The `suppliers` table as §8's Suppliers screen needs to read it.
 *
 * ── No scope parameter, and that is the documented difference from Module 3 ─
 *
 * `CustomerDirectoryInterface` takes a `CustomerRowScope` on every method, and
 * says why: `SEC-08` is row-level security, so a reader callable without a
 * scope is one somebody will call without a scope. That reasoning does not
 * transfer, because §3.7 has no ownership to scope by — its two columns are
 * "All operational roles" and CEO, and the seeded matrix gives every one of
 * them `Scope::All`.
 *
 * So the absence of a scope here is a **transcription of §3.7**, not an
 * omission. Adding a `SupplierRowScope` that resolved to "everything" for every
 * caller would be a speculative abstraction with one implementation, and it
 * would suggest a row-level rule that the permission matrix does not contain.
 * If §3.7 ever gains a scope, this interface changes and every caller is forced
 * to say what it holds — which is the failure mode worth having.
 */
interface SupplierDirectoryInterface
{
    public function list(SupplierListCriteria $criteria): SupplierPage;

    /** Null when the row is absent or soft-deleted. */
    public function find(string $supplierId): ?SupplierSummary;

    /** Point 2.2. */
    public function create(SupplierDraft $draft, string $actorId): SupplierSummary;

    /** Null on the same case as {@see find()} — absent or soft-deleted. */
    public function update(string $supplierId, SupplierDraft $draft, string $actorId): ?SupplierSummary;

    /**
     * `D-85` (F-09 · 1.4) — one `supplier_import_batches` row for a finished
     * import. On the directory rather than a port of its own: it is the same
     * module's persistence, and a second interface would have one method.
     */
    public function recordImportBatch(
        string $originalFilename,
        int $rowCount,
        int $importedCount,
        int $incompleteCount,
        string $actorId,
    ): ImportSummary;
}
