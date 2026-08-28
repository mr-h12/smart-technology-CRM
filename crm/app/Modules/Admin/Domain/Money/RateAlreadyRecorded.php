<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Money;

use RuntimeException;

/**
 * The pair already has a rate at that exact moment — Point 1.2's
 * `fx_rates_pair_moment_unique_alive`.
 *
 * Raised by the repository from the database's own refusal rather than from a
 * read-then-write check, because a check is a race: two administrators saving
 * the same rate within the same millisecond both read "absent" and the second
 * insert is what actually fails. The index is the authority; this is its
 * refusal in a shape the Application layer can answer.
 *
 * It lives in `Domain` and not beside {@see \App\Modules\Admin\Application\Money\CurrencyNotOffered}
 * because `deptrac.layers.yaml` lets Infrastructure reach Domain and never
 * Application — the layer that raises it decides where it may live.
 */
final class RateAlreadyRecorded extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This pair already has a rate effective at that moment.');
    }
}
