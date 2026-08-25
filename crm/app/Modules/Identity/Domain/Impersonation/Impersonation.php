<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Impersonation;

use App\Modules\Identity\Domain\Authentication\SessionToken;

/**
 * A started Login As: the token the Super Admin now drives, and the two
 * identities it carries.
 *
 * Both ids are here because every later question needs both — the audit row,
 * the leave endpoint, and the response that tells the SPA whose screens it is
 * about to render. Keeping them together is what stops one of them being
 * inferred later from the other, which is the inference that goes wrong.
 */
final readonly class Impersonation
{
    public function __construct(
        public string $impersonatorId,
        public string $targetId,
        public string $targetName,
        public string $targetRoleSlug,
        public SessionToken $token,
        public string $sessionId,
    ) {}
}
