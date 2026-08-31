<?php

declare(strict_types=1);

namespace App\Modules\Deals\Domain\CustomerStatus;

use DateTimeImmutable;

/**
 * §4.5's five ordered conditions (`D-49`), transcribed as a pure function of
 * one customer's deals — `DealStatusTransition`'s shape (Point 2.6), on the
 * same reasoning: the rule belongs in `Domain` because nothing about it
 * needs a database, a clock source beyond what the caller hands in, or a
 * setting beyond the one number the caller already resolved.
 *
 * ── "Won or beyond", read off the state graph rather than restated ─────────
 *
 * §4.5 rule 1 says *"reached Won or beyond (now or historically)"*. Checking
 * a deal's **current** status for membership in `won`/`purchasing`/
 * `delivery`/`delivery_complete` already covers "historically", because
 * {@see \App\Modules\Deals\Domain\Access\DealStatusTransition}'s own graph
 * has no edge leaving any of those four back toward an earlier state — once a
 * deal's current status is one of them, it can never have been true and
 * stopped being true. A deal that reached `Won` and was later marked `Lost`
 * is not a state this graph allows.
 *
 * ── One active-but-stale deal does not decide it for a customer with two ───
 *
 * §4.5 rules 2 and 3 are written as if a customer has one deal. A customer
 * *can* have several concurrent ones (Business Invariants), and the document
 * does not say what happens when one is fresh and another is stale. **Read
 * here as: any fresh active deal keeps the customer a live Prospect**, on the
 * reasoning that "is somebody actively working this customer" is the
 * question both rules are actually asking, and the answer is yes as long as
 * at least one deal says so. Recorded as an interpretation, not a documented
 * rule — owed a `D-xx` if the owner reads it differently.
 *
 * ── The clause this class does not implement ────────────────────────────────
 *
 * Rule 3's second half — *"or a quotation went Expired with no reply"* —
 * needs Module 7, which does not exist yet (`app/Modules/Quotations` is
 * still `.gitkeep`). Not approximated with a guess about what a quotation
 * table might look like: the clause is simply absent, the same way
 * `DealRowScope::Asgn` fails closed for a column Procurement has not built
 * yet. A customer who would trip this clause reads as `Prospect` or
 * `No Response` on the stale-deal test alone until Module 7 exists to widen
 * it — recorded in `CHECKLIST.md`, not hidden here.
 *
 * ── The unset threshold does not invent a number ────────────────────────────
 *
 * `$staleDealDays` is `null` until an administrator sets `D-17`'s limit
 * (`SystemLimit::StaleDealDays` is one of the five the enum's own docblock
 * calls "deliberately unvalued"). `null` here means rule 3's clause never
 * fires — every active deal reads as fresh — the same "the feature does not
 * activate yet" reading already established for `OD-08`'s similarity
 * threshold, not a guessed number standing in for the owner's decision.
 */
final class CustomerStatusDerivation
{
    /** Reachable only through `won` (Point 2.6's graph) — rule 1. */
    private const WON_OR_BEYOND = ['won', 'purchasing', 'delivery', 'delivery_complete'];

    private const LOST = 'lost';

    public const PROSPECT = 'prospect';

    public const CUSTOMER = 'customer';

    public const NO_RESPONSE = 'no_response';

    public const DEAL_NOT_COMPLETED = 'deal_not_completed';

    /**
     * @param  list<DealActivitySnapshot>  $deals  every deal belonging to one customer
     */
    public static function derive(array $deals, DateTimeImmutable $now, ?int $staleDealDays): string
    {
        // Rule 5: registered with no deals at all.
        if ($deals === []) {
            return self::PROSPECT;
        }

        // Rule 1: permanent, and checked before anything else can override it.
        foreach ($deals as $deal) {
            if (in_array($deal->status, self::WON_OR_BEYOND, true)) {
                return self::CUSTOMER;
            }
        }

        $active = array_values(array_filter(
            $deals,
            static fn (DealActivitySnapshot $deal): bool => $deal->status !== self::LOST,
        ));

        // Rule 4: every deal is Lost (rule 1 already ruled out Won+ above).
        if ($active === []) {
            return self::DEAL_NOT_COMPLETED;
        }

        // The threshold is not configured: rule 3's clause cannot fire yet.
        if ($staleDealDays === null) {
            return self::PROSPECT;
        }

        $threshold = $now->modify(sprintf('-%d days', $staleDealDays));

        // Rule 2: at least one active deal is still fresh.
        foreach ($active as $deal) {
            if ($deal->lastActivityAt >= $threshold) {
                return self::PROSPECT;
            }
        }

        // Rule 3: active, but every one of them is stale.
        return self::NO_RESPONSE;
    }
}
