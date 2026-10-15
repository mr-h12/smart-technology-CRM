<?php

declare(strict_types=1);

namespace App\Modules\Customers\Presentation;

use App\Modules\Customers\Domain\Writing\CustomerDraft;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `D-123` (F-50 · 4) — `PATCH /customers/{id}/status {status, reason}`.
 *
 * The target is one of the hand-settable three, so «عميل» is refused here
 * (`D-49`); the rules that read the row (a customer already «عميل», the same
 * status again) are {@see \App\Modules\Customers\Application\Status\ChangeCustomerStatus}'s.
 */
class ChangeCustomerStatusRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(CustomerDraft::SET_BY_HAND)],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'status' => (string) __('customers.attributes.status'),
            'reason' => (string) __('customers.attributes.status_reason'),
        ];
    }

    public function status(): string
    {
        return $this->text('status');
    }

    public function reason(): string
    {
        return $this->text('reason');
    }

    private function text(string $key): string
    {
        $value = $this->validated($key);

        if (! is_string($value)) {
            throw new \RuntimeException("The status request validated a non-string {$key}.");
        }

        return $value;
    }
}
