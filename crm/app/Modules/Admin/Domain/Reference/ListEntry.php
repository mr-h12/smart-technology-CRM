<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Reference;

/**
 * One row of a managed list: a stable code, a label in each language, and where
 * it sits in the list.
 *
 * **The labels are values, not translation keys, and that is the whole design.**
 * `CLAUDE.md` forbids hard-coded user-facing strings and the usual answer is a
 * key resolved from a lang file. That answer cannot work for a list whose
 * membership is editable: `design/DATABASE.md` promises that "adding a sector
 * must appear in the customer form **without a deployment**", and a sector
 * added at runtime has no key. Putting one there would move its label back into
 * a file that has to be deployed — the requirement, inverted.
 *
 * So both labels travel as data, bound for `label_en` and `label_ar` columns,
 * and the screen renders the row rather than looking anything up. The seeded
 * entries are the starting set; §4.2 and §7.3 both write "(extendable)".
 *
 * The `position` is data for the same reason. Without it the order on screen is
 * whatever the query planner returns, which is not an order anybody chose.
 */
final readonly class ListEntry
{
    public function __construct(
        private string $code,
        private string $labelEn,
        private string $labelAr,
        private int $position,
    ) {}

    /** Stable and machine-shaped. What a foreign key points at, never shown. */
    public function code(): string
    {
        return $this->code;
    }

    public function labelEn(): string
    {
        return $this->labelEn;
    }

    public function labelAr(): string
    {
        return $this->labelAr;
    }

    /** 1-based, contiguous within its list. */
    public function position(): int
    {
        return $this->position;
    }
}
