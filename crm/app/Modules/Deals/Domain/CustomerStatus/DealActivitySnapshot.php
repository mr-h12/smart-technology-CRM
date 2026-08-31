<?php

declare(strict_types=1);

namespace App\Modules\Deals\Domain\CustomerStatus;

use DateTimeImmutable;

/**
 * The two facts §4.5's rule reads off one deal — nothing else about it.
 *
 * Not `DealSummary`: that carries seventeen fields §4.5 never asks about, and
 * a derivation reading it would be one accidental property access away from
 * depending on a fact the rule does not name. This is the narrower shape
 * {@see CustomerStatusDerivation} actually needs.
 */
final readonly class DealActivitySnapshot
{
    public function __construct(
        public string $status,
        public DateTimeImmutable $lastActivityAt,
    ) {}
}
