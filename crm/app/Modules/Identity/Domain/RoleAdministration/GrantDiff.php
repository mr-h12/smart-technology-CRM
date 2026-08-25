<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\RoleAdministration;

/**
 * What one `PATCH /roles/{id}/permissions` actually changed, as triples.
 *
 * The endpoint takes a *desired set*, so "what changed" is not in the request
 * body — it is the difference between the body and the rows already there, and
 * only the store can compute it. `AUD-02` wants old and new values; this is the
 * part of the new value that an auditor reads first.
 */
final readonly class GrantDiff
{
    /**
     * @param  list<string>  $granted  triples that did not hold and now do
     * @param  list<string>  $revoked  triples that held and now do not
     */
    public function __construct(
        public array $granted,
        public array $revoked,
    ) {}

    public static function none(): self
    {
        return new self([], []);
    }

    /**
     * True when the submitted set was already the stored set.
     *
     * Used to decide whether an audit row is written at all. `AUD-03` makes
     * rows permanent, and a log that records a click rather than a change is a
     * log that has to be filtered before it can be read — the same reasoning
     * {@see \App\Modules\Identity\Application\Administration\SetUserActivation}
     * applies to an idempotent deactivation.
     */
    public function isEmpty(): bool
    {
        return $this->granted === [] && $this->revoked === [];
    }
}
