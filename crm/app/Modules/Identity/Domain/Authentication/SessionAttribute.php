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
}
