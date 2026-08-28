<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Contracts;

use App\Modules\Admin\Domain\Settings\SystemLimit;

/**
 * §13 screen 6's limits, read and written as rows.
 *
 * Keyed by `SystemLimit` rather than by string, for the reason
 * {@see SettingsRepositoryInterface} gives: the table accepts any key and this
 * interface does not, so nothing below the boundary re-checks what the boundary
 * already refused.
 *
 * A second interface rather than a parameter on the first, because §3.11 makes
 * them two permissions and Point 1.1 made them two tables. One repository with
 * a `$table` argument would put the authorisation-bearing distinction inside a
 * variable.
 */
interface SystemLimitRepositoryInterface
{
    /**
     * Every declared limit, valued or not, with the unit the screen renders.
     *
     * Null for "never set" rather than an absent key: §13 draws a form, and
     * five of the six have no documented value — an omitted key would be a
     * field the screen cannot draw.
     *
     * @return array<string, array{value: string|null, unit: string|null, value_type: string}>
     */
    public function all(): array;

    /**
     * Write one limit, returning the row's identifier and what was there before.
     *
     * Both for `SettingsRepositoryInterface::put()`'s reasons: `audit_log.entity_id`
     * is a UUID column and a key is not an identity, and `AUD-01` wants the old
     * and the new in one entry rather than in two reads that may disagree.
     *
     * @return array{id: string, previous: string|null}
     */
    public function put(SystemLimit $limit, string $value): array;
}
