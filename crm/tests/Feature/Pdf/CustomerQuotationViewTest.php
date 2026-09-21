<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Modules\Pdf\Domain\View\CustomerAdditionalLine;
use App\Modules\Pdf\Domain\View\CustomerQuotationLine;
use App\Modules\Pdf\Domain\View\CustomerQuotationView;
use App\Modules\Quotations\Domain\Listing\QuotationLine;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Module 9, Point 1.1 — the customer view's boundary, enforced.
 *
 * Pure, so it extends PHPUnit's own `TestCase` for the reason
 * `QuotationTotalsTest` gives.
 *
 * The acceptance criterion is that rendering consumes a model that
 * *"structurally cannot contain supplier, cost, or margin fields."* A docblock
 * saying so is not a structure. These tests are: a field added to any of the
 * three classes later fails the suite, rather than reaching a customer.
 */
final class CustomerQuotationViewTest extends TestCase
{
    /**
     * The three classes that together are everything the PDF may show.
     *
     * @var list<class-string>
     */
    private const CUSTOMER_VIEW = [
        CustomerQuotationView::class,
        CustomerQuotationLine::class,
        CustomerAdditionalLine::class,
    ];

    /**
     * Substrings no property of the customer view may contain, case-insensitively.
     *
     * `price` is deliberately absent: `unitPrice` is the customer's own figure
     * and §3.12 rule 2 forbids the *supplier's*. The two are told apart by the
     * supplier and cost terms below, not by banning the word price.
     *
     * @var list<string>
     */
    private const FORBIDDEN_SUBSTRINGS = ['cost', 'margin', 'supplier'];

    public function test_that_no_customer_view_field_is_one_of_module_7s_cost_fields(): void
    {
        // §3.5's "view cost & margin", named by the class that carries them,
        // so this test tracks Module 7's own list rather than a copy of it
        // that could drift. The snake_case keys become the camelCase property
        // names a PHP object would use.
        $forbidden = array_map(
            static fn (string $column): string => lcfirst(str_replace('_', '', ucwords($column, '_'))),
            QuotationLine::COST_FIELDS,
        );

        self::assertSame(
            ['unitCost', 'unitCostCurrency', 'unitCostFxRateAtTime', 'unitCostBase', 'marginPercent', 'lineCost'],
            $forbidden,
            'Module 7 changed COST_FIELDS; this test converted it to something unexpected.',
        );

        foreach (self::CUSTOMER_VIEW as $class) {
            foreach (self::propertyNames($class) as $property) {
                self::assertNotContains(
                    $property,
                    $forbidden,
                    "{$class}::\${$property} is one of §3.5's cost & margin fields. The customer PDF "
                    .'excludes supplier cost and margin unconditionally (§3.12 rule 2, §14.6), so the '
                    .'field must not exist on the view rather than be hidden when rendering.',
                );
            }
        }
    }

    public function test_that_no_customer_view_field_names_a_cost_margin_or_supplier(): void
    {
        foreach (self::CUSTOMER_VIEW as $class) {
            foreach (self::propertyNames($class) as $property) {
                foreach (self::FORBIDDEN_SUBSTRINGS as $forbidden) {
                    self::assertStringNotContainsStringIgnoringCase(
                        $forbidden,
                        $property,
                        "{$class}::\${$property} names a \"{$forbidden}\". A field Module 7 adds later "
                        .'must be mapped deliberately or left out, never carried across because the '
                        .'mapper was updated without reading what it now copies.',
                    );
                }
            }
        }
    }

    public function test_that_the_customer_view_cannot_be_changed_after_construction(): void
    {
        foreach (self::CUSTOMER_VIEW as $class) {
            $reflection = new ReflectionClass($class);

            self::assertTrue($reflection->isFinal(), "{$class} must be final.");
            self::assertTrue($reflection->isReadOnly(), "{$class} must be readonly.");
            self::assertFalse(
                $reflection->hasMethod('__set'),
                "{$class} must not define __set: a snapshot the renderer is handed is fixed (§14.6).",
            );

            foreach ($reflection->getMethods() as $method) {
                self::assertDoesNotMatchRegularExpression(
                    '/^(set|with|add|append)[A-Z]/',
                    $method->getName(),
                    "{$class}::{$method->getName()}() mutates or derives a new view. The PDF is an "
                    .'immutable snapshot, so the object the renderer receives is the object it was built with.',
                );
            }
        }
    }

    public function test_that_optional_prose_is_absent_rather_than_blank(): void
    {
        // §6.2's "show delivery terms in PDF (yes/no)": a false must omit the
        // section. A blank string would let a template print a heading with
        // nothing under it and still pass every other check.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/deliveryTerms must be absent/');

        self::view(deliveryTerms: '   ');
    }

    public function test_that_delivery_terms_may_be_absent_or_present(): void
    {
        self::assertNull(self::view(deliveryTerms: null)->deliveryTerms);
        self::assertSame('Within two weeks.', self::view(deliveryTerms: 'Within two weeks.')->deliveryTerms);
    }

    public function test_that_the_percentages_the_labels_need_are_carried(): void
    {
        // P-01's template hard-codes "Discount 5%" and "14% VAT" into its
        // labels. Step 2 interpolates them, which is only possible if the view
        // carries the rates and not merely the amounts.
        $view = self::view();

        self::assertSame('5', $view->discountPercent);
        self::assertSame('14', $view->taxPercent);
    }

    /**
     * @param  class-string  $class
     * @return list<string>
     */
    private static function propertyNames(string $class): array
    {
        $names = [];

        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            $names[] = $property->getName();

            // A property typed as another view class is followed, so a cost
            // field cannot be smuggled in one level down.
            $type = $property->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                self::assertContains(
                    $type->getName(),
                    self::CUSTOMER_VIEW,
                    "{$class}::\${$property->getName()} is typed {$type->getName()}, which is not part of "
                    .'the customer view. The view may only be built from itself, or the guarantee stops at its edge.',
                );
            }
        }

        return $names;
    }

    private static function view(?string $deliveryTerms = null): CustomerQuotationView
    {
        return new CustomerQuotationView(
            code: 'QT-2026-0001',
            quotationDate: '2026-07-14',
            validUntil: '2026-08-13',
            currencyCode: 'EGP',
            customerName: 'Vegatrone',
            customerContact: 'Hussein',
            companyName: 'Smart Technology for Integrated Systems',
            companyAddress: '5 El-Fath St, Wezarra Station, Boulkly, Alexandria, Egypt',
            companyPhones: ['035829952', '01070764779'],
            lines: [new CustomerQuotationLine(1, 'Formatter M428dw', '1', '5219.30', '5219.30')],
            additionalItems: [new CustomerAdditionalLine(1, 'Delivery & Installation', '250.00')],
            subtotal: '5219.30',
            additionalTotal: '250.00',
            discountPercent: '5',
            discountAmount: '260.97',
            taxBase: '4958.33',
            taxPercent: '14',
            taxAmount: '694.17',
            netAmount: '5652.50',
            finalTotal: '5902.50',
            roundingDiff: '0.00',
            paymentTerms: '50% advance, balance upon delivery.',
            warranty: 'One year.',
            deliveryTerms: $deliveryTerms,
        );
    }
}
