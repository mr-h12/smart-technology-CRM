<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Reference;

/**
 * The four lists `DB-05` names: "Enum tables, not hard-coded enums — sectors ·
 * units · service types · delivery terms".
 *
 * The irony of an enum here is worth answering directly. What `DB-05` forbids
 * is the **membership** being code — a sector that cannot be added without a
 * deployment. The set of *lists* is a different thing: it is fixed by the
 * columns that reference them (`customers.sector_id`, a catalog item's unit and
 * service type), and adding a fifth list means adding a column, which is a
 * migration either way.
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
}
