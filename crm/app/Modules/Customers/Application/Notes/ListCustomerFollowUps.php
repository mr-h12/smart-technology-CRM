<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Notes;

use App\Modules\Customers\Domain\Access\CustomerRowScope;
use App\Modules\Customers\Domain\Contracts\CustomerDirectoryInterface;
use App\Modules\Customers\Domain\Listing\CustomerNotFound;
use App\Modules\Customers\Domain\Listing\CustomerSummary;
use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Reports\Domain\Contracts\CustomerFollowUpsInterface;

/**
 * `D-133` — a customer's «المتابعات»: each submitted daily report's follow-ups on it,
 * read-only, newest first, under `customer.view` at the customer's row scope. Out of
 * reach or unknown is the parent's `404`, as for the customer's notes (`D-122`).
 */
final readonly class ListCustomerFollowUps
{
    public function __construct(
        private CustomerDirectoryInterface $customers,
        private CustomerFollowUpsInterface $followUps,
        private UserFactsInterface $users,
    ) {}

    /**
     * @param  list<string>  $heldScopes  `customer.view`'s, as the middleware reports them
     * @return list<array{note: string, author_name: string|null, followed_up_at: string}>
     *
     * @throws CustomerNotFound when the customer is absent or outside the caller's reach
     */
    public function list(string $customerId, array $heldScopes, string $actorId): array
    {
        if (! $this->customers->find($customerId, CustomerRowScope::resolve($heldScopes, $actorId)) instanceof CustomerSummary) {
            throw CustomerNotFound::of($customerId);
        }

        $rows = $this->followUps->ofCustomer($customerId);
        // A hidden or absent author has no name to show (`UserFactsInterface::namesOf`).
        $names = $this->users->namesOf(array_values(array_unique(array_column($rows, 'author_id'))));

        return array_map(static fn (array $row): array => [
            'note' => $row['note'],
            'author_name' => $names[$row['author_id']] ?? null,
            'followed_up_at' => $row['followed_up_at'],
        ], $rows);
    }
}
