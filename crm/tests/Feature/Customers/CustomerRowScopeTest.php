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
 * ── Two of the five codes have no mechanism, and that is the finding ───────
 *
 * `All` is "every record", `Own` is `customers.sales_owner_id`, and `Team`
 * (`D-138`) is the sales owners of the caller's team — the leader and every user
 * whose `team_leader_id` is theirs — which the caller resolves through
 * Identity and passes in, because this module may reference nothing. The other
 * two have no mechanism:
 *
 * - **Out** — `D-44` confines the Outdoor Supervisor to "visits, visit
 *   customers, and their team's deals up to handover". `visits` is Module 12
 *   (`D-138`: on customers and deals, Out stays unbacked until then).
 * - **Asgn** — §3.2 says "deals handed over to them"; it is not an owner.
 *
 * They therefore resolve to **no rows**, never to "all rows". A scope whose
 * mechanism is missing must fail closed, because the alternative is inventing
 * an authorisation rule the documentation does not state.
 */
final class CustomerRowScopeTest extends TestCase
{
    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    private const ACTOR = '0192f3aa-0000-7000-8000-00000000000a';

    private const OTHER = '0192f3aa-0000-7000-8000-00000000000b';

    private const MEMBER = '0192f3aa-0000-7000-8000-00000000000c';

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
            'out has no visits table' => ['out'],
            'asgn has no deals table' => ['asgn'],
        ];
    }

    /** `D-138` — Team reaches the rows the team owns: the leader and every member the caller passes in. */
    public function test_that_team_reaches_the_team_members(): void
    {
        $scope = CustomerRowScope::resolve(['team'], self::ACTOR, self::team(...));

        self::assertFalse($scope->unrestricted);
        self::assertSame([self::ACTOR, self::MEMBER], $scope->ownerIds);
        self::assertNotContains(self::OTHER, $scope->ownerIds);
    }

    /** `own` and `team` together list each owner once. */
    public function test_that_team_and_own_together_list_each_owner_once(): void
    {
        $scope = CustomerRowScope::resolve(['own', 'team'], self::ACTOR, self::team(...));

        self::assertSame([self::ACTOR, self::MEMBER], $scope->ownerIds);
    }

    /** Holding `team` with no member list is a defect at the call site, not an empty team (mirrors `own` with no actor). */
    public function test_that_team_without_its_members_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CustomerRowScope::resolve(['team'], self::ACTOR);
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

    /** `D-87`'s correction runs as the system (F-11 · 1.4): no caller, so `all` is the only reach it can hold. */
    public function test_that_the_system_may_hold_all_without_an_actor(): void
    {
        self::assertTrue(CustomerRowScope::resolve(['all'], null)->unrestricted);
    }

    public function test_that_own_without_an_actor_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CustomerRowScope::resolve(['own'], null);
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

    /**
     * Identity's `teamMemberIdsOf()` for the actor: the leader and one member.
     *
     * @return list<string>
     */
    private static function team(string $leaderId): array
    {
        return $leaderId === self::ACTOR ? [self::ACTOR, self::MEMBER] : [];
    }
}
