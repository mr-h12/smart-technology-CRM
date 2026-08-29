<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Modules\Customers\Domain\Access\CustomerRowScope;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Module 3, Point 3.1 — §3.3's row scope, resolved as a Domain concept.
 *
 * ── Why Customers transcribes §3.2's codes instead of importing them ───────
 *
 * Identity already owns a `Scope` enum, and this class deliberately does not
 * use it. `deptrac.modules.yaml` gives `Customers` an **empty ruleset**: the
 * module may reference nothing, not Identity and not the framework. So the five
 * codes cross the boundary as the strings §3.2 writes them, and
 * {@see test_that_the_recognised_codes_are_exactly_the_documented_five} reads
 * that table **out of the master documentation** rather than restating it — a
 * sixth code added to §3.2 must fail here rather than be silently ignored by an
 * authorisation rule.
 *
 * ── Three of the five codes have no mechanism, and that is the finding ─────
 *
 * `All` is "every record" and `Own` is `customers.sales_owner_id`, both of
 * which exist. The other three do not:
 *
 * - **Team** — §3.2 says "their team's records", and there is no team. §4.1's
 *   entity map has no team entity, §4.2 has no team field, and the `users`
 *   table has no team column (recorded already at Module 1 Point 3.2: "no team
 *   scoping, because `users` has no team column").
 * - **Out** — `D-44` confines the Outdoor Supervisor to "visits, visit
 *   customers, and their team's deals up to handover". `visits` is Module 12.
 * - **Asgn** — §3.2 says "deals handed over to them". `deals` is Module 5.
 *
 * They therefore resolve to **no rows**, never to "all rows". A scope whose
 * mechanism is missing must fail closed, because the alternative is inventing
 * an authorisation rule the documentation does not state — and the failure mode
 * of guessing here is handing somebody else's customers to a role that should
 * not see them. This is an **open owner question**, not a finished behaviour:
 * a Team Leader currently resolves to nothing at all.
 */
final class CustomerRowScopeTest extends TestCase
{
    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    private const ACTOR = '0192f3aa-0000-7000-8000-00000000000a';

    private const OTHER = '0192f3aa-0000-7000-8000-00000000000b';

    public function test_that_all_reaches_every_row(): void
    {
        $scope = CustomerRowScope::resolve(['all'], self::ACTOR);

        self::assertTrue($scope->unrestricted);
        self::assertFalse($scope->permitsNothing());
        self::assertSame([], $scope->ownerIds, 'Unrestricted must not also carry an owner list.');
    }

    public function test_that_own_reaches_only_the_callers_own_rows(): void
    {
        $scope = CustomerRowScope::resolve(['own'], self::ACTOR);

        self::assertFalse($scope->unrestricted);
        self::assertSame([self::ACTOR], $scope->ownerIds);
        self::assertNotContains(self::OTHER, $scope->ownerIds);
    }

    /** Two answers to one question is the defect; `all` subsumes `own`. */
    public function test_that_all_and_own_together_answer_once(): void
    {
        $scope = CustomerRowScope::resolve(['own', 'all'], self::ACTOR);

        self::assertTrue($scope->unrestricted);
        self::assertSame([], $scope->ownerIds);
    }

    /**
     * The negative authorisation §3.12 rule 1 and `SEC-09` ask for: a scope
     * with no mechanism yields no rows, never every row.
     */
    #[DataProvider('scopesWithNoMechanism')]
    public function test_that_a_scope_with_no_mechanism_permits_nothing(string $code): void
    {
        $scope = CustomerRowScope::resolve([$code], self::ACTOR);

        self::assertFalse($scope->unrestricted, "{$code} must never widen to every row.");
        self::assertSame([], $scope->ownerIds);
        self::assertTrue($scope->permitsNothing());
    }

    /** @return array<string, array{string}> — the keys are the data-set names PHPUnit prints. */
    public static function scopesWithNoMechanism(): array
    {
        return [
            'team has no team table' => ['team'],
            'out has no visits table' => ['out'],
            'asgn has no deals table' => ['asgn'],
        ];
    }

    /** Holding `own` as well must not let the unimplemented scope widen anything. */
    public function test_that_team_adds_nothing_to_own(): void
    {
        $scope = CustomerRowScope::resolve(['own', 'team'], self::ACTOR);

        self::assertFalse($scope->unrestricted);
        self::assertSame([self::ACTOR], $scope->ownerIds);
    }

    public function test_that_holding_no_scope_permits_nothing(): void
    {
        $scope = CustomerRowScope::resolve([], self::ACTOR);

        self::assertTrue($scope->permitsNothing());
        self::assertFalse($scope->unrestricted);
    }

    /**
     * A code §3.2 does not define is a programming error, not a denial.
     *
     * Failing closed on it would hide a typo behind an empty list that looks
     * exactly like a legitimate refusal. `AuthorizePermission` already throws on
     * an unknown route scope for the same reason.
     */
    public function test_that_an_undefined_code_is_refused_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CustomerRowScope::resolve(['everything'], self::ACTOR);
    }

    public function test_that_a_blank_actor_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CustomerRowScope::resolve(['own'], '  ');
    }

    /**
     * §3.2's code table, read out of the document rather than restated here.
     *
     * A list this test agreed with because both were typed by the same hand
     * would prove nothing.
     */
    public function test_that_the_recognised_codes_are_exactly_the_documented_five(): void
    {
        self::assertSame(
            self::documentedScopeCodes(),
            CustomerRowScope::recognisedCodes(),
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
            // absence of a scope rather than one of them. Measured, not assumed:
            // the first run of this parser returned six and said so.
            if ($code === '—') {
                continue;
            }

            $codes[] = $code;
        }

        self::assertCount(5, $codes, '§3.2 no longer defines exactly five scope codes.');

        return $codes;
    }
}
