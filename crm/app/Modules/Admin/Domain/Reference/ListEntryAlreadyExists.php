<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Reference;

use RuntimeException;

/**
 * The list already has an entry with that code — Point 1.3's partial unique
 * index on `(list, code) WHERE deleted_at IS NULL`.
 *
 * Raised by the repository from the database's own refusal rather than from a
 * read-then-write check, because a check is a race: two administrators adding
 * the same sector within the same millisecond both read "absent" and the second
 * insert is what actually fails. The index is the authority.
 *
 * It lives in `Domain` and not in `Application` because `deptrac.layers.yaml`
 * lets Infrastructure reach Domain and never Application — the layer that
 * raises it decides where it may live. The same reasoning as
 * {@see \App\Modules\Admin\Domain\Money\RateAlreadyRecorded}.
 */
final class ListEntryAlreadyExists extends RuntimeException
{
    public function __construct(ManagedList $list, string $code)
    {
        parent::__construct("The {$list->value} list already has an entry coded '{$code}'.");
    }
}
