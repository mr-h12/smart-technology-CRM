<?php

declare(strict_types=1);

namespace Tests\Feature\Deals;

use App\Modules\Deals\Domain\Access\DealRowScope;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Module 5, Point 2.1 — §3.4's row scope, resolved as a Domain concept.
 *
 * See {@see DealRowScope}'s own docblock for why `asgn` still fails closed
 * even though `deals` now exists — the reason changed, it did not close.
 */
final class DealRowScopeTest extends TestCase
{
    private const MASTER_DOCUMENTATION = '/opt/crm/docs/CRM_Documentation_EN.md';

    private const ACTOR = '0192f3aa-0000-7000-8000-00000000000a';

    private const OTHER = '0192f3aa-0000-7000-8000-00000000000b';

    private const MEMBER = '0192f3aa-0000-7000-8000-00000000000c';

    public function test_that_all_reaches_every_row(): void
    {
        $scope = DealRowScope::resolve(['all'], self::ACTOR);

        self::assertTrue($scope->unrestricted);
        self::assertFalse($scope->permitsNothing());
        self::assertSame([], $scope->ownerIds, 'Unrestricted must not also carry an owner list.');
    }

    public function test_that_own_reaches_only_the_callers_own_rows(): void
    {
        $scope = DealRowScope::resolve(['own'], self::ACTOR);

        self::assertFalse($scope->unrestricted);
        self::assertSame([self::ACTOR], $scope->ownerIds);
        self::assertNotContains(self::OTHER, $scope->ownerIds);
    }

    /**
     * Module 9 · 4.1 — one row's owner against the reach, the question a
     * quotation's PDF asks of its deal. A null owner is nobody's, so `own`
     * never reaches it; `all` reaches it like any other.
     */
    public function test_that_reaches_answers_for_one_owner(): void
    {
        $all = DealRowScope::resolve(['all'], self::ACTOR);
        $own = DealRowScope::resolve(['own'], self::ACTOR);
        $unbacked = DealRowScope::resolve(['asgn', 'out'], self::ACTOR);

        self::assertTrue($all->reaches(self::OTHER));
        self::assertTrue($all->reaches(null));

        self::assertTrue($own->reaches(self::ACTOR));
        self::assertFalse($own->reaches(self::OTHER));
        self::assertFalse($own->reaches(null));

        self::assertFalse($unbacked->reaches(self::ACTOR));
        self::assertFalse($unbacked->reaches(null));
    }

    /** Two answers to one question is the defect; `all` subsumes `own`. */
    public function test_that_all_and_own_together_answer_once(): void
    {
        $scope = DealRowScope::resolve(['own', 'all'], self::ACTOR);

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
        $scope = DealRowScope::resolve([$code], self::ACTOR);

        self::assertFalse($scope->unrestricted, "{$code} must never widen to every row.");
        self::assertSame([], $scope->ownerIds);
        self::assertTrue($scope->permitsNothing());
    }

    /** @return array<string, array{string}> — the keys are the data-set names PHPUnit prints. */
    public static function scopesWithNoMechanism(): array
    {
        return [
            'out has no visits table' => ['out'],
            'asgn has no procurement-assignment column' => ['asgn'],
        ];
    }

    /** `D-138` — Team reaches the deals the team owns: the leader and every member the caller passes in. */
    public function test_that_team_reaches_the_team_members(): void
    {
        $scope = DealRowScope::resolve(['team'], self::ACTOR, self::team(...));

        self::assertFalse($scope->unrestricted);
        self::assertSame([self::ACTOR, self::MEMBER], $scope->ownerIds);
        self::assertTrue($scope->reaches(self::MEMBER));
        self::assertFalse($scope->reaches(self::OTHER));
    }

    /** `own` and `team` together list each owner once. */
    public function test_that_team_and_own_together_list_each_owner_once(): void
    {
        $scope = DealRowScope::resolve(['own', 'team'], self::ACTOR, self::team(...));

        self::assertSame([self::ACTOR, self::MEMBER], $scope->ownerIds);
    }

    /** Holding `team` with no member list is a defect at the call site, not an empty team. */
    public function test_that_team_without_its_members_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DealRowScope::resolve(['team'], self::ACTOR);
    }

    public function test_that_holding_no_scope_permits_nothing(): void
    {
        $scope = DealRowScope::resolve([], self::ACTOR);

        self::assertTrue($scope->permitsNothing());
        self::assertFalse($scope->unrestricted);
    }

    /**
     * A code §3.2 does not define is a programming error, not a denial.
     *
     * Failing closed on it would hide a typo behind an empty list that looks
     * exactly like a legitimate refusal.
     */
    public function test_that_an_undefined_code_is_refused_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DealRowScope::resolve(['everything'], self::ACTOR);
    }

    public function test_that_a_blank_actor_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DealRowScope::resolve(['own'], '  ');
    }

    /**
     * §3.2's code table, read out of the document rather than restated here.
     *
     * A list this test agreed with because both were typed by the same hand
     * would prove nothing — and it is the same table `CustomerRowScopeTest`
     * reads, which is what proves the two transcriptions have not drifted.
     */
    public function test_that_the_recognised_codes_are_exactly_the_documented_five(): void
    {
        self::assertSame(
            self::documentedScopeCodes(),
            DealRowScope::recognisedCodes(),
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
