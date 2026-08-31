<?php

declare(strict_types=1);

namespace App\Modules\Deals\Domain\Access;

use InvalidArgumentException;

/**
 * `SEC-08` — how far a caller's §3.4 grant reaches into the `deals` table.
 *
 * The shape is `CustomerRowScope`'s (Module 3 Point 3.1), transcribed rather
 * than shared: `deptrac.modules.yaml` gives `Deals` an empty ruleset, so §3.2's
 * five codes cross the boundary as the strings the document writes, and
 * {@see DealRowScopeTest} reads §3.2's table out of the master documentation to
 * check this transcription still agrees with Customers' — two modules quietly
 * drifting on what `own` means would be worse than either one being wrong.
 *
 * ── Three of the five have no mechanism, and one of them is a new gap ──────
 *
 * | code | what §3.2 says | what backs it today |
 * |---|---|---|
 * | `all` | every record in the system | no predicate needed |
 * | `own` | their own records only | `deals.owner_id` |
 * | `team` | their team's records | **nothing** — no team entity in §4.1, no team field anywhere, no team column on `users` (Module 1 Point 3.2's own finding, unchanged by this module existing) |
 * | `out` | outdoor team records only | **nothing** — `D-44` scopes it to "visits, visit customers"; `visits` is Module 12 |
 * | `asgn` | §3.4's own reading: deals assigned to Procurement | **nothing** — `deals` has `owner_id` for "assigned sales employee" (§4.3) and no second assignment column; a procurement-facing one is not documented anywhere in §4 |
 *
 * `asgn` is worth pausing on precisely because it looks answered. Customers'
 * own `asgn` failed for a reason this module now removes — "`deals` is Module
 * 5" — and `deals` exists. But §3.4's `Asgn` column belongs to **Procurement**,
 * not to the sales owner §4.3 already names, and no field says which
 * procurement employee a deal is assigned to. Reusing `owner_id` for both would
 * silently redefine what §4.3 documents it as. So `asgn` still fails closed
 * here, for a different and still-open reason — recorded rather than assumed
 * answered because the table changed.
 *
 * The three unbacked codes resolve to **no rows**, the same safe half of an
 * undefined rule `CustomerRowScope` chose, and for the same reason: a guess
 * that widens access is the failure mode, not a guess that narrows it.
 */
final readonly class DealRowScope
{
    /** §3.2's code table. Transcribed; {@see DealRowScopeTest} reads the document and compares. */
    private const ALL = 'all';

    private const OWN = 'own';

    private const TEAM = 'team';

    private const OUT = 'out';

    private const ASGN = 'asgn';

    /**
     * @param  list<string>  $ownerIds  the deal owners this caller may reach; empty when none
     */
    private function __construct(
        public bool $unrestricted,
        public array $ownerIds,
    ) {}

    /**
     * The reach a caller holds, from the scope codes their grants carry.
     *
     * @param  list<string>  $heldScopes  §3.2 codes, as the authorisation decision reports them
     * @param  string  $actorId  the caller's own user id — what `own` means
     *
     * @throws InvalidArgumentException on a code §3.2 does not define, or a blank actor
     */
    public static function resolve(array $heldScopes, string $actorId): self
    {
        if (trim($actorId) === '') {
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
