<?php

declare(strict_types=1);

namespace App\Modules\Deals\Presentation;

use Illuminate\Foundation\Http\FormRequest;
use RuntimeException;

/**
 * The boundary for `PATCH /deals/{deal}/status`.
 *
 * `status` is checked against §4.4's twelve states — not against
 * `DealStatusTransition`'s edges, which need the deal's *current* status and
 * are checked in `ChangeDealStatus` instead. A Form Request answers "is this
 * a real status" (400-shaped as a validation concern); "is this reachable
 * from here" is `409 state_transition_invalid`, `OpenAPI §6.1`'s distinction
 * between a malformed request and one that does not fit the workflow.
 *
 * `reason` is `required` only when `status` is `lost` — §4.4: "Lost | Sales
 * (mandatory reason)" — on `RejectDealRequest`'s `regex:/\S/`-beside-`required`
 * reading of a mandatory reason field.
 */
final class ChangeDealStatusRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:'.implode(',', $this->knownStatuses())],
            'reason' => $this->input('status') === 'lost'
                ? ['required', 'string', 'regex:/\S/']
                : ['prohibited'],
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

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.regex' => (string) __('deals.validation.reason_not_blank'),
            'reason.prohibited' => (string) __('deals.validation.reason_only_on_lost'),
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

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) ? $reason : null;
    }

    /** @return list<string> */
    private function knownStatuses(): array
    {
        // Every status that has an outgoing edge, plus the two terminal ones
        // §4.4 still names: `DealStatusTransition::allowedFrom()` on its own
        // would silently accept a typo that happens to match no known state
        // rather than a real one, because an unknown $from and a genuinely
        // terminal one both answer with an empty list.
        return ['lead', 'contacted', 'waiting_customer_request', 'supplier_rfq',
            'supplier_quotation', 'quotation_sent', 'negotiations', 'won',
            'purchasing', 'delivery', 'delivery_complete', 'lost'];
    }
}
