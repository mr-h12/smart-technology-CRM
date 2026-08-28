<?php

declare(strict_types=1);

namespace App\Modules\Admin\Presentation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The boundary for `POST /api/v1/managed-lists/{list}`.
 *
 * **Both labels are required, and that is `§14.2`.** `ListEntry`'s docblock
 * explains why they are *values* rather than translation keys — a sector added
 * at runtime has no key to resolve — and the consequence is that an entry
 * accepted with one label renders blank in the other language. Point 1.3 made
 * both columns `NOT NULL` for the same reason, having watched `roles.name_ar`
 * take the other road.
 *
 * **`code` is machine-shaped and nothing else.** It is what a foreign key
 * points at and it is never shown: `ListEntry` says so, and `ManagedLists`
 * seeds `government`, `piece`, `installation`. Lower-case ASCII with
 * underscores — no spaces (a URL segment), no capitals (two codes differing
 * only in case are one code to a human), no Arabic (a code is not a label).
 *
 * **`position` starts at 1.** The database's CHECK allows `0` — Point 1.3 chose
 * `position >= 0` because nothing documented a floor — and `ListEntry` documents
 * the value as *"1-based, contiguous within its list"*. The boundary is the
 * stricter of the two on purpose: a zero would sort ahead of `government`
 * without anybody having asked for it.
 *
 * Authorisation is on the route (`permission:admin.system_settings`), §3.12
 * rule 1.
 */
final class AddListEntryRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'label_en' => ['required', 'string', 'max:128'],
            'label_ar' => ['required', 'string', 'max:128'],
            'position' => ['required', 'integer', 'min:1'],
        ];
    }
}
