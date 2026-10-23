<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Modules\Catalog\Domain\Contracts\CatalogItemLabelsInterface;
use App\Modules\Customers\Domain\Contracts\CustomerContactsInterface;
use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Pdf\Domain\Contracts\LineDescriptionsInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * F-40 · 1.4 — the three facts `D-107`'s Commercial Offer reads from other
 * modules, against the real database: each line's unit by its `units` code
 * (QS.2a: the line owns its unit, `D-155`; both languages), the signer's job
 * title (`D-107` ruling 2, F-40 · 1.3's columns), and the customer's contact
 * person with `D-104`'s English title label.
 */
final class CustomerDocumentFactsTest extends TestCase
{
    use RefreshDatabase;

    // ── Catalog: the unit, by its code (QS.2a) ──────────────────────────────

    /** Catalog's seam and the PDF's, which forwards to it: the same answer. */
    private const UNIT_SEAMS = [CatalogItemLabelsInterface::class, LineDescriptionsInterface::class];

    /** QS.2a: a line names its unit by its `units` code (`D-155`); a code the list never held has no entry, so the PDF prints `—`. */
    public function test_a_unit_code_carries_its_labels_and_an_unlisted_code_has_no_entry(): void
    {
        $this->unit('carton', 'Carton', 'كرتونة');

        foreach (self::UNIT_SEAMS as $seam) {
            self::assertSame(
                ['carton' => ['en' => 'Carton', 'ar' => 'كرتونة']],
                $this->app->make($seam)->unitsOf(['carton', 'not_on_the_list']),
                "{$seam} does not name a unit by its code.",
            );
        }
    }

    /** An archived unit still names a line that chose it; a live entry of the same code wins. */
    public function test_an_archived_unit_still_names_its_code_and_the_live_entry_wins(): void
    {
        $this->unit('crate', 'Old Crate', 'صندوق قديم', archived: true);
        $this->unit('carton', 'Old Carton', 'كرتونة قديمة', archived: true);
        $this->unit('carton', 'Carton', 'كرتونة');

        foreach (self::UNIT_SEAMS as $seam) {
            self::assertSame(
                self::byKey(['crate' => ['en' => 'Old Crate', 'ar' => 'صندوق قديم'], 'carton' => ['en' => 'Carton', 'ar' => 'كرتونة']]),
                self::byKey($this->app->make($seam)->unitsOf(['crate', 'carton'])),
                "{$seam} does not prefer the live entry, or forgets an archived one.",
            );
        }
    }

    public function test_no_unit_codes_is_no_query(): void
    {
        DB::enableQueryLog();

        foreach (self::UNIT_SEAMS as $seam) {
            self::assertSame([], $this->app->make($seam)->unitsOf([]));
        }
        self::assertCount(0, DB::getQueryLog());
    }

    // ── Identity: the signer's job title ────────────────────────────────────

    public function test_a_users_job_titles_cross_and_an_untitled_user_has_nulls(): void
    {
        $titled = User::factory()->create(['job_title_en' => 'Sales Manager', 'job_title_ar' => 'مدير المبيعات']);
        $untitled = User::factory()->create();

        self::assertSame(
            self::byKey([
                $titled->id => ['en' => 'Sales Manager', 'ar' => 'مدير المبيعات'],
                $untitled->id => ['en' => null, 'ar' => null],
            ]),
            self::byKey($this->app->make(UserFactsInterface::class)->jobTitlesOf([$titled->id, $untitled->id])),
        );
    }

    /** The same rule `namesOf()` keeps: §3.1's hidden account is not named on paper. */
    public function test_a_hidden_users_job_title_has_no_entry(): void
    {
        $hidden = User::factory()->create(['is_hidden' => true, 'job_title_en' => 'Owner']);

        self::assertSame([], $this->app->make(UserFactsInterface::class)->jobTitlesOf([$hidden->id]));
    }

    // ── Customers: the contact person ───────────────────────────────────────

    public function test_a_contact_with_a_title_carries_its_english_label(): void
    {
        $id = $this->customer(['contact_person' => 'Mona Adel', 'contact_title' => 'mrs']);

        self::assertSame(
            ['name' => 'Mona Adel', 'title_en' => 'Mrs.'],
            $this->app->make(CustomerContactsInterface::class)->contactOf($id),
        );
    }

    /** `D-104`'s live label wins over an archived entry of the same code. */
    public function test_a_contact_title_reads_the_live_label_first(): void
    {
        DB::table('enum_lists')->where('list', 'contact_titles')->where('code', 'mr')->update(['deleted_at' => now()]);
        $this->insertEnum('contact_titles', 'mr', 'Mister', 'سيد');
        DB::table('enum_lists')->where('list', 'contact_titles')->where('code', 'mr')->whereNotNull('deleted_at')
            ->update(['label_en' => 'Old Mr.']);

        $id = $this->customer(['contact_person' => 'Omar Said', 'contact_title' => 'mr']);

        self::assertSame(
            ['name' => 'Omar Said', 'title_en' => 'Mister'],
            $this->app->make(CustomerContactsInterface::class)->contactOf($id),
        );
    }

    public function test_a_contact_without_a_title_has_none(): void
    {
        $id = $this->customer(['contact_person' => 'Omar Said']);

        self::assertSame(
            ['name' => 'Omar Said', 'title_en' => null],
            $this->app->make(CustomerContactsInterface::class)->contactOf($id),
        );
    }

    /** `D-89`: *Att.* is omitted when the contact is blank — and for an unknown customer. */
    public function test_a_blank_contact_and_an_unknown_customer_have_none(): void
    {
        $contacts = $this->app->make(CustomerContactsInterface::class);

        self::assertNull($contacts->contactOf($this->customer(['contact_person' => '   ', 'contact_title' => 'mr'])));
        self::assertNull($contacts->contactOf($this->customer()));
        self::assertNull($contacts->contactOf(Uuid::uuid4()->toString()));
    }

    /**
     * A read with no ORDER BY answers in no fixed order; the map is compared, not its order.
     *
     * @template T
     *
     * @param  array<string, T>  $map
     * @return array<string, T>
     */
    private static function byKey(array $map): array
    {
        ksort($map);

        return $map;
    }

    private function unit(string $code, string $en, string $ar, bool $archived = false): void
    {
        $this->insertEnum('units', $code, $en, $ar, $archived);
    }

    private function insertEnum(string $list, string $code, string $en, string $ar, bool $archived = false): void
    {
        DB::table('enum_lists')->insert([
            'id' => Uuid::uuid4()->toString(), 'list' => $list, 'code' => $code,
            'label_en' => $en, 'label_ar' => $ar, 'position' => 1,
            'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $archived ? now() : null,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function customer(array $overrides = []): string
    {
        $id = Uuid::uuid4()->toString();

        DB::table('customers')->insert([
            'id' => $id, 'name' => 'Customer '.$id,
            'created_at' => now(), 'updated_at' => now(),
            ...$overrides,
        ]);

        return $id;
    }
}
