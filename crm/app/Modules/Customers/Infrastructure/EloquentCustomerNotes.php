<?php

declare(strict_types=1);

namespace App\Modules\Customers\Infrastructure;

use App\Modules\Customers\Domain\Contracts\CustomerNotesInterface;
use App\Modules\Customers\Domain\Listing\CustomerListCriteria;
use App\Modules\Customers\Domain\Listing\CustomerPage;
use App\Modules\Customers\Domain\Notes\CustomerNote;
use App\Modules\Customers\Infrastructure\Eloquent\CustomerNote as CustomerNoteRow;
use DateTimeImmutable;

final class EloquentCustomerNotes implements CustomerNotesInterface
{
    /** @return CustomerPage<CustomerNote> */
    public function list(string $customerId, CustomerListCriteria $criteria): CustomerPage
    {
        $query = CustomerNoteRow::query()->where('customer_id', $customerId);
        $total = $query->count();

        // `D-122`'s order. PostgreSQL sorts NULL first under DESC, so the
        // undated notes are sent to the end explicitly; `id` (UUIDv7) breaks a tie.
        $rows = $query
            ->orderByRaw('note_date DESC NULLS LAST')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->offset($criteria->offset())
            ->limit($criteria->perPage)
            ->get();

        return new CustomerPage(
            array_values($rows->map(static fn (CustomerNoteRow $row): CustomerNote => self::hydrate($row))->all()),
            $total,
            $criteria->page,
            $criteria->perPage,
        );
    }

    public function find(string $customerId, string $noteId): ?CustomerNote
    {
        $row = $this->row($customerId, $noteId);

        return $row === null ? null : self::hydrate($row);
    }

    public function add(string $customerId, string $body, ?string $noteDate, ?string $actorId): CustomerNote
    {
        $row = new CustomerNoteRow;
        $row->fill(['customer_id' => $customerId, 'body' => $body, 'note_date' => $noteDate]);
        // `DB-02`: the actor comes from the request, as on `customers`.
        $row->created_by = $actorId;
        $row->updated_by = $actorId;
        $row->save();

        return self::hydrate($row->refresh());
    }

    public function update(string $customerId, string $noteId, string $body, ?string $noteDate, string $actorId): ?CustomerNote
    {
        $row = $this->row($customerId, $noteId);

        if ($row === null) {
            return null;
        }

        $row->fill(['body' => $body, 'note_date' => $noteDate]);
        $row->updated_by = $actorId;
        $row->save();

        return self::hydrate($row->refresh());
    }

    public function remove(string $customerId, string $noteId, string $actorId): bool
    {
        $row = $this->row($customerId, $noteId);

        if ($row === null) {
            return false;
        }

        $row->updated_by = $actorId;
        $row->save();
        $row->delete();

        return true;
    }

    /** Keyed by the customer too, so another customer's note is absent here. */
    private function row(string $customerId, string $noteId): ?CustomerNoteRow
    {
        return CustomerNoteRow::query()->where('customer_id', $customerId)->whereKey($noteId)->first();
    }

    private static function hydrate(CustomerNoteRow $row): CustomerNote
    {
        return new CustomerNote(
            id: $row->id,
            customerId: $row->customer_id,
            body: $row->body,
            noteDate: $row->note_date,
            createdBy: $row->created_by,
            createdAt: new DateTimeImmutable((string) $row->created_at?->toIso8601String()),
            updatedAt: new DateTimeImmutable((string) $row->updated_at?->toIso8601String()),
        );
    }
}
