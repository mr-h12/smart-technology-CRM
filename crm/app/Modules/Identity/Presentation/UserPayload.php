<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Domain\Administration\AdministeredUser;
use App\Modules\Identity\Domain\Administration\UserPage;

/**
 * How a user appears on the wire.
 *
 * ── What is deliberately absent ────────────────────────────────────────────
 *
 * `password`, obviously — {@see AdministeredUser} has never held one. And
 * `is_hidden`: §3.12 rule 6 means a hidden account never reaches this class,
 * so the field would be the constant `false` on every response, and a constant
 * that describes a security rule is an invitation to filter on it.
 *
 * ── What is present, and why ───────────────────────────────────────────────
 *
 * `OpenAPI §8.2`: "Represent direct relationships with explicit ID fields", so
 * `role_id` is there; the slug and name ride alongside because every screen
 * that lists people shows the role and the alternative is an N+1 of detail
 * calls. `is_active` because §8.2 requires inactive status to be "explicit when
 * a caller is permitted to view it". Timestamps in ISO-8601 UTC (`DB-08`).
 */
final class UserPayload
{
    /** @return array<string, mixed> */
    public static function of(AdministeredUser $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => $user->roleId,
            'role' => [
                'slug' => $user->roleSlug,
                'name' => $user->roleName,
            ],
            'is_active' => $user->isActive,
            'created_at' => $user->createdAt->format(DATE_ATOM),
            'updated_at' => $user->updatedAt->format(DATE_ATOM),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function many(UserPage $page): array
    {
        return array_map(self::of(...), $page->items);
    }

    /** @return array{page: int, per_page: int, total: int, total_pages: int, has_next_page: bool, has_previous_page: bool} */
    public static function pagination(UserPage $page): array
    {
        return [
            'page' => $page->page,
            'per_page' => $page->perPage,
            'total' => $page->total,
            'total_pages' => $page->totalPages(),
            'has_next_page' => $page->hasNextPage(),
            'has_previous_page' => $page->hasPreviousPage(),
        ];
    }
}
