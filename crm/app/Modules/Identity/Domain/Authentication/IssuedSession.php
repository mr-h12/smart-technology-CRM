<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Authentication;

/**
 * What a successful login produces: the person, the session that was opened,
 * and the one and only time the token exists in plaintext.
 *
 * The token is returned and never stored — `user_sessions` keeps its digest
 * (see {@see SessionToken}) — so a caller that loses it has to log in again.
 * That is the property that makes reading the table useless to an attacker.
 */
final readonly class IssuedSession
{
    public function __construct(
        public Account $account,
        public SessionToken $token,
        public string $sessionId,
    ) {}
}
