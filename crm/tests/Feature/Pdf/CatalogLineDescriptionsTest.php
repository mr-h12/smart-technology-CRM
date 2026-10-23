<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Modules\Catalog\Domain\Contracts\CatalogItemLabelsInterface;
use App\Modules\Pdf\Domain\Contracts\LineDescriptionsInterface;
use App\Modules\Pdf\Infrastructure\CatalogLineDescriptions;
use ArrayObject;
use Tests\TestCase;

/**
 * Module 9, Point 3.2 — `LineDescriptionsInterface` answered from the catalog.
 *
 * QS.1 · `D-155`: a quotation line names its own catalog item, so the id going
 * in is a catalog item's and nothing of a supplier is read on the way to its
 * label and unit (§3.12 rule 2, §14.6).
 */
final class CatalogLineDescriptionsTest extends TestCase
{
    private const CATALOG_A = '0199a000-0000-7000-8000-00000000ca01';

    private const CATALOG_B = '0199a000-0000-7000-8000-00000000cb02';

    public function test_that_each_catalog_item_is_described_by_its_label(): void
    {
        $descriptions = new CatalogLineDescriptions(
            self::labels([self::CATALOG_A => 'Centrifugal pump 5 HP', self::CATALOG_B => 'Installation']),
        );

        self::assertSame(
            [self::CATALOG_A => 'Centrifugal pump 5 HP', self::CATALOG_B => 'Installation'],
            $descriptions->descriptionsOf([self::CATALOG_A, self::CATALOG_B]),
        );
    }

    public function test_that_the_catalog_is_asked_once_for_the_whole_quotation(): void
    {
        /** @var ArrayObject<int, list<string>> $asked */
        $asked = new ArrayObject;

        (new CatalogLineDescriptions(
            self::labels([self::CATALOG_A => 'Centrifugal pump 5 HP'], $asked),
        ))->descriptionsOf([self::CATALOG_A, self::CATALOG_B]);

        self::assertSame([[self::CATALOG_A, self::CATALOG_B]], $asked->getArrayCopy());
    }

    public function test_that_the_application_answers_line_descriptions_from_the_catalog(): void
    {
        // 1.2 left the port unbound on purpose; Step 3's endpoint resolves the
        // mapper, so from here a missing binding is a container error.
        self::assertInstanceOf(CatalogLineDescriptions::class, $this->app->make(LineDescriptionsInterface::class));
    }

    /**
     * Answers from `$labels`, appending every list it is asked for to `$asked`.
     *
     * @param  array<string, string>  $labels
     * @param  ArrayObject<int, list<string>>|null  $asked
     */
    private static function labels(array $labels, ?ArrayObject $asked = null): CatalogItemLabelsInterface
    {
        return new class($labels, $asked ?? new ArrayObject) implements CatalogItemLabelsInterface
        {
            /**
             * @param  array<string, string>  $labels
             * @param  ArrayObject<int, list<string>>  $asked
             */
            public function __construct(private readonly array $labels, private readonly ArrayObject $asked) {}

            /** QS.2a: units are by code, proved against the database in `CustomerDocumentFactsTest`. */
            public function unitsOf(array $unitCodes): array
            {
                return [];
            }

            public function labelsOf(array $catalogItemIds): array
            {
                $this->asked->append($catalogItemIds);

                return array_intersect_key($this->labels, array_flip($catalogItemIds));
            }
        };
    }
}
