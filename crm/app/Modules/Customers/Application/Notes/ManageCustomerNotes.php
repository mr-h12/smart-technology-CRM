<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Notes;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Customers\Domain\Access\CustomerRowScope;
use App\Modules\Customers\Domain\Contracts\CustomerDirectoryInterface;
use App\Modules\Customers\Domain\Contracts\CustomerNotesInterface;
use App\Modules\Customers\Domain\Listing\CustomerListCriteria;
use App\Modules\Customers\Domain\Listing\CustomerNotFound;
use App\Modules\Customers\Domain\Listing\CustomerPage;
use App\Modules\Customers\Domain\Listing\CustomerSummary;
use App\Modules\Customers\Domain\Notes\CustomerNote;
use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * `D-122` — a customer's notes: read under `customer.view`, written under
 * `customer.edit`, each at the customer's own row scope (§3.3).
 *
 * The customer is reached first, through the same scoped `find()` the
 * customer's own routes use, so a customer out of reach and a note that is
 * not this customer's are one answer: the parent's `404` (`OpenAPI §5.1`,
 * as `D-117` answers for a deal's documents). Any holder of `customer.edit`
 * in reach may change any note, not only its author (`D-122`).
 */
final readonly class ManageCustomerNotes
{
    public function __construct(
        private CustomerDirectoryInterface $customers,
        private CustomerNotesInterface $notes,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
        private UserFactsInterface $users,
    ) {}

    /**
     * @param  list<string>  $heldScopes  `customer.view`'s, as the middleware reports them
     * @return CustomerPage<CustomerNote>
     */
    public function list(string $customerId, CustomerListCriteria $criteria, array $heldScopes, string $actorId): CustomerPage
    {
        $this->reach($customerId, $heldScopes, $actorId);

        return $this->notes->list($customerId, $criteria);
    }

    /** @param  list<string>  $heldScopes  `customer.edit`'s */
    public function add(string $customerId, string $body, ?string $noteDate, array $heldScopes, string $actorId): CustomerNote
    {
        return $this->connection->transaction(function () use ($customerId, $body, $noteDate, $heldScopes, $actorId): CustomerNote {
            $this->reach($customerId, $heldScopes, $actorId);

            return $this->record($customerId, $body, $noteDate, $actorId);
        });
    }

    /**
     * Writes and audits one note on a customer the caller has already reached —
     * the route above, and the importer for a row's `notes` cell (`D-122`, the
     * owner's ruling (a)). Inside the caller's transaction (`DB-11`).
     */
    public function record(string $customerId, string $body, ?string $noteDate, string $actorId): CustomerNote
    {
        $note = $this->notes->add($customerId, $body, $noteDate, $actorId);

        $this->audit->record(
            AuditEvent::of('CUSTOMER_NOTE_ADDED'),
            'customer_note',
            $note->id,
            null,
            ['customer_id' => $customerId, 'body' => $body, 'note_date' => $noteDate],
        );

        return $note;
    }

    /**
     * @param  array{body: string, note_date?: string|null}  $changes  an absent `note_date` keeps the stored one
     * @param  list<string>  $heldScopes  `customer.edit`'s
     */
    public function update(string $customerId, string $noteId, array $changes, array $heldScopes, string $actorId): CustomerNote
    {
        return $this->connection->transaction(function () use ($customerId, $noteId, $changes, $heldScopes, $actorId): CustomerNote {
            $before = $this->reachNote($customerId, $noteId, $heldScopes, $actorId);
            $noteDate = array_key_exists('note_date', $changes) ? $changes['note_date'] : $before->noteDate;

            $after = $this->notes->update($customerId, $noteId, $changes['body'], $noteDate, $actorId)
                ?? throw CustomerNotFound::of($customerId);

            // `AUD-02`: the values this write replaced, beside the ones it wrote.
            $this->audit->record(
                AuditEvent::of('CUSTOMER_NOTE_UPDATED'),
                'customer_note',
                $noteId,
                ['body' => $before->body, 'note_date' => $before->noteDate],
                ['body' => $after->body, 'note_date' => $after->noteDate],
            );

            return $after;
        });
    }

    /** @param  list<string>  $heldScopes  `customer.edit`'s */
    public function remove(string $customerId, string $noteId, array $heldScopes, string $actorId): void
    {
        $this->connection->transaction(function () use ($customerId, $noteId, $heldScopes, $actorId): void {
            $before = $this->reachNote($customerId, $noteId, $heldScopes, $actorId);

            $this->notes->remove($customerId, $noteId, $actorId);

            // No new values: the recorder documents them as absent on a delete.
            $this->audit->record(
                AuditEvent::of('CUSTOMER_NOTE_REMOVED'),
                'customer_note',
                $noteId,
                ['customer_id' => $customerId, 'body' => $before->body, 'note_date' => $before->noteDate],
                null,
            );
        });
    }

    /**
     * @param  list<string>  $heldScopes
     *
     * @throws CustomerNotFound when the customer is absent or outside the caller's reach
     */
    private function reach(string $customerId, array $heldScopes, string $actorId): void
    {
        if (! $this->customers->find($customerId, CustomerRowScope::resolve($heldScopes, $actorId, $this->users->teamMemberIdsOf(...))) instanceof CustomerSummary) {
            throw CustomerNotFound::of($customerId);
        }
    }

    /**
     * @param  list<string>  $heldScopes
     *
     * @throws CustomerNotFound for the customer out of reach, and for a note absent, removed or another customer's
     */
    private function reachNote(string $customerId, string $noteId, array $heldScopes, string $actorId): CustomerNote
    {
        $this->reach($customerId, $heldScopes, $actorId);

        return $this->notes->find($customerId, $noteId) ?? throw CustomerNotFound::of($customerId);
    }
}
