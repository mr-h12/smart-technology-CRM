<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Modules\Admin\Domain\Contracts\SettingsRepositoryInterface;
use App\Modules\Admin\Domain\Settings\SystemSetting;
use App\Modules\Customers\Domain\Contracts\CustomerNamesInterface;
use App\Modules\Pdf\Application\CustomerQuotationViewMapper;
use App\Modules\Pdf\Domain\Contracts\LineDescriptionsInterface;
use App\Modules\Pdf\Domain\View\CustomerViewIncomplete;
use App\Modules\Quotations\Domain\Contracts\QuotationReaderInterface;
use App\Modules\Quotations\Domain\Listing\QuotationAdditionalLine;
use App\Modules\Quotations\Domain\Listing\QuotationDetail;
use App\Modules\Quotations\Domain\Listing\QuotationLine;
use App\Modules\Quotations\Domain\Listing\QuotationNotFound;
use ArrayObject;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Module 9, Point 1.2 — `QuotationDetail` → `CustomerQuotationView`.
 *
 * Pure, so it extends PHPUnit's own `TestCase` for the reason
 * `QuotationTotalsTest` gives: every collaborator is an interface, and each is
 * answered here by a fake that records what it was asked.
 *
 * The headline test is the acceptance criterion *"no supplier name or price
 * anywhere in the PDF"*, checked at the model: a three-supplier quotation is
 * mapped, serialised, and searched for every value that identifies a supplier
 * or reveals a cost. 1.1 proved the view has no field to hold them; this proves
 * the mapper does not smuggle them into a field that exists.
 */
final class CustomerQuotationViewMapperTest extends TestCase
{
    private const CUSTOMER_ID = '0199a000-0000-7000-8000-00000000c001';

    /**
     * Three supplier-quotation items, one per supplier, each with a cost chain
     * no customer-visible figure in {@see self::quotation()} shares.
     *
     * @var array<string, array{unitCost: string, unitCostBase: string, lineCost: string, marginPercent: string}>
     */
    private const SUPPLIER_ITEMS = [
        '0199a000-0000-7000-8000-00000000a001' => ['unitCost' => '4111.17', 'unitCostBase' => '4111.1700', 'lineCost' => '8222.34', 'marginPercent' => '17.25'],
        '0199a000-0000-7000-8000-00000000a002' => ['unitCost' => '4222.28', 'unitCostBase' => '4222.2800', 'lineCost' => '12666.84', 'marginPercent' => '18.35'],
        '0199a000-0000-7000-8000-00000000a003' => ['unitCost' => '4333.39', 'unitCostBase' => '4333.3900', 'lineCost' => '4333.39', 'marginPercent' => '19.45'],
    ];

    private const DEFAULT_MARGIN = '21.75';

    private const FX_RATE = '48.7310';

    public function test_that_a_three_supplier_quotation_leaves_no_supplier_or_cost_value_in_the_view(): void
    {
        $view = $this->mapper()->map(self::quotation());

        // The twelve values the point names — three supplier-item ids, and each
        // line's unit cost, base-currency unit cost and line cost — plus the
        // margins and the rate, which reveal the same thing from the other side.
        // Sixteen distinct, not seventeen: line 3 is quantity 1, so its line
        // cost is its unit cost.
        $leaks = [self::DEFAULT_MARGIN, self::FX_RATE];
        foreach (self::SUPPLIER_ITEMS as $id => $costs) {
            $leaks = [$id, ...array_values($costs), ...$leaks];
        }
        $leaks = array_values(array_unique($leaks));
        self::assertCount(16, $leaks, 'The fixture must hold sixteen distinct values to search for.');

        $json = json_encode($view, JSON_THROW_ON_ERROR);

        foreach ($leaks as $value) {
            self::assertStringNotContainsString(
                $value,
                $json,
                "\"{$value}\" belongs to a supplier or a cost and reached the customer view. §3.12 rule 2 and "
                .'§14.6 exclude it from the PDF unconditionally.',
            );
        }

        foreach (['cost', 'margin', 'supplier'] as $word) {
            self::assertStringNotContainsStringIgnoringCase("{$word}", implode(' ', self::keysOf(json_decode($json, true))));
        }

        // And the customer's own figures did cross, so the search above is not
        // passing over an empty document.
        self::assertCount(3, $view->lines);
        self::assertSame(['5219.30', '6332.50', '5418.00'], array_map(static fn ($line): string => $line->unitPrice, $view->lines));
        self::assertSame('Formatter M428dw', $view->lines[0]->description);
    }

    public function test_that_the_quotation_is_read_through_the_directory(): void
    {
        $view = $this->mapper()->forQuotation('0199a000-0000-7000-8000-000000009001');

        self::assertSame('QT-2026-0001', $view->code);
        self::assertSame('EGP', $view->currencyCode);
        self::assertSame('Vegatrone', $view->customerName);
    }

    public function test_that_an_unknown_quotation_is_not_found(): void
    {
        $this->expectException(QuotationNotFound::class);

        $this->mapper()->forQuotation('0199a000-0000-7000-8000-00000000dead');
    }

    public function test_that_company_identity_comes_from_settings(): void
    {
        $view = $this->mapper(settings: [
            SystemSetting::CompanyName->value => 'Smart Technology for Integrated Systems',
            SystemSetting::CompanyAddress->value => '   ',
            SystemSetting::CompanyPhones->value => '035829952 · 01070764779',
        ])->map(self::quotation());

        self::assertSame('Smart Technology for Integrated Systems', $view->companyName);
        self::assertNull($view->companyAddress, 'A blank setting is an absent one: the view refuses blank.');
        self::assertSame('035829952 · 01070764779', $view->companyPhones);
    }

    public function test_that_a_missing_company_name_is_refused(): void
    {
        $this->expectException(CustomerViewIncomplete::class);
        $this->expectExceptionMessageMatches('/company name is not set/');

        $this->mapper(settings: [SystemSetting::CompanyName->value => null])->map(self::quotation());
    }

    public function test_that_a_customer_without_a_name_is_refused_rather_than_printed_as_an_id(): void
    {
        $this->expectException(CustomerViewIncomplete::class);
        $this->expectExceptionMessageMatches('/customer '.self::CUSTOMER_ID.' has no name/');

        $this->mapper(customerNames: [])->map(self::quotation());
    }

    public function test_that_an_undescribed_line_is_refused_naming_the_line(): void
    {
        $descriptions = self::descriptions();
        unset($descriptions['0199a000-0000-7000-8000-00000000a003']);

        $this->expectException(CustomerViewIncomplete::class);
        $this->expectExceptionMessageMatches('/no description for line\(s\) 3\.$/');

        $this->mapper(descriptions: $descriptions)->map(self::quotation());
    }

    public function test_that_descriptions_are_asked_for_once_with_each_item_once(): void
    {
        /** @var ArrayObject<int, list<string>> $asked */
        $asked = new ArrayObject;

        $this->mapper(lineDescriptions: self::lineDescriptions(self::descriptions(), $asked))
            ->map(self::quotation(repeatFirstItem: true));

        self::assertSame([array_keys(self::SUPPLIER_ITEMS)], $asked->getArrayCopy());
    }

    public function test_that_delivery_terms_follow_the_flag_not_the_text(): void
    {
        $mapper = $this->mapper();

        self::assertNull($mapper->map(self::quotation(showDeliveryTerms: false))->deliveryTerms);
        self::assertSame('Within two weeks.', $mapper->map(self::quotation(showDeliveryTerms: true))->deliveryTerms);
        self::assertNull(
            $mapper->map(self::quotation(showDeliveryTerms: true, deliveryTerms: '  '))->deliveryTerms,
            'A shown section with nothing in it is omitted rather than printed empty.',
        );
    }

    public function test_that_the_money_chain_and_its_percentages_cross_unchanged(): void
    {
        $view = $this->mapper()->map(self::quotation());

        self::assertSame(
            ['34854.10', '250.00', '5', '1742.71', '33111.39', '14', '4635.59', '37746.98', '37996.98', '0.00'],
            [$view->subtotal, $view->additionalTotal, $view->discountPercent, $view->discountAmount, $view->taxBase,
                $view->taxPercent, $view->taxAmount, $view->netAmount, $view->finalTotal, $view->roundingDiff],
        );
        self::assertSame('Delivery & Installation', $view->additionalItems[0]->description);
    }

    public function test_that_the_mapper_copies_fields_by_name_and_never_spreads_a_row(): void
    {
        // `QuotationLine::asRow()` is the one list of a line's columns, cost
        // fields included. A mapper that spread it would carry any column
        // Module 7 adds later straight to the customer.
        $source = (string) file_get_contents(__DIR__.'/../../../app/Modules/Pdf/Application/CustomerQuotationViewMapper.php');

        self::assertStringNotContainsString('asRow(', $source);
        self::assertDoesNotMatchRegularExpression('/\.\.\.\$(quotation|line)\b/', $source);
        self::assertDoesNotMatchRegularExpression('/get_object_vars|ReflectionClass|toArray\(/', $source);
    }

    /**
     * @param  array<string, string|null>|null  $settings
     * @param  array<string, string>|null  $customerNames
     * @param  array<string, string>|null  $descriptions
     */
    private function mapper(
        ?array $settings = null,
        ?array $customerNames = null,
        ?array $descriptions = null,
        ?LineDescriptionsInterface $lineDescriptions = null,
    ): CustomerQuotationViewMapper {
        return new CustomerQuotationViewMapper(
            self::directory(self::quotation()),
            self::settings($settings ?? [
                SystemSetting::CompanyName->value => 'Smart Technology for Integrated Systems',
                SystemSetting::CompanyAddress->value => '5 El-Fath St, Wezarra Station, Boulkly, Alexandria, Egypt',
                SystemSetting::CompanyPhones->value => '035829952 · 01070764779',
            ]),
            self::customerNames($customerNames ?? [self::CUSTOMER_ID => 'Vegatrone']),
            $lineDescriptions ?? self::lineDescriptions($descriptions ?? self::descriptions()),
        );
    }

    private static function directory(QuotationDetail $quotation): QuotationReaderInterface
    {
        return new class($quotation) implements QuotationReaderInterface
        {
            public function __construct(private readonly QuotationDetail $quotation) {}

            public function find(string $quotationId): ?QuotationDetail
            {
                return $quotationId === $this->quotation->id ? $this->quotation : null;
            }
        };
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private static function settings(array $values): SettingsRepositoryInterface
    {
        return new class($values) implements SettingsRepositoryInterface
        {
            /**
             * @param  array<string, string|null>  $values
             */
            public function __construct(private readonly array $values) {}

            public function all(): array
            {
                return $this->values;
            }

            public function put(SystemSetting $setting, string $value): array
            {
                throw new LogicException('The mapper only reads.');
            }
        };
    }

    /**
     * @param  array<string, string>  $names
     */
    private static function customerNames(array $names): CustomerNamesInterface
    {
        return new class($names) implements CustomerNamesInterface
        {
            /**
             * @param  array<string, string>  $names
             */
            public function __construct(private readonly array $names) {}

            public function namesOf(array $customerIds): array
            {
                return array_intersect_key($this->names, array_flip($customerIds));
            }
        };
    }

    /**
     * Answers from `$descriptions`, appending every list it is asked for to `$asked`.
     *
     * @param  array<string, string>  $descriptions
     * @param  ArrayObject<int, list<string>>|null  $asked
     */
    private static function lineDescriptions(array $descriptions, ?ArrayObject $asked = null): LineDescriptionsInterface
    {
        return new class($descriptions, $asked ?? new ArrayObject) implements LineDescriptionsInterface
        {
            /**
             * @param  array<string, string>  $descriptions
             * @param  ArrayObject<int, list<string>>  $asked
             */
            public function __construct(private readonly array $descriptions, private readonly ArrayObject $asked) {}

            public function descriptionsOf(array $supplierQuotationItemIds): array
            {
                $this->asked->append($supplierQuotationItemIds);

                return array_intersect_key($this->descriptions, array_flip($supplierQuotationItemIds));
            }
        };
    }

    /**
     * @return array<string, string>
     */
    private static function descriptions(): array
    {
        return array_combine(array_keys(self::SUPPLIER_ITEMS), ['Formatter M428dw', 'Toner CF259A', 'Fuser RM2-5399']);
    }

    private static function quotation(
        bool $showDeliveryTerms = true,
        ?string $deliveryTerms = 'Within two weeks.',
        bool $repeatFirstItem = false,
    ): QuotationDetail {
        // Quantity, unit price and line total per supplier item — the customer's figures.
        $priced = [['2', '5219.30', '10438.60'], ['3', '6332.50', '18997.50'], ['1', '5418.00', '5418.00']];
        $ids = array_keys(self::SUPPLIER_ITEMS);

        $items = [];
        foreach ($ids as $index => $id) {
            $costs = self::SUPPLIER_ITEMS[$id];
            $items[] = new QuotationLine(
                id: '0199a000-0000-7000-8000-00000000b00'.($index + 1),
                lineNo: $index + 1,
                supplierQuotationItemId: $repeatFirstItem && $index === 2 ? $ids[0] : $id,
                unitCost: $costs['unitCost'],
                unitCostCurrency: 'USD',
                unitCostFxRateAtTime: self::FX_RATE,
                unitCostBase: $costs['unitCostBase'],
                marginPercent: $costs['marginPercent'],
                unitPrice: $priced[$index][1],
                quantity: $priced[$index][0],
                lineTotal: $priced[$index][2],
                lineCost: $costs['lineCost'],
            );
        }

        if ($repeatFirstItem) {
            // Two lines quoting the same supplier item: asked for once, and
            // the third item still asked for through a fourth line.
            $items[] = new QuotationLine('0199a000-0000-7000-8000-00000000b004', 4, $ids[2], '4333.39', 'USD', self::FX_RATE, '4333.3900', '19.45', '5418.00', '1', '5418.00', '4333.39');
        }

        return new QuotationDetail(
            id: '0199a000-0000-7000-8000-000000009001',
            code: 'QT-2026-0001',
            dealId: '0199a000-0000-7000-8000-00000000d001',
            customerId: self::CUSTOMER_ID,
            quotationDate: '2026-07-14',
            validUntil: '2026-08-13',
            status: 'approved',
            currencyId: '0199a000-0000-7000-8000-00000000e001',
            currency: 'EGP',
            defaultMargin: self::DEFAULT_MARGIN,
            discountPercent: '5',
            taxPercent: '14',
            roundingUnit: '1',
            roundingEnabled: false,
            subtotal: '34854.10',
            additionalTotal: '250.00',
            discountAmount: '1742.71',
            taxBase: '33111.39',
            taxAmount: '4635.59',
            netAmount: '37746.98',
            totalBeforeRound: '37996.98',
            finalTotal: '37996.98',
            roundingDiff: '0.00',
            paymentTerms: '50% advance, balance upon delivery.',
            warranty: 'One year.',
            deliveryTerms: $deliveryTerms,
            showDeliveryTerms: $showDeliveryTerms,
            version: 1,
            parentId: null,
            rejectionReason: null,
            sentAt: null,
            submittedAt: '2026-07-14T09:00:00+00:00',
            returnedAt: null,
            returnNote: null,
            isSelfApproved: false,
            versionToken: 3,
            createdBy: '0199a000-0000-7000-8000-00000000f001',
            updatedBy: null,
            createdAt: new DateTimeImmutable('2026-07-14T08:00:00+00:00'),
            updatedAt: new DateTimeImmutable('2026-07-14T09:00:00+00:00'),
            items: $items,
            additionalItems: [new QuotationAdditionalLine('0199a000-0000-7000-8000-00000000c101', 1, 'Delivery & Installation', '250.00')],
        );
    }

    /**
     * Every key at every depth of a decoded JSON document.
     *
     * @return list<string>
     */
    private static function keysOf(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            return [];
        }

        $keys = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            $keys = [...$keys, ...self::keysOf($value)];
        }

        return $keys;
    }
}
