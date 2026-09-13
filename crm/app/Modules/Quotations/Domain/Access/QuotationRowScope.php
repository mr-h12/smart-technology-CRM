<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Domain\Access;

use InvalidArgumentException;

/**
 * `SEC-08` — how far a caller's §3.5 grant reaches into the `quotations` table.
 *
 * The shape is `CustomerRowScope`'s (Module 3 Point 3.1) and `DealRowScope`'s
 * (Module 5 Point 2.1), transcribed rather than shared: `deptrac.modules.yaml`
 * gives `Quotations` a two-entry ruleset that does not include either module,
 * so §3.2's five codes cross the boundary as the strings the document writes,
 * and {@see \Tests\Feature\Quotations\QuotationRowScopeTest} reads §3.2's table
 * out of the master documentation to check this transcription still agrees with
 * theirs — three modules quietly drifting on what `own` means would be worse
 * than any one of them being wrong.
 *
 * ── §3.5's `view` row is the widest spread of scopes in the document ────────
 *
 * `| view | All | Team | — | Own | Own | Asgn | All |` across Manager, Team
 * Leader, Outdoor Supervisor, Outdoor Sales, Indoor Sales, Procurement and CEO.
 * Four of the five codes appear on one row, which is why this module needs the
 * resolver at all and why two of the four still reach nothing:
 *
 * | code | what §3.2 says | what backs it today |
 * |---|---|---|
 * | `all` | every record in the system | no predicate needed — Manager and CEO |
 * | `own` | their own records only | the caller's id; **which column carries it is not decided here** — see below |
 * | `team` | their team's records | **nothing** — no team entity in §4.1, no team column on `users`; unchanged since Module 1 Point 3.2 found it |
 * | `out` | outdoor team records only | **nothing**, and §3.5 never grants it on a quotation anyway — Outdoor Supervisor's cell is `—` |
 * | `asgn` | deals handed over to them | **nothing** — and here it is Procurement's only way to see a quotation at all |
 *
 * `asgn` is the one that costs something. §3.5 grants Procurement `Asgn` on
 * `quotation.view`, so until an assignment column exists Procurement sees no
 * quotation whatsoever. That is the safe half of an undefined rule, and it is
 * the same open gap `DealRowScope` records: `deals.owner_id` is §4.3's *sales*
 * owner, and reusing it for a procurement assignment would silently redefine a
 * documented field. Failing closed is deliberate — a guess that widens access
 * is the failure mode, not a guess that narrows it.
 *
 * ── What `own` matches on is deliberately not answered here ─────────────────
 *
 * This class turns scope codes into the caller's id, exactly as its two
 * siblings do; the column that id is compared against lives in the directory's
 * query, the way `deals.owner_id` does in `EloquentDealDirectory::scoped()`.
 * That choice is open for a quotation and it is a real one: `quotations` has no
 * owner column at all, only `created_by` (§6.2's tracking field) and `deal_id`
 * pointing at a row that does have one. Whether reassigning a deal should carry
 * its quotations with it is the question, and it belongs to the point that
 * writes `find()`, not to this one.
 */
final readonly class QuotationRowScope
{
    /** §3.2's code table. Transcribed; the test reads the document and compares. */
    private const ALL = 'all';

    private const OWN = 'own';

    private const TEAM = 'team';

    private const OUT = 'out';

    private const ASGN = 'asgn';

    /**
     * @param  list<string>  $ownerIds  the quotation owners this caller may reach; empty when none
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
     * Whether a quotation whose deal is owned by `$ownerId` is within reach —
     * the owner ruling of 2026-09-11 that "own" is the deal's `owner_id`,
     * asked in one place by the create (Point 3.4) and the read (Point 3.5).
     *
     * A null owner is nobody's, so it is nobody's under `own`.
     */
    public function reaches(?string $ownerId): bool
    {
        return $this->unrestricted || ($ownerId !== null && in_array($ownerId, $this->ownerIds, true));
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
