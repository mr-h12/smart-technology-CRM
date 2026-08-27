<?php

declare(strict_types=1);

namespace App\Modules\Admin\Domain\Contracts;

use App\Modules\Admin\Domain\Settings\SystemSetting;

/**
 * §13 screen 4's settings, read and written as rows.
 *
 * Keyed by `SystemSetting` rather than by string on purpose: the table accepts
 * any key and this interface does not, so nothing below the boundary has to
 * re-check what the boundary already refused.
 */
interface SettingsRepositoryInterface
{
    /**
     * Every known field, with its stored value or null.
     *
     * Null for "never set" rather than an absent key: §13 draws a form, and a
     * form with a missing field is a different screen from one with an empty
     * field.
     *
     * @return array<string, string|null>
     */
    public function all(): array;

    /**
     * Write one field, returning the row's identifier and what was there before.
     *
     * The previous value comes back rather than being fetched again by the
     * caller because `AUD-01` wants the old and the new in one entry, and a
     * second read is a second chance for them to disagree.
     *
     * The **identifier** comes back because `audit_log.entity_id` is a `UUID`
     * column, not a name: an audit entry points at the row that changed, and
     * `company.name` is a key rather than an identity. Measured — passing the
     * key produced `SQLSTATE[22P02] invalid input syntax for type uuid`.
     *
     * @return array{id: string, previous: string|null}
     */
    public function put(SystemSetting $setting, string $value): array;
}
