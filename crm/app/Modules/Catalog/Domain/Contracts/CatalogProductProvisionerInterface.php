<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Contracts;

/**
 * `D-22` — "New products are **added to the catalog automatically, without
 * review**" — as the one thing another module is allowed to ask of Catalog.
 *
 * ── Why this interface exists at all ───────────────────────────────────────
 *
 * The product is added to *the catalog*, so the rule belongs to Catalog. The
 * module that discovers a missing product is Module 6: §7.2's offer carries the
 * line that names it. `deptrac.modules.yaml` grants `SupplierQuotations` three
 * layers — `Framework`, `SharedContracts`, `AuditContract` — and Catalog is not
 * among them, which is `CLAUDE.md`'s "cross-module work goes through interfaces
 * or domain events, never another module's models" made mechanical.
 *
 * ── A name in, an id out, and nothing else ─────────────────────────────────
 *
 * The layer that exposes this class to other modules is a `classLike` collector
 * on **this one interface**, not on Catalog's `Domain` directory — `Precision`'s
 * precedent rather than `AuditContract`'s. That is only possible while the
 * signature is primitives: a `CatalogItemSummary` here would drag Catalog's
 * `Domain\Listing` across the boundary with it, and the narrow collector would
 * report that dependency uncovered. The caller needs an id for a foreign key,
 * so an id is all it gets.
 *
 * ── What the implementation owes ───────────────────────────────────────────
 *
 * `AUD-01` names create explicitly and `D-45` makes the audit record the stated
 * mitigation for opening the catalog to every employee, so the create is
 * expected to run through Catalog's audited writer rather than beside it. No
 * permission is checked here: `D-45` opens catalog creation to any employee,
 * and the caller has already passed its own route's grant.
 */
interface CatalogProductProvisionerInterface
{
    /**
     * The id of the product called `$name`, adding it to the catalog if no
     * live product carries that name.
     *
     * The match is exact but case-insensitive, and it is a convention rather
     * than a constraint: `catalog_items.name` is nullable with no unique index.
     * `D-22` says a new product is added without review and is silent on
     * whether a name already present is the same product; the owner ruled on
     * 2026-09-03 that it is reused.
     *
     * `$actorId` is `DB-02`'s `created_by` for the row this may write.
     */
    public function productIdFor(string $name, string $actorId): string;
}
