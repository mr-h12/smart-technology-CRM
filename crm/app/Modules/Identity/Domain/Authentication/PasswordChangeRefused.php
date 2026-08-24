<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

use RuntimeException;

/**
 * The one way the password-change use case says no.
 *
 * A single exception carrying a {@see PasswordRefusal}, for the reason
 * {@see AuthenticationRefused} is shaped the same way: the status, the field
 * and the message all derive from the reason, so a new refusal is a new enum
 * case and cannot arrive without them.
 *
 * Rendered into `OpenAPI §5`'s envelope in `bootstrap/app.php`, outside
 * `app/Modules` — the module states *what* was refused and never builds an
 * HTTP response to say it.
 */
final class PasswordChangeRefused extends RuntimeException
{
    private function __construct(public readonly PasswordRefusal $reason)
    {
        // For logs and stack traces only. The presented password never appears
        // here — Coding Standards §9 keeps credentials out of anything a log
        // line touches, and this message reaches one.
        parent::__construct('Password change refused: '.$reason->value);
    }

    public static function because(PasswordRefusal $reason): self
    {
        return new self($reason);
    }
}
