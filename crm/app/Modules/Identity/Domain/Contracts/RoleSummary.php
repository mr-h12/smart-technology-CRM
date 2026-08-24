<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

/**
 * The three fields a use case needs about a role it did not load: its id (to
 * write), its slug (to ask §3.12 rule 7 about) and its name (to serialise).
 *
 * A value object rather than the Eloquent `Role`, for the reason `D-77` exists.
 */
final readonly class RoleSummary
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
    ) {}
}
