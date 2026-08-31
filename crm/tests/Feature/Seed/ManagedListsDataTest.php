<?php

declare(strict_types=1);

namespace Tests\Feature\Seed;

use App\Modules\Admin\Domain\Reference\ListEntry;
use App\Modules\Admin\Domain\Reference\ManagedList;
use App\Modules\Admin\Domain\Reference\ManagedLists;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Point 7.4 — the four lists `DB-05` names, as data.
 *
 * "Enum tables, not hard-coded enums — sectors · units · service types ·
 * delivery terms". The point of a table is that `design/DATABASE.md` can
 * promise "adding a sector must appear in the customer form **without a
 * deployment**", so what is defined here is a *starting set*, not a closed one.
 * §4.2 and §7.3 both write "(extendable)" beside their lists, which is the
 * specification saying the same thing.
 *
 * **Labels are data, in both languages, and that is deliberate.** `CLAUDE.md`
 * forbids hard-coded user-facing strings, and the usual answer is a translation
 * key — but a key cannot work here. A sector added at runtime has no key, and
 * inventing one would put its label back in a file that needs deploying, which
 * is the exact requirement above. So every entry carries `label_en` and
 * `label_ar` as values destined for a column, and the screen renders whatever
 * the row says.
 *
 * The Arabic is not translated here. It is the project's own terminology, read
 * from `arabic/docs/CRM_Documentation.md` — §4.2's sector row and §7.3's
 * catalog table — so that the seeded lists say what the business already says.
 *
 * **Delivery terms is defined and empty, on purpose.** `DB-05` names the list
 * in both languages and neither document gives it a single value; §6.2 carries
 * `delivery` as free text beside payment and warranty. An invented "standard
 * delivery term" would be business content nobody wrote, appearing on customer
 * quotations. Empty is the honest starting state, and the test below says so
 * out loud so it is never read as an omission.
 */
final class ManagedListsDataTest extends TestCase
{
    // ───────────────────────────────────────────── the lists

    /**
     * `DB-05` names four. `companies` is a **fifth, added by the owner's ruling
     * of 2026-08-31** and recorded in `CHECKLIST.md` awaiting a `D-xx`.
     *
     * The reason it is a managed list rather than the free text it was: §7.3
     * groups the catalog "by company/team name" and the owner asked to filter by
     * it too, and a value that is both grouped and filtered cannot be free text
     * without fragmenting — "Acme", "acme" and "Acme Ltd" become three
     * companies on one screen. That is `R-03`'s recorded risk about free-text
     * regions, arriving a second time.
     *
     * This guard is a count assertion and it broke on purpose. It is updated
     * here rather than widened, so the fifth name had to be written down.
     */
    public function test_the_lists_this_system_keeps_are_defined(): void
    {
        self::assertSame(
            ['sectors', 'units', 'service_types', 'delivery_terms', 'companies'],
            array_map(static fn (ManagedList $l): string => $l->value, ManagedList::cases()),
        );
    }

    /** @return array<string, array{0: ManagedList, 1: int}> */
    public static function documentedCounts(): array
    {
        return [
            // §4.2: Government · Medical · Commercial · Industrial · Hotels · Banks
            'six sectors' => [ManagedList::Sectors, 6],
            // §7.3: piece · metre · kilo
            'three units' => [ManagedList::Units, 3],
            // §7.3: installation · repair · maintenance · setup
            'four service types' => [ManagedList::ServiceTypes, 4],
            // DB-05 names it; no document gives it a value.
            'no delivery terms' => [ManagedList::DeliveryTerms, 0],
            // Owner's ruling of 2026-08-31; no document names a single company.
            'no companies' => [ManagedList::Companies, 0],
        ];
    }

    #[DataProvider('documentedCounts')]
    public function test_each_list_holds_exactly_what_the_documents_give_it(ManagedList $list, int $count): void
    {
        self::assertCount($count, ManagedLists::for($list));
    }

    public function test_delivery_terms_is_empty_because_nothing_documents_one(): void
    {
        // Not an oversight, and this assertion is the difference between the
        // two. DB-05 names the list; §6.2 keeps `delivery` as free text beside
        // payment (D-26) and warranty; no value appears in either language.
        // The business supplies these, the way it supplies FX rates.
        self::assertSame([], ManagedLists::for(ManagedList::DeliveryTerms));
    }

    /**
     * Empty for `delivery_terms`' reason, not for a new one: **only the business
     * knows its own companies.** Seeding a plausible-sounding "Acme" here would
     * be business content nobody wrote, and it would then be grouped under and
     * filtered by on a real screen.
     *
     * ⚠️ The consequence is deliberate and belongs to Point 5.2, not here:
     * once `company` becomes `required`, an empty list means **no catalog item
     * can be created until the Super Admin adds a company** — `POST
     * /managed-lists/{list}` carries `admin.system_settings`. Recorded in
     * `CHECKLIST.md`; the owner has been asked.
     */
    public function test_companies_is_empty_because_only_the_business_knows_them(): void
    {
        self::assertSame([], ManagedLists::for(ManagedList::Companies));
    }

    public function test_the_lists_that_do_have_entries_are_not_empty(): void
    {
        // Otherwise the count assertions above pass on a registry that returns
        // nothing for everything.
        foreach ([ManagedList::Sectors, ManagedList::Units, ManagedList::ServiceTypes] as $list) {
            self::assertNotSame([], ManagedLists::for($list), "{$list->value} is empty.");
        }
    }

    // ──────────────────────────────────────────── the entries themselves

    /** @return array<string, array{0: ManagedList, 1: list<string>}> */
    public static function documentedCodes(): array
    {
        return [
            'sectors, §4.2 in order' => [
                ManagedList::Sectors,
                ['government', 'medical', 'commercial', 'industrial', 'hotels', 'banks'],
            ],
            'units, §7.3 in order' => [
                ManagedList::Units,
                ['piece', 'metre', 'kilo'],
            ],
            'service types, §7.3 in order' => [
                ManagedList::ServiceTypes,
                ['installation', 'repair', 'maintenance', 'setup'],
            ],
        ];
    }

    /** @param list<string> $codes */
    #[DataProvider('documentedCodes')]
    public function test_the_codes_and_their_order_follow_the_document(ManagedList $list, array $codes): void
    {
        self::assertSame(
            $codes,
            array_map(static fn (ListEntry $e): string => $e->code(), ManagedLists::for($list)),
        );
    }

    public function test_every_code_is_machine_shaped_and_unique_within_its_list(): void
    {
        $checked = 0;

        foreach (ManagedList::cases() as $list) {
            $codes = array_map(static fn (ListEntry $e): string => $e->code(), ManagedLists::for($list));

            self::assertSame(array_unique($codes), $codes, "{$list->value} repeats a code.");

            foreach ($codes as $code) {
                self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/D', $code,
                    "'{$code}' is a label, not a stable machine code.");
                $checked++;
            }
        }

        self::assertSame(13, $checked, 'The registry is not the size the documents describe.');
    }

    public function test_every_entry_carries_both_languages(): void
    {
        // Module 0 supports Arabic and English from the first release. A list
        // that ships one of them is a screen that falls back to the other
        // without saying so.
        foreach (ManagedList::cases() as $list) {
            foreach (ManagedLists::for($list) as $entry) {
                self::assertNotSame('', trim($entry->labelEn()), "{$entry->code()} has no English label.");
                self::assertNotSame('', trim($entry->labelAr()), "{$entry->code()} has no Arabic label.");
            }
        }
    }

    public function test_the_arabic_label_is_actually_arabic(): void
    {
        // The failure this catches is not a missing label but a copied one:
        // an English string left in the Arabic column reads as filled in, and
        // an Arabic screen then shows "Government" beside five Arabic sectors.
        foreach (ManagedList::cases() as $list) {
            foreach (ManagedLists::for($list) as $entry) {
                self::assertMatchesRegularExpression('/\p{Arabic}/u', $entry->labelAr(),
                    "{$entry->code()}'s Arabic label contains no Arabic: '{$entry->labelAr()}'.");
                self::assertDoesNotMatchRegularExpression('/[A-Za-z]/', $entry->labelAr(),
                    "{$entry->code()}'s Arabic label still carries Latin text: '{$entry->labelAr()}'.");
            }
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function documentedLabels(): array
    {
        // English from docs/CRM_Documentation_EN.md §4.2 and §7.3; Arabic from
        // arabic/docs/CRM_Documentation.md, the same two tables.
        return [
            'government' => ['government', 'Government', 'حكومي'],
            'medical' => ['medical', 'Medical', 'طبي'],
            'commercial' => ['commercial', 'Commercial', 'تجاري'],
            'industrial' => ['industrial', 'Industrial', 'صناعي'],
            'hotels' => ['hotels', 'Hotels', 'فنادق'],
            'banks' => ['banks', 'Banks', 'بنوك'],
            'piece' => ['piece', 'Piece', 'قطعة'],
            'metre' => ['metre', 'Metre', 'متر'],
            'kilo' => ['kilo', 'Kilo', 'كيلو'],
            'installation' => ['installation', 'Installation', 'تركيب'],
            'repair' => ['repair', 'Repair', 'إصلاح'],
            'maintenance' => ['maintenance', 'Maintenance', 'صيانة'],
            'setup' => ['setup', 'Setup', 'تجهيز'],
        ];
    }

    #[DataProvider('documentedLabels')]
    public function test_each_label_pair_matches_its_source(string $code, string $en, string $ar): void
    {
        $entry = ManagedLists::find($code);

        self::assertNotNull($entry, "{$code} is not in any list.");
        self::assertSame($en, $entry->labelEn());
        self::assertSame($ar, $entry->labelAr());
    }

    // ────────────────────────────────────────────────── shape and order

    public function test_positions_are_contiguous_from_one(): void
    {
        // The order a screen shows them in is data too — otherwise it is
        // whatever the database returns, which is whatever the planner chose.
        foreach (ManagedList::cases() as $list) {
            $positions = array_map(static fn (ListEntry $e): int => $e->position(), ManagedLists::for($list));

            // range(1, 0) is [1, 0] in PHP, not [] — measured, when delivery
            // terms failed this. An empty list numbers nothing.
            $expected = $positions === [] ? [] : range(1, count($positions));

            self::assertSame($expected, $positions,
                "{$list->value} does not number its entries 1..n.");
        }
    }

    public function test_a_code_is_unique_across_every_list(): void
    {
        // find() answers by code alone, which is only meaningful if no two
        // lists share one.
        $all = [];

        foreach (ManagedList::cases() as $list) {
            foreach (ManagedLists::for($list) as $entry) {
                $all[] = $entry->code();
            }
        }

        self::assertSame(array_unique($all), $all);
    }

    public function test_the_registry_is_stable_between_reads(): void
    {
        self::assertEquals(ManagedLists::for(ManagedList::Sectors), ManagedLists::for(ManagedList::Sectors));
    }

    public function test_an_unknown_code_is_not_found(): void
    {
        self::assertNull(ManagedLists::find('aerospace'));
    }
}
