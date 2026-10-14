<?php

declare(strict_types=1);

namespace App\Modules\Deals\Presentation;

use Illuminate\Foundation\Http\FormRequest;
use RuntimeException;

/**
 * The boundary for `PATCH /deals/{deal}/status`.
 *
 * `D-119`: by hand only `purchasing`, `delivery` and `delivery_complete` are
 * chosen. Every other status is reached on its own — creation, a quotation,
 * the customer's answer, a recorded rejection — through `ChangeDealStatus`
 * directly, never through this request; so a withdrawn status is a 422 here,
 * before §4.4's graph is asked. Whether the edge is reachable from the deal's
 * current status is still `ChangeDealStatus`'s `409 state_transition_invalid`
 * (`OpenAPI §6.1`), and `delivery_complete`'s `D-14` permission its 403.
 *
 * `reason` is `prohibited` on every status: it belonged to the manual `lost`,
 * which `D-119` withdrew.
 */
final class ChangeDealStatusRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:purchasing,delivery,delivery_complete'],
            'reason' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'status' => (string) __('deals.attributes.status'),
            'reason' => (string) __('deals.attributes.lost_reason'),
        ];
    }

    public function status(): string
    {
        $status = $this->validated('status');

        if (! is_string($status)) {
            throw new RuntimeException('The status request validated a non-string status.');
        }

        return $status;
    }
}
