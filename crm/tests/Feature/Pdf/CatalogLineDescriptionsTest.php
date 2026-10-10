<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Modules\Catalog\Domain\Contracts\CatalogItemLabelsInterface;
use App\Modules\Pdf\Domain\Contracts\LineDescriptionsInterface;
use App\Modules\Pdf\Infrastructure\CatalogLineDescriptions;
use App\Modules\SupplierQuotations\Domain\Contracts\SupplierItemPricingInterface;
use App\Modules\SupplierQuotations\Domain\Pricing\SupplierItemPrice;
use ArrayObject;
use Tests\TestCase;

/**
 * Module 9, Point 3.2 — `LineDescriptionsInterface` answered from the catalog.
 *
 * The id going in is a supplier-quotation item's, and `SupplierItemPrice` — the
 * only published way from that id to its catalog item — also carries the
 * supplier's price. So the headline test gives the supplier line a price, a
 * currency and quantities nobody would type by accident, and asserts that none
 * of them comes back out (§3.12 rule 2, §14.6).
 */
final class CatalogLineDescriptionsTest extends TestCase
{
    private const LINE_A = '0199a000-0000-7000-8000-0000000051a1';

    private const LINE_B = '0199a000-0000-7000-8000-0000000051b2';

    private const CATALOG_A = '0199a000-0000-7000-8000-00000000ca01';

    private const CATALOG_B = '0199a000-0000-7000-8000-00000000cb02';

    public function test_that_each_supplier_line_is_described_by_its_catalog_items_label(): void
    {
        $descriptions = new CatalogLineDescriptions(
            self::prices([self::LINE_A => self::price(self::CATALOG_A), self::LINE_B => self::price(self::CATALOG_B)]),
            self::labels([self::CATALOG_A => 'Centrifugal pump 5 HP', self::CATALOG_B => 'Installation']),
        );

        self::assertSame(
            [self::LINE_A => 'Centrifugal pump 5 HP', self::LINE_B => 'Installation'],
            $descriptions->descriptionsOf([self::LINE_A, self::LINE_B]),
        );
    }

    /** F-40 · 1.4 — `D-107`: each line's unit is its catalog item's, in both languages; none, no entry. */
    public function test_that_each_line_takes_its_catalog_items_unit_in_both_languages(): void
    {
        $descriptions = new CatalogLineDescriptions(
            self::prices([self::LINE_A => self::price(self::CATALOG_A), self::LINE_B => self::price(self::CATALOG_B)]),
            self::labels([], null, [self::CATALOG_A => ['en' => 'Piece', 'ar' => 'قطعة']]),
        );

        self::assertSame(
            [self::LINE_A => ['en' => 'Piece', 'ar' => 'قطعة']],
            $descriptions->unitsOf([self::LINE_A, self::LINE_B]),
        );
    }

    public function test_that_nothing_of_the_suppliers_line_but_the_catalog_label_leaves(): void
    {
        $supplierLine = new SupplierItemPrice(
            unitPrice: '98765.432100',
            currencyId: '0199a000-0000-7000-8000-0000000cc0de',
            consumedQuantity: '4321.0000',
            availableQuantity: '1234.0000',
            catalogItemId: self::CATALOG_A,
        );

        $answer = json_encode(
            (new CatalogLineDescriptions(
                self::prices([self::LINE_A => $supplierLine]),
                self::labels([self::CATALOG_A => 'Centrifugal pump 5 HP']),
            ))->descriptionsOf([self::LINE_A]),
            JSON_THROW_ON_ERROR,
        );

        foreach (['98765.432100', '98765.4321', '0199a000-0000-7000-8000-0000000cc0de', '4321.0000', '1234.0000', self::CATALOG_A] as $leak) {
            self::assertStringNotContainsString($leak, $answer);
        }
    }

    public function test_that_an_archived_supplier_line_has_no_entry(): void
    {
        // `priceFor()` answers null for a soft-deleted offer's line. No entry
        // is what makes the mapper refuse the document instead of printing a
        // blank description.
        $descriptions = new CatalogLineDescriptions(
            self::prices([self::LINE_A => self::price(self::CATALOG_A), self::LINE_B => null]),
            self::labels([self::CATALOG_A => 'Centrifugal pump 5 HP']),
        );

        self::assertSame([self::LINE_A => 'Centrifugal pump 5 HP'], $descriptions->descriptionsOf([self::LINE_A, self::LINE_B]));
    }

    public function test_that_a_catalog_item_the_catalog_cannot_name_has_no_entry(): void
    {
        $descriptions = new CatalogLineDescriptions(
            self::prices([self::LINE_A => self::price(self::CATALOG_A), self::LINE_B => self::price(self::CATALOG_B)]),
            self::labels([self::CATALOG_A => 'Centrifugal pump 5 HP']),
        );

        self::assertSame([self::LINE_A => 'Centrifugal pump 5 HP'], $descriptions->descriptionsOf([self::LINE_A, self::LINE_B]));
    }

    public function test_that_the_catalog_is_asked_once_for_the_whole_quotation(): void
    {
        /** @var ArrayObject<int, list<string>> $asked */
        $asked = new ArrayObject;

        // Two supplier lines of one catalog item: one read, one id in it.
        (new CatalogLineDescriptions(
            self::prices([self::LINE_A => self::price(self::CATALOG_A), self::LINE_B => self::price(self::CATALOG_A)]),
            self::labels([self::CATALOG_A => 'Centrifugal pump 5 HP'], $asked),
        ))->descriptionsOf([self::LINE_A, self::LINE_B]);

        self::assertSame([[self::CATALOG_A]], $asked->getArrayCopy());
    }

    public function test_that_the_application_answers_line_descriptions_from_the_catalog(): void
    {
        // 1.2 left the port unbound on purpose; Step 3's endpoint resolves the
        // mapper, so from here a missing binding is a container error.
        self::assertInstanceOf(CatalogLineDescriptions::class, $this->app->make(LineDescriptionsInterface::class));
    }

    private static function price(string $catalogItemId): SupplierItemPrice
    {
        return new SupplierItemPrice('100.000000', null, '0.0000', '1.0000', $catalogItemId);
    }

    /**
     * @param  array<string, SupplierItemPrice|null>  $byLine
     */
    private static function prices(array $byLine): SupplierItemPricingInterface
    {
        return new class($byLine) implements SupplierItemPricingInterface
        {
            /**
             * @param  array<string, SupplierItemPrice|null>  $byLine
             */
            public function __construct(private readonly array $byLine) {}

            public function priceFor(string $supplierQuotationItemId): ?SupplierItemPrice
            {
                return $this->byLine[$supplierQuotationItemId] ?? null;
            }
        };
    }

    /**
     * Answers from `$labels`, appending every list it is asked for to `$asked`.
     *
     * @param  array<string, string>  $labels
     * @param  ArrayObject<int, list<string>>|null  $asked
     * @param  array<string, array{en: string, ar: string}>  $units
     */
    private static function labels(array $labels, ?ArrayObject $asked = null, array $units = []): CatalogItemLabelsInterface
    {
        return new class($labels, $asked ?? new ArrayObject, $units) implements CatalogItemLabelsInterface
        {
            /**
             * @param  array<string, string>  $labels
             * @param  ArrayObject<int, list<string>>  $asked
             * @param  array<string, array{en: string, ar: string}>  $units
             */
            public function __construct(private readonly array $labels, private readonly ArrayObject $asked, private readonly array $units) {}

            public function unitsOf(array $catalogItemIds): array
            {
                return array_intersect_key($this->units, array_flip($catalogItemIds));
            }

            public function labelsOf(array $catalogItemIds): array
            {
                $this->asked->append($catalogItemIds);

                return array_intersect_key($this->labels, array_flip($catalogItemIds));
            }
        };
    }
}
