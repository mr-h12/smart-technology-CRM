<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

/**
 * The request attribute the guard leaves the resolved session's id in.
 *
 * A named constant in Domain because two layers need it and neither may see the
 * other: the guard adapter lives in Infrastructure, the logout endpoint in
 * Presentation, and `deptrac` allows no edge between them. A duplicated string
 * literal is how the two quietly stop agreeing.
 */
final class SessionAttribute
{
    public const NAME = 'identity.session_id';

    /**
     * `SEC-10` — set only when the resolved session is an impersonation, and
     * holding the **Super Admin's** id, not the impersonated account's.
     *
     * Absent rather than null on an ordinary session: "is this attribute here"
     * is then the whole question, and there is no second state to get wrong.
     */
    public const IMPERSONATOR = 'identity.impersonator_id';
}
