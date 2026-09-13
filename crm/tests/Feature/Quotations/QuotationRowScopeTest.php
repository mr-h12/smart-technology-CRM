<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Modules\Quotations\Domain\Access\QuotationRowScope;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Module 7, Point 3.1 — §3.5's row scope, resolved as a Domain concept.
 *
 * See {@see QuotationRowScope}'s own docblock for why `asgn` fails closed and
 * what that costs Procurement, and for why the column `own` compares against is
 * not decided in this class.
 */
final class QuotationRowScopeTest extends TestCase
{
    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    private const ACTOR = '0192f3aa-0000-7000-8000-0000000000c1';

    private const OTHER = '0192f3aa-0000-7000-8000-0000000000c2';

    public function test_that_all_reaches_every_row(): void
    {
        $scope = QuotationRowScope::resolve(['all'], self::ACTOR);

        self::assertTrue($scope->unrestricted);
        self::assertSame([], $scope->ownerIds);
        self::assertFalse($scope->permitsNothing());
    }

    public function test_that_own_reaches_only_the_callers_own_rows(): void
    {
        $scope = QuotationRowScope::resolve(['own'], self::ACTOR);

        self::assertFalse($scope->unrestricted);
        self::assertSame([self::ACTOR], $scope->ownerIds);
        self::assertNotContains(self::OTHER, $scope->ownerIds);
    }

    public function test_that_all_and_own_together_answer_once(): void
    {
        // `all` is the widest reach there is, so the pair must not come back as
        // "unrestricted, and also these ids" — a reader checking `ownerIds`
        // first would then narrow a caller who should see everything.
        $scope = QuotationRowScope::resolve(['own', 'all'], self::ACTOR);

        self::assertTrue($scope->unrestricted);
        self::assertSame([], $scope->ownerIds);
    }

    public function test_that_a_repeated_code_does_not_repeat_the_owner(): void
    {
        self::assertSame([self::ACTOR], QuotationRowScope::resolve(['own', 'own'], self::ACTOR)->ownerIds);
    }

    // ───────────────────────── the codes §3.2 defines but nothing backs

    /** @return array<string, array{0: string}> */
    public static function scopesWithNoMechanism(): array
    {
        return [
            'team has no team entity' => ['team'],
            'out has no visits until Module 12' => ['out'],
            'asgn has no assignment column' => ['asgn'],
        ];
    }

    #[DataProvider('scopesWithNoMechanism')]
    public function test_that_a_scope_with_no_mechanism_permits_nothing(string $code): void
    {
        $scope = QuotationRowScope::resolve([$code], self::ACTOR);

        self::assertFalse($scope->unrestricted);
        self::assertSame([], $scope->ownerIds);
        self::assertTrue($scope->permitsNothing(),
            'A scope with no mechanism must fail closed, not open.');
    }

    public function test_that_procurement_sees_no_quotation_until_asgn_has_a_mechanism(): void
    {
        // §3.5 grants Procurement `Asgn` on `quotation.view` and nothing else,
        // so this is not an edge case for them — it is their whole access. It
        // is asserted rather than left implicit so the day `asgn` gains a
        // column, this test fails and says which behaviour changed.
        $scope = QuotationRowScope::resolve(['asgn'], self::ACTOR);

        self::assertTrue($scope->permitsNothing());
    }

    public function test_that_team_adds_nothing_to_own(): void
    {
        // A Team Leader holds `team` on §3.5's view row. Until a team entity
        // exists that grant must not quietly widen past their own rows.
        $scope = QuotationRowScope::resolve(['own', 'team'], self::ACTOR);

        self::assertSame([self::ACTOR], $scope->ownerIds);
        self::assertFalse($scope->unrestricted);
    }

    public function test_that_holding_no_scope_permits_nothing(): void
    {
        self::assertTrue(QuotationRowScope::resolve([], self::ACTOR)->permitsNothing());
    }

    // ───────────────────────── programming errors, not denials

    public function test_that_an_undefined_code_is_refused_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QuotationRowScope::resolve(['everything'], self::ACTOR);
    }

    public function test_that_a_blank_actor_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QuotationRowScope::resolve(['own'], '  ');
    }

    // ───────────────────────── the document, not a restatement of the class

    /**
     * §3.2's code table, read out of the document rather than restated here.
     *
     * A list this test agreed with because both were typed by the same hand
     * would prove nothing — and it is the same table `CustomerRowScopeTest` and
     * `DealRowScopeTest` read, which is what proves the three transcriptions
     * have not drifted.
     */
    public function test_that_the_recognised_codes_are_exactly_the_documented_five(): void
    {
        self::assertSame(
            self::documentedScopeCodes(),
            QuotationRowScope::recognisedCodes(),
            '§3.2 and the resolver disagree about which scopes exist.',
        );
    }

    /**
     * The bolded codes in §3.2's table, lowercased, excluding the `—` row.
     *
     * @return list<string>
     */
    private static function documentedScopeCodes(): array
    {
        self::assertFileExists(
            self::MASTER_DOCUMENTATION,
            'The master documentation is not mounted. This fails rather than skips: without it '
            .'this check would only be agreeing with the class it is checking.',
        );

        $body = (string) file_get_contents(self::MASTER_DOCUMENTATION);

        $start = strpos($body, '### 3.2 Permission Model');
        $end = strpos($body, '### 3.3 Customers');

        self::assertIsInt($start, '§3.2 is no longer in the master documentation.');
        self::assertIsInt($end, '§3.3 is no longer in the master documentation.');

        $section = substr($body, $start, $end - $start);

        $matches = [];
        preg_match_all('/^\|\s*\*\*(.+?)\*\*\s*\|/mu', $section, $matches);

        $codes = [];

        foreach ($matches[1] as $code) {
            $code = mb_strtolower(trim($code));

            // §3.2 bolds six cells, and the sixth is `—` "Not permitted" — the
            // absence of a scope rather than one of them.
            if ($code === '—') {
                continue;
            }

            $codes[] = $code;
        }

        self::assertCount(5, $codes, '§3.2 no longer defines exactly five scope codes.');

        return $codes;
    }
}
