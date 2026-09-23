<?php

declare(strict_types=1);

namespace App\Modules\Deals\Domain\Contracts;

/**
 * A deal's title, for another module that has to name what a document is
 * about — Module 9's customer PDF prints it as `D-89`'s **Subject** line.
 *
 * ── Why not a sixth method on `DealFactsInterface` ────────────────────────
 *
 * Module 6's tests implement that interface as an anonymous fake. A method
 * added there leaves those fakes abstract, which is a module of the other
 * developer's broken by a change in this one — the exact crossing the
 * per-module rule forbids. A separate contract costs one file and breaks
 * nothing, and `D-90`'s `DealOutcomeInterface` set that precedent.
 */
interface DealTitlesInterface
{
    /**
     * Deal id => title, for one document's worth of ids. A deal with no title
     * (`deals.title` is nullable — §4.3 never made it mandatory) and a `DB-01`
     * soft-deleted deal each have **no entry**, so the caller omits the line
     * rather than printing a blank one. The empty list answers `[]` without a
     * query.
     *
     * @param  list<string>  $dealIds
     * @return array<string, string>
     */
    public function titlesOf(array $dealIds): array;
}
