<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Status;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Customers\Domain\Access\CustomerRowScope;
use App\Modules\Customers\Domain\Contracts\CustomerDirectoryInterface;
use App\Modules\Customers\Domain\Listing\CustomerNotFound;
use App\Modules\Customers\Domain\Listing\CustomerSummary;
use App\Modules\Customers\Domain\Writing\CustomerDraft;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;

/**
 * `D-123` (F-50 · 4) — a customer's status set by hand, with its reason.
 *
 * It holds until any of the customer's deals next changes status: Deals'
 * `RecomputeCustomerStatus` then clears the reason and derives §4.5 again, and
 * the nightly correction leaves a hand-set status alone.
 */
final readonly class ChangeCustomerStatus
{
    public function __construct(
        private CustomerDirectoryInterface $customers,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /** @param list<string> $heldScopes */
    public function handle(string $customerId, string $status, string $reason, array $heldScopes, string $actorId): CustomerSummary
    {
        $scope = CustomerRowScope::resolve($heldScopes, $actorId);

        return $this->connection->transaction(function () use ($customerId, $status, $reason, $scope, $actorId): CustomerSummary {
            $before = $this->customers->find($customerId, $scope);

            if (! $before instanceof CustomerSummary) {
                throw CustomerNotFound::of($customerId);
            }

            // `D-49`: «عميل» is permanent, so never left by hand.
            if ($before->customerStatus === 'customer') {
                throw ValidationException::withMessages(['status' => [(string) __('customers.validation.status_customer_is_permanent')]]);
            }

            if ($before->customerStatus === $status) {
                throw ValidationException::withMessages(['status' => [(string) __('customers.validation.status_unchanged')]]);
            }

            $after = $this->customers->update($customerId, CustomerDraft::forStatusByHand($status, $reason), $scope, $actorId);

            if (! $after instanceof CustomerSummary) {
                // Unreachable: the same scope found the row one statement ago.
                throw CustomerNotFound::of($customerId);
            }

            $this->audit->record(
                AuditEvent::of('CUSTOMER_STATUS_CHANGED'),
                'customer',
                $customerId,
                ['customer_status' => $before->customerStatus],
                ['customer_status' => $after->customerStatus, 'status_reason' => $after->statusReason],
            );

            return $after;
        });
    }
}
