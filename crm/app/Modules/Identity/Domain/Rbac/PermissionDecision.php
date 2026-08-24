<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Rbac;

/**
 * The answer to "may this caller do `resource.action`, and how far does it
 * reach".
 *
 * ── Why it carries a *set* of scopes and not one ───────────────────────────
 *
 * Because {@see Scope::includes()} is deliberately **not** a total order.
 * `Own ⊂ Team ⊂ All` is a real nesting, but `Out` and `Asgn` are different
 * slices of the company and neither contains `Team`. A role can therefore hold
 * `deal.view.team` and `deal.view.asgn` at once, and collapsing that to "the
 * widest one" would have to invent a comparison the documentation does not
 * make — the kind of invention that reads as a helpful default right up until
 * it grants somebody a screen.
 *
 * So the decision keeps everything the role holds and answers questions about
 * it. There is no `widest()`, on purpose.
 */
final readonly class PermissionDecision
{
    /** @param  list<Scope>  $scopes  every scope the role holds on this resource.action */
    private function __construct(
        public bool $granted,
        public array $scopes,
        /** §3.1's Super Admin: granted without consulting a single cell. */
        public bool $unconditional,
    ) {}

    public static function denied(): self
    {
        return new self(false, [], false);
    }

    /** §3.1 — Super Admin. Recorded as `All` so a row check has something to compare. */
    public static function unconditional(): self
    {
        return new self(true, [Scope::All], true);
    }

    /** @param  list<Scope>  $scopes */
    public static function granted(array $scopes): self
    {
        // An empty grant list is a denial, not a grant with no reach. Making
        // that impossible here means no caller has to remember to check both.
        return $scopes === [] ? self::denied() : new self(true, $scopes, false);
    }

    /**
     * `SEC-08` — whether the reach this caller holds covers the reach the row
     * needs.
     *
     * "Any held scope includes the required one", never "the biggest one does":
     * with a partial order those differ, and the second is how `Asgn` quietly
     * stops working for somebody who also holds `Team`.
     */
    public function allows(Scope $required): bool
    {
        if (! $this->granted) {
            return false;
        }

        foreach ($this->scopes as $held) {
            if ($held->includes($required)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the scope values, for a response or an audit row */
    public function scopeValues(): array
    {
        return array_map(static fn (Scope $scope): string => $scope->value, $this->scopes);
    }
}
