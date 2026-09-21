<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Access;

use InvalidArgumentException;

/**
 * `SEC-08` — how far a caller's §3.3 grant reaches into the `customers` table.
 *
 * The authorisation engine answers *whether* a caller may view or edit a
 * customer; §3.2 makes every permission `resource.action.scope`, so the answer
 * carries a reach as well. This turns that reach into the only two things a
 * query needs: either every row, or the rows belonging to a known set of sales
 * owners.
 *
 * ── Why the codes are strings and not Identity's `Scope` enum ──────────────
 *
 * Identity owns one, and this deliberately does not use it. `deptrac.modules.yaml`
 * gives `Customers` an **empty ruleset** — the module may reference nothing at
 * all, Identity included — because `AP-02` wants each module extractable. So
 * §3.2's five codes cross the boundary as the strings the document writes, and
 * `CustomerRowScopeTest` reads that table out of the master documentation to
 * check the two transcriptions still agree. Duplication that a test pins is
 * cheaper here than a module boundary that leaks.
 *
 * ── Three of the five have no mechanism, and they fail closed ──────────────
 *
 * | code | what §3.2 says | what backs it today |
 * |---|---|---|
 * | `all` | every record in the system | no predicate needed |
 * | `own` | their own records only | `customers.sales_owner_id` |
 * | `team` | their team's records | **nothing** — no team entity in §4.1, no team field in §4.2, no team column on `users` |
 * | `out` | outdoor team records only | **nothing** — `D-44` scopes it to "visits, visit customers"; `visits` is Module 12 |
 * | `asgn` | deals handed over to them | **nothing** — `deals` is Module 5 |
 *
 * The three unbacked codes resolve to **no rows**. That is the safe half of an
 * undefined rule rather than the finished behaviour: guessing that `team` means
 * "everyone sharing a role" or "everyone this leader created" would invent an
 * authorisation rule no source states, and the way that fails is by handing one
 * person's customers to somebody the matrix never granted them to. A Team
 * Leader therefore resolves to nothing at all today, which is **an open owner
 * question recorded in `CHECKLIST.md`**, not a behaviour anybody signed off.
 *
 * ponytail: `ownerIds` is a set of owners rather than a boolean "mine only"
 * precisely so `team` and `out` become a wider set later without changing this
 * shape or any caller. `asgn` will not fit it — "deals handed over to them" is a
 * join, not an owner — and that arm is left for Module 5 rather than guessed at
 * now.
 */
final readonly class CustomerRowScope
{
    /** §3.2's code table. Transcribed; `CustomerRowScopeTest` reads the document and compares. */
    private const ALL = 'all';

    private const OWN = 'own';

    private const TEAM = 'team';

    private const OUT = 'out';

    private const ASGN = 'asgn';

    /**
     * @param  list<string>  $ownerIds  the sales owners this caller may reach; empty when none
     */
    private function __construct(
        public bool $unrestricted,
        public array $ownerIds,
    ) {}

    /**
     * The reach a caller holds, from the scope codes their grants carry.
     *
     * @param  list<string>  $heldScopes  §3.2 codes, as the authorisation decision reports them
     * @param  string|null  $actorId  the caller's own user id — what `own` means; null when the
     *                                system acts on its own behalf, which can hold `all` and nothing narrower
     *
     * @throws InvalidArgumentException on a code §3.2 does not define, or a blank actor
     */
    public static function resolve(array $heldScopes, ?string $actorId): self
    {
        if ($actorId !== null && trim($actorId) === '') {
            // Never a legitimate call. Letting it through would make `own`
            // silently match nothing, which is indistinguishable from a refusal.
            throw new InvalidArgumentException('A row scope needs the caller it is being resolved for.');
        }

        $ownerIds = [];

        foreach ($heldScopes as $code) {
            switch ($code) {
                case self::ALL:
                    // Widest reach there is; nothing a later code adds can extend
                    // it, so answering once here keeps the two fields consistent.
                    return new self(true, []);

                case self::OWN:
                    if ($actorId === null) {
                        // The system has no rows of its own; `own` without a caller is a defect, not an empty set.
                        throw new InvalidArgumentException('The own scope needs the caller it is being resolved for.');
                    }

                    $ownerIds[] = $actorId;
                    break;

                case self::TEAM:
                case self::OUT:
                case self::ASGN:
                    // Recognised, unbacked, and therefore contributing no rows.
                    // Listed rather than defaulted so that the day one of them
                    // gains a mechanism, this is where it lands.
                    break;

                default:
                    throw new InvalidArgumentException(
                        "Unknown scope '{$code}': §3.2 defines own, team, all, out and asgn.",
                    );
            }
        }

        return new self(false, array_values(array_unique($ownerIds)));
    }

    /**
     * Whether this reach selects no rows whatsoever.
     *
     * A caller that has to check `unrestricted` and `ownerIds` separately is a
     * caller that one day will check only one of them, and the half it forgets
     * is the half that returns every row.
     */
    public function permitsNothing(): bool
    {
        return ! $this->unrestricted && $this->ownerIds === [];
    }

    /**
     * §3.2's five codes, in the document's order.
     *
     * @return list<string>
     */
    public static function recognisedCodes(): array
    {
        return [self::OWN, self::TEAM, self::ALL, self::OUT, self::ASGN];
    }
}
