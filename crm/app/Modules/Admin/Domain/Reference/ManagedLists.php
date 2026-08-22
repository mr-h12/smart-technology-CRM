<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Reference;

/**
 * What each of `DB-05`'s four lists starts with.
 *
 * A **starting set, not a closed one**. §4.2 and §7.3 both write "(extendable)"
 * beside their lists, and `design/DATABASE.md` turns that into an acceptance
 * criterion: adding a sector must reach the customer form without a deployment.
 * Nothing below is a constraint on what the table may hold later.
 *
 * English labels come from `docs/CRM_Documentation_EN.md` §4.2 and §7.3.
 * Arabic labels come from the same two tables in
 * `arabic/docs/CRM_Documentation.md` — the project's own terminology, read
 * rather than translated, so the seeded lists say what the business says.
 *
 * The English source writes sectors capitalised and the catalog rows in
 * lower-case prose ("piece · metre · kilo"); both are rendered here as display
 * labels. The machine code carries the lower-case form, and it is the code —
 * never a label — that a foreign key points at.
 */
final class ManagedLists
{
    /** @return list<ListEntry> */
    public static function for(ManagedList $list): array
    {
        return match ($list) {
            // §4.2: "Reference list: Government · Medical · Commercial ·
            // Industrial · Hotels · Banks (extendable)".
            ManagedList::Sectors => [
                new ListEntry('government', 'Government', 'حكومي', 1),
                new ListEntry('medical', 'Medical', 'طبي', 2),
                new ListEntry('commercial', 'Commercial', 'تجاري', 3),
                new ListEntry('industrial', 'Industrial', 'صناعي', 4),
                new ListEntry('hotels', 'Hotels', 'فنادق', 5),
                new ListEntry('banks', 'Banks', 'بنوك', 6),
            ],

            // §7.3: "Unit (piece · metre · kilo · extendable)".
            ManagedList::Units => [
                new ListEntry('piece', 'Piece', 'قطعة', 1),
                new ListEntry('metre', 'Metre', 'متر', 2),
                new ListEntry('kilo', 'Kilo', 'كيلو', 3),
            ],

            // §7.3: "Service type (installation · repair · maintenance ·
            // setup · extendable)".
            ManagedList::ServiceTypes => [
                new ListEntry('installation', 'Installation', 'تركيب', 1),
                new ListEntry('repair', 'Repair', 'إصلاح', 2),
                new ListEntry('maintenance', 'Maintenance', 'صيانة', 3),
                new ListEntry('setup', 'Setup', 'تجهيز', 4),
            ],

            // Empty on purpose, and the one entry in this file that needs a
            // reason rather than a citation. `DB-05` names delivery terms as an
            // enum table in both languages and **neither document gives it a
            // single value**; §6.2 keeps `delivery` as free text on the
            // quotation, beside payment terms (`D-26`) and warranty.
            //
            // A plausible-sounding default here would be business content
            // nobody wrote, printed on customer quotations under the company's
            // name. The list exists so Module 2 has somewhere to put the real
            // ones; until the business supplies them, empty is the accurate
            // answer. Same rule as the exchange rates in Point 7.3.
            ManagedList::DeliveryTerms => [],
        };
    }

    /** @return array<string, list<ListEntry>> keyed by ManagedList::value */
    public static function all(): array
    {
        $lists = [];

        foreach (ManagedList::cases() as $list) {
            $lists[$list->value] = self::for($list);
        }

        return $lists;
    }

    /** Codes are unique across every list, so one is enough to find an entry. */
    public static function find(string $code): ?ListEntry
    {
        foreach (ManagedList::cases() as $list) {
            foreach (self::for($list) as $entry) {
                if ($entry->code() === $code) {
                    return $entry;
                }
            }
        }

        return null;
    }
}
