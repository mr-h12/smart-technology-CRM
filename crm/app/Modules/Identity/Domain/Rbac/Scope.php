<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Rbac;

/**
 * The `scope` third of `resource.action.scope` (`SEC-07`).
 *
 * §3.2's code table, and nothing else. A sixth value cannot be written, which
 * is what makes a permission checkable rather than a naming convention.
 */
enum Scope: string
{
    /** Their own records only. */
    case Own = 'own';

    /** Their team's records. */
    case Team = 'team';

    /** Every record in the system. */
    case All = 'all';

    /** Outdoor team records only. */
    case Out = 'out';

    /** Deals handed over to them. */
    case Asgn = 'asgn';

    /**
     * Whether this scope covers everything `$other` covers.
     *
     * Deliberately not a total order. `Own ⊂ Team ⊂ All` is a real nesting the
     * document relies on, and `Out` and `Asgn` sit under `All` because it is
     * "every record in the system". But `Out` and `Team` are two different
     * slices of the company, and declaring either to contain the other would
     * invent an authorisation rule the specification does not state — the kind
     * of invention that reads as a helpful default right up until it grants
     * somebody a screen.
     */
    public function includes(self $other): bool
    {
        if ($this === $other) {
            return true;
        }

        return match ($this) {
            self::All => true,
            self::Team => $other === self::Own,
            self::Own, self::Out, self::Asgn => false,
        };
    }
}
