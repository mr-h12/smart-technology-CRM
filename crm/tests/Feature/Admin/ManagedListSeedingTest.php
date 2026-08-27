<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Modules\Admin\Domain\Contracts\ManagedListRepositoryInterface;
use App\Modules\Admin\Domain\Reference\ManagedList;
use Database\Seeders\ManagedListSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Module 2, Point 2.2 — `DB-05`'s lists as rows, and the domain reading them.
 *
 * **The members are read out of the documentation, not out of `ManagedLists`.**
 * §4.2 publishes the sectors and §7.3 the units and service types; comparing
 * the seeder against the class the seeder loads would be self-consistent and
 * blind to both drifting from the document together — defect #4 on this
 * project's list.
 *
 * **The acceptance criterion is the point of the module:** *a new sector added
 * in settings appears in the customer form **without a deployment***. Half of
 * it is provable here — an entry that reaches the table is returned by the
 * repository with no code change, and survives the next run of the seeder. The
 * other half, the customer form itself, is Module 3.
 *
 * **`delivery_terms` is seeded empty on purpose.** `DB-05` names the list and
 * **no document gives it a single value**; `ManagedLists` records the reasoning.
 * Inventing four plausible terms here would print business content nobody wrote
 * onto customer quotations, so the assertion below pins the emptiness rather
 * than treating it as an oversight to be fixed later.
 */
final class ManagedListSeedingTest extends TestCase
{
    use RefreshDatabase;

    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    /**
     * The `·`-separated members the documentation publishes on the line that
     * carries $marker, minus its "(extendable)" tail.
     *
     * @return list<string>
     */
    private function documented(string $marker): array
    {
        self::assertFileExists(
            self::MASTER_DOCUMENTATION,
            'The master documentation is not mounted. This fails rather than skips: the members '
            .'below would otherwise be compared against the class that produced them.',
        );

        foreach (file(self::MASTER_DOCUMENTATION) ?: [] as $line) {
            if (! str_contains($line, $marker)) {
                continue;
            }

            $after = mb_substr($line, mb_strpos($line, $marker) + mb_strlen($marker));

            // End the list where its parenthesis closes, or the rest of the
            // table row joins in: §7.3's units are followed by another cell.
            $close = mb_strpos($after, ')');

            if ($close !== false) {
                $after = mb_substr($after, 0, $close);
            }

            // The two shapes the document actually uses, found by reading the
            // failure rather than by guessing: §7.3 writes `Unit (piece · metre
            // · kilo · extendable) | Active service (yes/no) |`, so the row
            // continues past the list; §4.2 writes `... · Banks (extendable) |`,
            // where the tail hangs off the last member instead. Cutting each
            // member at its own `(` handles both, and `extendable` is the
            // document saying the list is open — not a member of it.
            $members = [];
            foreach (explode('·', $after) as $member) {
                $member = mb_substr($member, 0, mb_strpos($member, '(') ?: null);
                $member = mb_strtolower(trim($member, " \t|:)\n"));

                if ($member !== '' && $member !== 'extendable') {
                    $members[] = $member;
                }
            }

            self::assertNotSame([], $members, "Nothing parsed out of the line carrying '{$marker}'.");

            return $members;
        }

        self::fail("The documentation no longer carries '{$marker}'.");
    }

    /** @return list<string> */
    private function seededCodes(ManagedList $list): array
    {
        // Narrowed with a real assertion rather than a cast: `pluck()` is
        // typed as a collection of mixed, and a cast would hide a column that
        // came back as something other than a string.
        $codes = [];

        foreach (DB::table('enum_lists')->where('list', $list->value)->orderBy('position')->get() as $row) {
            self::assertIsString($row->code);

            $codes[] = $row->code;
        }

        return $codes;
    }

    // ──────────────────────────────────────────────── what the document says

    /** §4.2: "Reference list: Government · Medical · Commercial · Industrial · Hotels · Banks". */
    public function test_that_the_sectors_are_the_ones_section_4_2_publishes(): void
    {
        $this->seed(ManagedListSeeder::class);

        self::assertSame(
            $this->documented('Reference list:'),
            array_map(static fn (string $c): string => str_replace('_', ' ', $c), $this->seededCodes(ManagedList::Sectors)),
        );
    }

    /** §7.3: "Unit (piece · metre · kilo · extendable)". */
    public function test_that_the_units_are_the_ones_section_7_3_publishes(): void
    {
        $this->seed(ManagedListSeeder::class);

        self::assertSame($this->documented('Unit ('), $this->seededCodes(ManagedList::Units));
    }

    /** §7.3: "Service type (installation · repair · maintenance · setup · extendable)". */
    public function test_that_the_service_types_are_the_ones_section_7_3_publishes(): void
    {
        $this->seed(ManagedListSeeder::class);

        self::assertSame($this->documented('Service type ('), $this->seededCodes(ManagedList::ServiceTypes));
    }

    /** No document gives delivery terms a value. Empty is the accurate answer. */
    public function test_that_delivery_terms_is_seeded_empty_and_that_is_deliberate(): void
    {
        $this->seed(ManagedListSeeder::class);

        self::assertSame([], $this->seededCodes(ManagedList::DeliveryTerms));
    }

    /** §14.2: an entry without Arabic is an English word on an Arabic screen. */
    public function test_that_every_seeded_entry_carries_both_labels(): void
    {
        $this->seed(ManagedListSeeder::class);

        self::assertSame(
            0,
            DB::table('enum_lists')->where('label_ar', '')->orWhere('label_en', '')->count(),
            'A seeded entry has a blank label.',
        );
    }

    // ───────────────────────────────────────────────────── the seeder contract

    public function test_that_a_second_run_changes_nothing(): void
    {
        $this->seed(ManagedListSeeder::class);
        $first = DB::table('enum_lists')->orderBy('id')->get(['id', 'created_at'])->toArray();

        $this->seed(ManagedListSeeder::class);
        $second = DB::table('enum_lists')->orderBy('id')->get(['id', 'created_at'])->toArray();

        self::assertEquals($first, $second, 'The second run re-created rows rather than leaving them alone.');
        self::assertSame(13, DB::table('enum_lists')->count());
    }

    /** `IdempotentSeeder`: a second run may not overwrite a deliberate edit. */
    public function test_that_a_second_run_keeps_an_administrators_edit(): void
    {
        $this->seed(ManagedListSeeder::class);

        DB::table('enum_lists')
            ->where('list', 'sectors')->where('code', 'banks')
            ->update(['label_en' => 'Banking', 'label_ar' => 'مصارف']);

        $this->seed(ManagedListSeeder::class);

        self::assertSame(
            'Banking',
            DB::scalar("select label_en from enum_lists where list = 'sectors' and code = 'banks'"),
        );
    }

    /** `DEV-08` names sectors and units beside roles: production runs on them. */
    public function test_that_the_seeder_is_not_test_data(): void
    {
        self::assertFalse(app(ManagedListSeeder::class)->seedsTestData());
    }

    // ────────────────────────────────────── the acceptance criterion, half of it

    /**
     * *"New sector added in settings → appears in the customer form without a
     * deployment"*. The row arrives; nothing is rebuilt; the domain returns it.
     */
    public function test_that_a_sector_added_after_seeding_is_returned_without_a_code_change(): void
    {
        $this->seed(ManagedListSeeder::class);

        DB::table('enum_lists')->insert([
            'id' => Uuid::uuid7()->toString(),
            'list' => 'sectors',
            'code' => 'education',
            'label_en' => 'Education',
            'label_ar' => 'تعليم',
            'position' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $codes = array_map(
            static fn ($entry): string => $entry->code(),
            app(ManagedListRepositoryInterface::class)->entriesFor(ManagedList::Sectors),
        );

        self::assertContains('education', $codes);
    }

    /** And it must survive the next deployment's seeder run. */
    public function test_that_a_sector_added_after_seeding_survives_the_next_run(): void
    {
        $this->seed(ManagedListSeeder::class);

        DB::table('enum_lists')->insert([
            'id' => Uuid::uuid7()->toString(),
            'list' => 'sectors',
            'code' => 'education',
            'label_en' => 'Education',
            'label_ar' => 'تعليم',
            'position' => 7,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seed(ManagedListSeeder::class);

        self::assertSame(1, DB::table('enum_lists')->where('code', 'education')->count());
    }

    // ─────────────────────────────────────────────────────────── the read path

    public function test_that_the_repository_returns_entries_in_display_order(): void
    {
        $this->seed(ManagedListSeeder::class);

        $positions = array_map(
            static fn ($entry): int => $entry->position(),
            app(ManagedListRepositoryInterface::class)->entriesFor(ManagedList::Sectors),
        );

        $sorted = $positions;
        sort($sorted);

        self::assertSame($sorted, $positions);
        self::assertSame([1, 2, 3, 4, 5, 6], $positions);
    }

    /** `AP-08`: a repository answering from the class would not see an edit. */
    public function test_that_the_repository_reads_the_table_and_not_the_class(): void
    {
        $this->seed(ManagedListSeeder::class);

        DB::table('enum_lists')
            ->where('list', 'units')->where('code', 'kilo')
            ->update(['label_ar' => 'كجم']);

        $labels = [];
        foreach (app(ManagedListRepositoryInterface::class)->entriesFor(ManagedList::Units) as $entry) {
            $labels[$entry->code()] = $entry->labelAr();
        }

        self::assertSame('كجم', $labels['kilo']);
    }

    /** `D-34`: archived means absent from every list that offers a choice. */
    public function test_that_the_repository_skips_archived_entries(): void
    {
        $this->seed(ManagedListSeeder::class);

        DB::table('enum_lists')->where('list', 'units')->where('code', 'kilo')->update(['deleted_at' => now()]);

        $codes = array_map(
            static fn ($entry): string => $entry->code(),
            app(ManagedListRepositoryInterface::class)->entriesFor(ManagedList::Units),
        );

        self::assertNotContains('kilo', $codes);
        self::assertCount(2, $codes);
    }
}
