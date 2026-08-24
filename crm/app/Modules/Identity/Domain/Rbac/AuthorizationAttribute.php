<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Rbac;

/**
 * The request attribute the authorisation middleware leaves its decision in.
 *
 * A named constant in Domain for the reason {@see \App\Modules\Identity\Domain\Authentication\SessionAttribute}
 * is one: the middleware is Presentation, the row-scoping query will be
 * Infrastructure, and `deptrac` allows no edge between them. A duplicated
 * string literal is how two layers quietly stop agreeing.
 *
 * It exists because `SEC-08` needs the *reach* downstream, not just the yes.
 * The middleware has already resolved which scopes the caller holds; making the
 * query resolve them again is two answers to one question.
 */
final class AuthorizationAttribute
{
    public const NAME = 'identity.authorization';
}
