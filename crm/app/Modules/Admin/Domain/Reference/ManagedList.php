<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Reference;

/**
 * The four lists `DB-05` names — "Enum tables, not hard-coded enums — sectors ·
 * units · service types · delivery terms" — and a fifth the owner added.
 *
 * The irony of an enum here is worth answering directly. What `DB-05` forbids
 * is the **membership** being code — a sector that cannot be added without a
 * deployment. The set of *lists* is a different thing: it is fixed by the
 * columns that reference them (`customers.sector`, a catalog item's unit,
 * service type and company).
 *
 * ── The fifth case, and why it needed no migration ─────────────────────────
 *
 * An earlier version of this comment said "adding a fifth list means adding a
 * column, which is a migration either way". `companies` narrows that:
 * **the pointing column already existed.** `catalog_items.company` has been a
 * `string(255)` since Point 1.2, filled as free text, so making it a managed
 * list changes where its *values* come from, not where they are stored.
 *
 * ⚠️ It is still not free of a migration, and this case was added believing it
 * was. `create_enum_lists` CHECKs `list` against four **literals**, so the
 * first `companies` row answered `SQLSTATE[23514]`.
 * `2026_08_31_020000_extend_enum_lists_with_companies` is the correcting
 * migration. A fifth list costs one CHECK; it is the *column* that this one
 * did not need.
 *
 * Owner's ruling, 2026-08-31, recorded in `CHECKLIST.md` awaiting a `D-xx`:
 * §7.3 groups the catalog "by company/team name" and the owner asked to filter
 * by it as well. A value that is both grouped and filtered cannot stay free
 * text without fragmenting — "Acme", "acme" and "Acme Ltd" become three
 * companies on one screen, which is `R-03`'s recorded free-text risk arriving a
 * second time.
 */
enum ManagedList: string
{
    /** §4.2's `sector` row — a reference list on every customer. */
    case Sectors = 'sectors';

    /** §7.3's `Unit` field on a catalog product. */
    case Units = 'units';

    /** §7.3's `Service type` field on a catalog service. */
    case ServiceTypes = 'service_types';

    /** Named by `DB-05`; no document gives it a value. See `ManagedLists`. */
    case DeliveryTerms = 'delivery_terms';

    /**
     * §7.3's "Providing team / company", and what the catalog is grouped by.
     * Owner's ruling of 2026-08-31; `DB-05` does not name it. Seeded empty for
     * `DeliveryTerms`' reason — only the business knows its own companies.
     */
    case Companies = 'companies';
}
