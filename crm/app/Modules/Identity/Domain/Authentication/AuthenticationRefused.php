<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

use RuntimeException;

/**
 * The one way this module says no.
 *
 * A single exception carrying a {@see RefusalReason} rather than one class per
 * refusal: the status, the code and the message are all derived from the reason,
 * so a new refusal is a new enum case and cannot arrive without them. It is
 * rendered into `OpenAPI §5`'s envelope in `bootstrap/app.php`, which is
 * outside `app/Modules` — the module therefore states *what* was refused and
 * never builds an HTTP response to say so.
 */
final class AuthenticationRefused extends RuntimeException
{
    private function __construct(public readonly RefusalReason $reason)
    {
        // The message is for logs and stack traces only; the caller is answered
        // from the reason's lang key, in the request's locale.
        parent::__construct('Authentication refused: '.$reason->value);
    }

    public static function because(RefusalReason $reason): self
    {
        return new self($reason);
    }
}
