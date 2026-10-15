<?php

declare(strict_types=1);

namespace App\Modules\Customers\Presentation;

use App\Modules\Customers\Domain\Listing\CustomerPage;
use App\Modules\Customers\Domain\Notes\CustomerNote;

/** `OpenAPI §7.1`'s note: `{id, body, note_date, created_by, created_at, updated_at}` (`D-122`). */
final class CustomerNotePayload
{
    /** @return array<string, string|null> */
    public static function of(CustomerNote $note): array
    {
        return [
            'id' => $note->id,
            'body' => $note->body,
            'note_date' => $note->noteDate,
            'created_by' => $note->createdBy,
            'created_at' => $note->createdAt->format(DATE_ATOM),
            'updated_at' => $note->updatedAt->format(DATE_ATOM),
        ];
    }

    /**
     * @param  CustomerPage<CustomerNote>  $page
     * @return list<array<string, string|null>>
     */
    public static function many(CustomerPage $page): array
    {
        return array_map(static fn (CustomerNote $note): array => self::of($note), $page->items);
    }
}
