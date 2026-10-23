<script setup lang="ts">
/**
 * One quotation — `Design System §5.2`'s Detail view (Module 7, Point 6.5),
 * on `DealDetailView`'s shape: summary first, the lines and the totals, the
 * actions only by permission.
 *
 * ── Every figure is the server's ───────────────────────────────────────────
 *
 * `GET /quotations/{id}` answers the priced quotation (Point 3.4) and this
 * page draws it digit for digit — money stays a string (`DB-07`), the totals
 * are `Design System §7.2`'s groups in the order the engine computes them
 * (`§5.5`): subtotal, additional items, discount before tax (`D-64`), the tax
 * base, the tax, the rounding, the final total. Two rows are conditional on
 * what the server sent and never on what the screen thinks: an exempt
 * quotation has `tax_amount: null` and **no tax row** (`D-63`); a currency
 * that does not round has `rounding_enabled: false` and no rounding row
 * (`D-65`). The cost columns appear only when the body carries them —
 * `QuotationPayload::detail()` strips them for a caller without §3.5's
 * `view cost & margin` (Step 6 Q7), and the SPA makes no decision of its own.
 *
 * ── Actions: hidden when refused, decided by the API ───────────────────────
 *
 * Edit, Submit for approval and Delete belong to a Draft (`§3.5`, `D-46`);
 * a new version opens from the statuses `D-08` names — `partial`, `counter`,
 * `expired` (`QuotationStatusTransition::VERSIONABLE`). The page hides what
 * the API would refuse (§3.12 "hiding a button is not the same as blocking an
 * action") and the API still decides: a `409 concurrency_conflict` on a
 * stale `If-Match` (`API-12`) is a banner asking for a reload, never a silent
 * retry; any other refusal is shown in the server's words.
 *
 * ── What is deliberately not here ──────────────────────────────────────────
 *
 * Send and the customer's response are Module 10 · 3.1: *Send* on an
 * Approved quotation, and the response asked in the page (`Design System
 * §6.6`) — a reason for Counter and Rejected (§6.3), the PO reference and
 * date for Accepted; Partial and Counter open the draft the server copied.
 * An Expired quotation is only rejected, "no response" offered (§10.5). The
 * order an acceptance wrote is drawn by `QuotationPurchaseOrder` (3.3), with
 * its files and their upload; the customer PDF by Module 9's panel (5.2).
 * Approve and return are Module 8.
 * QS.2a (`D-155`): a line owns its supplier, unit, entered price and tax; the
 * payload carries them for a cost viewer only and the unit always. The unit is
 * a `units` code, named from the managed list. No warning rides a read (`D-161`).
 * Edit links to `/quotations/:id/edit`, Point 6.7's builder.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { ApiError } from '@/api';
import QuotationPdfPanel from '@/components/pdf/QuotationPdfPanel.vue';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import ConfirmDialog from '@/components/users/ConfirmDialog.vue';
import DealStatusChip from '@/pages/deals/DealStatusChip.vue';
import DealStatusControl from '@/pages/deals/DealStatusControl.vue';
import QuotationPurchaseOrder from '@/pages/quotations/QuotationPurchaseOrder.vue';
import QuotationStatusChip from '@/pages/quotations/QuotationStatusChip.vue';
import SelfApprovedBadge from '@/pages/quotations/SelfApprovedBadge.vue';
import { entryLabel, listEntries, type ListEntry } from '@/services/admin';
import { readCustomer, type Customer } from '@/services/customers';
import { readDeal, type Deal } from '@/services/deals';
import {
    createQuotationVersion,
    deleteQuotation,
    readQuotation,
    respondToQuotation,
    sendQuotation,
    submitQuotation,
    type CustomerResponse,
    type QuotationDetail,
} from '@/services/quotations';
import { displayDecimals, displayPercent, displayQuantity } from '@/domain/displayDecimals';
import { useAuth } from '@/stores/auth';
import { displayDate, displayTimestamp } from '@/domain/displayDate';

const route = useRoute();
const router = useRouter();
const { t, locale } = useI18n();
const { hasPermission } = useAuth();

const quotation = ref<QuotationDetail | null>(null);
const units = ref<ListEntry[]>([]);
const customer = ref<Customer | null>(null);
/** F-49 · 1.4a: the deal's status and its manual move; `null` when it is not, or cannot be, read. */
const deal = ref<Deal | null>(null);

const loading = ref(true);
const failed = ref(false);
const denied = ref(false);
const missing = ref(false);

const busy = ref(false);
const conflict = ref(false);
const actionError = ref('');
const confirmingDelete = ref(false);

/** The response dialog: open or not, and what is typed in it. */
const responding = ref(false);
const outcome = ref<CustomerResponse['response']>('accepted');
const reason = ref('');
/** `D-147`: a Rejected answer's `lost_reasons` code, chosen from the live list. */
const reasonCode = ref('');
const lostReasons = ref<ListEntry[]>([]);

/** A recorded rejection names its reason by code: its label is read from the same list, once. */
watch(
    () => quotation.value?.rejection_reason_code,
    async (code) => {
        if (code && lostReasons.value.length === 0) {
            try {
                lostReasons.value = (await listEntries('lost_reasons', 1)).items;
            } catch {
                // The code itself is shown instead.
            }
        }
    },
);
const rejectionReasonLabel = computed(() => {
    const code = quotation.value?.rejection_reason_code;
    if (!code) {
        return null;
    }
    const entry = lostReasons.value.find((e) => e.code === code);

    return entry === undefined ? code : entryLabel(entry, locale.value);
});
const poReference = ref('');
const poDate = ref('');
/**
 * `D-108`: the picker offers no day after today. ponytail: the device's day, not
 * `locale.timezone`'s, which the SPA cannot read — a device in another zone may
 * be one day off near midnight, and the server's 422 still holds the rule.
 */
const poDateMax = ref('');
const respondButton = ref<HTMLButtonElement | null>(null);
const dialog = ref<HTMLFormElement | null>(null);
/** 1.5's `deal_lost`, after a rejection only. */
const dealLost = ref<boolean | null>(null);

const id = computed(() => String(route.params.id ?? ''));

/** `D-08`'s statuses, as `QuotationStatusTransition::VERSIONABLE` names them. */
const VERSIONABLE = ['partial', 'counter', 'expired'];

const isDraft = computed(() => quotation.value?.status === 'draft');
const canEdit = computed(() => isDraft.value && hasPermission('quotation.edit'));
const canSubmit = computed(() => isDraft.value && hasPermission('quotation.submit_for_approval'));
const canDelete = computed(() => isDraft.value && hasPermission('quotation.delete'));
const canVersion = computed(() => VERSIONABLE.includes(quotation.value?.status ?? '') && hasPermission('quotation.edit'));
const canSend = computed(() => quotation.value?.status === 'approved' && hasPermission('quotation.send_to_customer'));
const canRespond = computed(() => ['sent', 'expired'].includes(quotation.value?.status ?? '') && hasPermission('quotation.record_customer_response'));
const hasActions = computed(
    () => canEdit.value || canSubmit.value || canDelete.value || canVersion.value || canSend.value || canRespond.value,
);

/** `D-103`: what the PDF prints — a term with no text is left out (the owner, 2026-10-08). */
const printedTerms = computed(() => (quotation.value?.terms ?? []).filter((term) => (term.body ?? '') !== ''));

/** `QuotationStatusTransition`: `sent` takes four answers, `expired` only a rejection (Q8). */
const outcomes = computed<Array<CustomerResponse['response']>>(() =>
    quotation.value?.status === 'expired' ? ['rejected'] : ['accepted', 'partial', 'counter', 'rejected'],
);
/** §6.3 as `D-147` amends it: Counter needs its text; Rejected a code, and its text only for "other". */
const needsReason = computed(() => outcome.value === 'counter' || (outcome.value === 'rejected' && reasonCode.value === 'other'));
const takesReason = computed(() => outcome.value === 'counter' || outcome.value === 'rejected');
/** `RespondQuotationRequest`'s rules, mirrored for the person; the server still decides. */
const canRecord = computed(() =>
    !busy.value
    && (outcome.value === 'accepted'
        ? poReference.value.trim() !== '' && poDate.value !== ''
        : (outcome.value !== 'rejected' || reasonCode.value !== '') && (!needsReason.value || reason.value.trim() !== '')),
);

/** A-22: a greyed Record says what it waits for; busy is said on the page already. */
const recordHint = computed(() => {
    if (busy.value || canRecord.value) {
        return '';
    }

    if (outcome.value === 'accepted') {
        return t('quotations.detail.recordNeedsPo');
    }

    return outcome.value === 'rejected' && reasonCode.value === '' ? t('quotations.detail.recordNeedsReasonCode') : t('quotations.detail.recordNeedsReason');
});

/** Q7: the line carries the cost keys or it does not; the header follows the first line. */
const showsCosts = computed(() => quotation.value?.items[0]?.unit_cost !== undefined);

function text(value: string | null | undefined): string {
    return value === null || value === undefined || value === '' ? '—' : value;
}

/** U-7: two places, more only when the unit needs them (`1.000000` → `1.00`, `0.005000` → `0.005`). */
function roundingUnit(unit: string): string {
    const trimmed = displayQuantity(unit);

    return (trimmed.split('.')[1] ?? '').length > 2 ? trimmed : displayDecimals(unit);
}

/** `D-155`: a line's unit is a code; the list names it, the code stands in until (or unless) the list is read. */
function unitLabel(code: string | null): string {
    const entry = units.value.find((candidate) => candidate.code === code);

    return entry === undefined ? text(code) : entryLabel(entry, locale.value);
}

function percent(value: string | null | undefined): string {
    return value === null || value === undefined ? '—' : `${displayPercent(value)}%`;
}

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    denied.value = false;
    missing.value = false;

    try {
        const result = await readQuotation(id.value);
        quotation.value = result.quotation;
        void listEntries('units', 1).then((page) => { units.value = page.items; }, () => {});
    } catch (error) {
        const status = error instanceof ApiError ? error.status : 0;

        // `OpenAPI §5.1`: a 404 says the record could not be opened and never
        // which of the two reasons applies.
        missing.value = status === 404;
        denied.value = status === 403;
        failed.value = !missing.value && !denied.value;
    } finally {
        loading.value = false;
    }
}

/** Best-effort, exactly as the list's own name lookup is. */
async function loadCustomer(customerId: string): Promise<void> {
    try {
        customer.value = await readCustomer(customerId);
    } catch {
        customer.value = null;
    }
}

/** Best-effort, like the customer; asked only with `deal.view`, so a role without it sees no 403. */
async function loadDeal(dealId: string): Promise<void> {
    if (!hasPermission('deal.view')) {
        return;
    }

    try {
        deal.value = await readDeal(dealId);
    } catch {
        deal.value = null;
    }
}

async function refresh(): Promise<void> {
    conflict.value = false;
    actionError.value = '';
    confirmingDelete.value = false;
    dealLost.value = null;
    responding.value = false;

    await load();

    if (quotation.value !== null) {
        // A response may have moved the deal (D-119), so it is read again with the quotation.
        await Promise.all([loadCustomer(quotation.value.customer_id), loadDeal(quotation.value.deal_id)]);
    }
}

/**
 * One path for every write: a 409 on the etag is the reload banner
 * (`API-12` — the other person's change is read, never overwritten by a
 * retry); anything else is told in the server's words.
 */
async function act(write: () => Promise<void>): Promise<void> {
    busy.value = true;
    conflict.value = false;
    actionError.value = '';

    try {
        await write();
    } catch (error) {
        if (error instanceof ApiError && error.status === 409 && error.code === 'concurrency_conflict') {
            conflict.value = true;
        } else {
            actionError.value = error instanceof Error ? error.message : String(error);
        }
    } finally {
        busy.value = false;
    }
}

async function submit(): Promise<void> {
    if (quotation.value === null) {
        return;
    }

    const current = quotation.value;
    await act(async () => {
        quotation.value = await submitQuotation(current.id, current.etag);
    });
}

async function remove(): Promise<void> {
    if (quotation.value === null) {
        return;
    }

    const current = quotation.value;
    confirmingDelete.value = false;
    await act(async () => {
        await deleteQuotation(current.id, current.etag);
        await router.push({ name: 'quotations' });
    });
}

async function send(): Promise<void> {
    if (quotation.value === null) {
        return;
    }

    const current = quotation.value;
    await act(async () => {
        quotation.value = await sendQuotation(current.id, current.etag);
    });
}

async function openResponse(): Promise<void> {
    outcome.value = outcomes.value[0] ?? 'rejected';
    reason.value = quotation.value?.status === 'expired' ? t('quotations.detail.noResponse') : '';
    // An expired quotation's "no response" is no reason on the list: it is "other", with that note.
    reasonCode.value = quotation.value?.status === 'expired' ? 'other' : '';
    try {
        lostReasons.value = (await listEntries('lost_reasons', 1)).items;
    } catch {
        lostReasons.value = [];
    }
    poReference.value = '';
    poDate.value = '';
    const now = new Date();
    poDateMax.value = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    responding.value = true;
    await nextTick();
    dialog.value?.querySelector<HTMLElement>('input, textarea')?.focus();
}

/** §6.6: Cancel and Escape close without writing, and focus goes back to the button that opened it. */
async function closeResponse(): Promise<void> {
    responding.value = false;
    // The button is `disabled` while the dialog is open; it takes focus only once Vue re-enables it.
    await nextTick();
    respondButton.value?.focus();
}

/** Only the fields the chosen answer takes — the server refuses the others (`prohibited_unless`). */
async function recordResponse(): Promise<void> {
    if (quotation.value === null || !canRecord.value) {
        return;
    }

    const current = quotation.value;
    const body: CustomerResponse =
        outcome.value === 'accepted'
            ? { response: 'accepted', customer_po_reference: poReference.value.trim(), po_date: poDate.value }
            : outcome.value === 'rejected'
              ? { response: 'rejected', reason_code: reasonCode.value, ...(reason.value.trim() === '' ? {} : { reason: reason.value.trim() }) }
              : needsReason.value
                ? { response: outcome.value, reason: reason.value.trim() }
                : { response: outcome.value };

    await act(async () => {
        const answered = await respondToQuotation(current.id, current.etag, body);
        responding.value = false;

        if (answered.new_version !== undefined) {
            // Partial and Counter: the server already copied the quotation (Q7); the copy opens in the builder.
            await router.push({ name: 'quotation-edit', params: { id: answered.new_version.id } });

            return;
        }

        quotation.value = answered;
        dealLost.value = answered.deal_lost ?? null;
    });
}

/** `OpenAPI §9.1`: one key per attempt, minted here; a replay answers the first copy. */
async function newVersion(): Promise<void> {
    if (quotation.value === null) {
        return;
    }

    const current = quotation.value;
    await act(async () => {
        const copy = await createQuotationVersion(current.id, crypto.randomUUID());
        // The copy is a Draft: it opens in the builder (Point 6.7).
        await router.push({ name: 'quotation-edit', params: { id: copy.id } });
    });
}

onMounted(refresh);
</script>

<template>
    <section class="flex flex-col gap-4">
        <LoadingState v-if="loading" label-key="quotations.detail.loading" />
        <PermissionDeniedState v-else-if="denied" />
        <ErrorState v-else-if="failed" @retry="refresh" />

        <!-- One state, one sentence: never which of the two reasons applies. -->
        <p v-else-if="missing" class="form-alert rounded-lg p-3" role="alert" data-testid="quotation-detail-missing">
            {{ t('quotations.detail.missing') }}
        </p>

        <template v-else-if="quotation !== null">
            <header class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-page-title" data-testid="quotation-detail-title">{{ quotation.code }}</h1>
                <p class="flex flex-wrap items-center gap-2">
                    <span class="tabular-nums" data-testid="quotation-detail-version">
                        {{ t('quotations.version', { version: quotation.version }) }}
                    </span>
                    <QuotationStatusChip :status="quotation.status" />
                    <SelfApprovedBadge v-if="quotation.is_self_approved" />
                    <!-- A-56: the final total beside the code; the totals block below stays the full account. -->
                    <span class="whitespace-nowrap font-semibold tabular-nums" data-testid="quotation-detail-header-total"><bdi dir="ltr">{{ displayDecimals(quotation.final_total) }} {{ quotation.currency }}</bdi></span>
                </p>
            </header>

            <!-- `API-12`: somebody else wrote first. Read their version; never overwrite it by retrying. -->
            <div
                v-if="conflict"
                class="form-alert flex flex-wrap items-center justify-between gap-3 rounded-lg p-3"
                role="alert"
                data-testid="quotation-detail-conflict"
            >
                <span>{{ t('quotations.detail.conflict') }}</span>
                <button type="button" class="row-action min-h-11 rounded-lg px-3" @click="refresh">
                    {{ t('quotations.detail.reload') }}
                </button>
            </div>

            <p v-else-if="actionError !== ''" class="form-alert rounded-lg p-3" role="alert" data-testid="quotation-detail-action-error">
                {{ actionError }}
            </p>

            <!-- §5.2: summary first — §6.2's core, terms and tracking groups. -->
            <dl class="detail-grid rounded-xl p-4" data-testid="quotation-detail-summary">
                <div class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('quotations.column.customer') }}</dt>
                    <!-- A-18: a name or «—», never the uuid. -->
                    <dd data-testid="quotation-detail-customer">{{ text(customer?.name) }}</dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('quotations.detail.deal') }}</dt>
                    <dd>
                        <RouterLink
                            :to="{ name: 'deal-detail', params: { id: quotation.deal_id } }"
                            class="row-link"
                            data-testid="quotation-detail-deal"
                        >
                            {{ t('quotations.detail.openDeal') }}
                        </RouterLink>
                        <span v-if="deal !== null" class="mt-1 flex flex-wrap items-center gap-2">
                            <DealStatusChip :status="deal.status" data-testid="quotation-detail-deal-status" />
                            <DealStatusControl :deal="deal" @changed="deal = $event" />
                        </span>
                    </dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('quotations.column.quotationDate') }}</dt>
                    <dd class="tabular-nums">{{ displayDate(quotation.quotation_date, locale) }}</dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('quotations.column.validUntil') }}</dt>
                    <dd class="tabular-nums">{{ displayDate(quotation.valid_until, locale) }}</dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('quotations.filter.currency') }}</dt>
                    <dd>{{ quotation.currency }}</dd>
                </div>
                <div v-if="typeof quotation.default_margin === 'string'" class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('quotations.detail.defaultMargin') }}</dt>
                    <dd class="tabular-nums" data-testid="quotation-detail-default_margin">{{ percent(quotation.default_margin) }}</dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('quotations.detail.submittedAt') }}</dt>
                    <dd class="tabular-nums">{{ displayTimestamp(quotation.submitted_at, locale) }}</dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('quotations.detail.sentAt') }}</dt>
                    <dd class="tabular-nums">{{ displayTimestamp(quotation.sent_at, locale) }}</dd>
                </div>
                <div v-if="quotation.parent_id !== null" class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('quotations.detail.parent') }}</dt>
                    <dd>
                        <RouterLink
                            :to="{ name: 'quotation-detail', params: { id: quotation.parent_id } }"
                            class="row-link"
                            data-testid="quotation-detail-parent"
                        >
                            {{ t('quotations.version', { version: quotation.version - 1 }) }}
                        </RouterLink>
                    </dd>
                </div>
                <!-- Module 8 · 1.2's note: the seller reads why the draft came back. -->
                <div v-if="quotation.return_note !== null" class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('quotations.detail.returnNote') }}</dt>
                    <dd data-testid="quotation-detail-return_note">{{ quotation.return_note }}</dd>
                </div>
                <div v-if="rejectionReasonLabel !== null" class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('quotations.detail.rejectionReasonCode') }}</dt>
                    <dd data-testid="quotation-detail-rejection_reason_code">{{ rejectionReasonLabel }}</dd>
                </div>
                <div v-if="quotation.rejection_reason !== null" class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('quotations.detail.rejectionReason') }}</dt>
                    <dd data-testid="quotation-detail-rejection_reason">{{ quotation.rejection_reason }}</dd>
                </div>
                <!-- D-103: the terms numbered as the PDF prints them — an empty one left out,
                     an unnamed ready term under the PDF's own label. -->
                <div class="col-span-full flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('quotations.terms.heading') }}</dt>
                    <dd>
                        <ol v-if="printedTerms.length > 0" class="list-decimal ps-5" data-testid="quotation-detail-terms">
                            <li v-for="(term, i) in printedTerms" :key="i" data-testid="quotation-detail-term">
                                {{ t('quotations.terms.line', { name: term.title ?? t(`quotations.terms.labels.${term.key}`), body: term.body }) }}
                            </li>
                        </ol>
                        <span v-else data-testid="quotation-detail-terms-none">—</span>
                    </dd>
                </div>
            </dl>

            <!-- §5.2: "action controls only by permission". A-33: the status's one next
                 step (Submit, Send or Respond) is the primary; Delete is row Danger
                 text, and the filled Danger is the confirm's. -->
            <div v-if="hasActions" class="flex flex-wrap items-center gap-2" data-testid="quotation-detail-actions">
                <RouterLink
                    v-if="canEdit"
                    :to="`/quotations/${quotation.id}/edit`"
                    class="row-action inline-flex min-h-11 items-center rounded-lg px-3"
                    data-testid="quotation-action-edit"
                >
                    {{ t('quotations.detail.edit') }}
                </RouterLink>
                <button
                    v-if="canSubmit"
                    type="button"
                    class="create-action min-h-11 rounded-lg px-3 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="busy"
                    data-testid="quotation-action-submit"
                    @click="submit"
                >
                    {{ t('quotations.detail.submit') }}
                </button>
                <button
                    v-if="canVersion"
                    type="button"
                    class="row-action min-h-11 rounded-lg px-3 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="busy"
                    data-testid="quotation-action-new-version"
                    @click="newVersion"
                >
                    {{ t('quotations.detail.newVersion') }}
                </button>
                <button
                    v-if="canSend"
                    type="button"
                    class="create-action min-h-11 rounded-lg px-3 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="busy"
                    data-testid="quotation-action-send"
                    @click="send"
                >
                    {{ t('quotations.detail.send') }}
                </button>
                <button
                    v-if="canRespond"
                    ref="respondButton"
                    type="button"
                    class="create-action min-h-11 rounded-lg px-3 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="busy || responding"
                    data-testid="quotation-action-respond"
                    @click="openResponse"
                >
                    {{ t('quotations.detail.respond') }}
                </button>
                <button
                    v-if="canDelete"
                    type="button"
                    class="danger-text min-h-11 rounded-lg px-3 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="busy"
                    data-testid="quotation-action-delete"
                    @click="confirmingDelete = true"
                >
                    {{ t('quotations.detail.delete') }}
                </button>
            </div>

            <!-- `D-163`: the rule as it stands, said where the button is. -->
            <p v-if="canSubmit" class="text-[var(--color-text-muted)]" data-testid="quotation-detail-submit-note">{{ t('quotations.detail.submitNote') }}</p>

            <!-- `Design System §6.6`: the consequence, never a native confirm(); A-22 (Option B): in a modal dialog. -->
            <ConfirmDialog
                :open="confirmingDelete"
                title-key="quotations.detail.delete"
                message-key="quotations.detail.deletePrompt"
                confirm-key="quotations.detail.deleteProceed"
                :subject="quotation.code"
                :busy="busy"
                danger
                @confirm="remove"
                @cancel="confirmingDelete = false"
            />

            <!-- `Design System §6.6`: the answer is asked in the page; Escape closes it without writing. -->
            <form
                v-if="responding"
                ref="dialog"
                class="form-alert flex flex-col gap-3 rounded-lg p-3"
                role="alertdialog"
                :aria-label="t('quotations.detail.respond')"
                data-testid="quotation-respond-dialog"
                @submit.prevent="recordResponse"
                @keydown.escape.prevent="closeResponse"
            >
                <fieldset class="flex flex-wrap gap-x-4 gap-y-2">
                    <legend class="mb-1">{{ t('quotations.detail.respondOutcome') }}</legend>
                    <label v-for="option in outcomes" :key="option" class="inline-flex min-h-11 items-center gap-2">
                        <input
                            v-model="outcome"
                            type="radio"
                            name="quotation-response"
                            :value="option"
                            :data-testid="`quotation-respond-outcome-${option}`"
                        />
                        {{ t(`quotations.status.${option}`) }}
                    </label>
                </fieldset>
                <label v-if="outcome === 'rejected'" class="flex flex-col gap-1">
                    <span>{{ t('quotations.detail.respondReasonCode') }}</span>
                    <select v-model="reasonCode" required class="form-field min-h-11 rounded-lg px-3" data-testid="quotation-respond-reason-code">
                        <option value="" disabled>{{ t('quotations.detail.respondReasonCodePick') }}</option>
                        <option v-for="entry in lostReasons" :key="entry.code" :value="entry.code">{{ entryLabel(entry, locale) }}</option>
                    </select>
                </label>
                <label v-if="takesReason" class="flex flex-col gap-1">
                    <span>{{ outcome === 'rejected' && !needsReason ? t('quotations.detail.respondReasonOptional') : t('quotations.detail.respondReason') }}</span>
                    <textarea
                        v-model="reason"
                        :required="needsReason"
                        rows="3"
                        maxlength="2000"
                        :placeholder="t('quotations.detail.respondReasonHint')"
                        class="form-field rounded-lg p-3"
                        data-testid="quotation-respond-reason"
                    ></textarea>
                </label>
                <div v-if="outcome === 'accepted'" class="flex flex-wrap gap-3">
                    <label class="flex flex-col gap-1">
                        <span>{{ t('quotations.detail.poReference') }}</span>
                        <input
                            v-model="poReference"
                            type="text"
                            required
                            maxlength="255"
                            :placeholder="t('quotations.detail.poReferenceHint')"
                            class="form-field min-h-11 rounded-lg px-3 py-2"
                            data-testid="quotation-respond-po-reference"
                        />
                    </label>
                    <label class="flex flex-col gap-1">
                        <span>{{ t('quotations.detail.poDate') }}</span>
                        <input
                            v-model="poDate"
                            type="date"
                            required
                            :max="poDateMax"
                            class="form-field min-h-11 rounded-lg px-3 py-2"
                            data-testid="quotation-respond-po-date"
                        />
                    </label>
                </div>
                <p v-if="recordHint !== ''" id="quotation-respond-hint" class="text-[var(--color-text-muted)]" data-testid="quotation-respond-hint">
                    {{ recordHint }}
                </p>
                <span class="flex gap-2">
                    <button
                        type="submit"
                        class="row-action min-h-11 rounded-lg px-3 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="!canRecord"
                        :aria-describedby="recordHint === '' ? undefined : 'quotation-respond-hint'"
                        data-testid="quotation-respond-record"
                    >
                        {{ t('quotations.detail.respondRecord') }}
                    </button>
                    <button type="button" class="row-action min-h-11 rounded-lg px-3" data-testid="quotation-respond-cancel" @click="closeResponse">
                        {{ t('action.cancel') }}
                    </button>
                </span>
            </form>

            <!-- 1.5's `deal_lost` (rule b): whether the rejection moved the deal. -->
            <p v-if="dealLost !== null" class="rounded-lg p-3" role="status" data-testid="quotation-detail-deal-outcome">
                {{ dealLost ? t('quotations.detail.dealLost') : t('quotations.detail.dealKept') }}
            </p>

            <!-- §4.6: the order `accepted` wrote, keyed so a reload of another quotation re-reads its files. -->
            <QuotationPurchaseOrder v-if="quotation.purchase_order" :key="quotation.purchase_order.id" :order="quotation.purchase_order" />

            <!-- Module 9 · 5.2: the customer PDF. Keyed on the status, because it reads on mount and a send queues one (Q14). -->
            <QuotationPdfPanel :key="quotation.status" :quotation-id="quotation.id" :quotation-code="quotation.code" />

            <!-- §7.2: the lines, with the cost group only when the body carries it. -->
            <section class="flex flex-col gap-2" data-testid="quotation-lines">
                <h2 class="text-card-title">{{ t('quotations.detail.lines') }}</h2>
                <div class="table-frame overflow-x-auto rounded-xl">
                    <table class="card-table w-full text-table">
                        <thead>
                            <tr class="table-head">
                                <th scope="col" class="p-3 text-start">#</th>
                                <th v-if="showsCosts" scope="col" class="p-3 text-start">{{ t('quotations.detail.supplier') }}</th>
                                <th scope="col" class="p-3 text-start">{{ t('quotations.detail.product') }}</th>
                                <th scope="col" class="p-3 text-start">{{ t('quotations.detail.unit') }}</th>
                                <th scope="col" class="p-3 text-end">{{ t('quotations.detail.quantity') }}</th>
                                <th v-if="showsCosts" scope="col" class="p-3 text-end">{{ t('quotations.detail.enteredPrice') }}</th>
                                <th scope="col" class="p-3 text-end">{{ t('quotations.detail.taxRate') }}</th>
                                <th v-if="showsCosts" scope="col" class="p-3 text-end">{{ t('quotations.detail.unitCost') }}</th>
                                <th v-if="showsCosts" scope="col" class="p-3 text-end">{{ t('quotations.detail.margin') }}</th>
                                <th scope="col" class="p-3 text-end">{{ t('quotations.detail.unitPrice') }}</th>
                                <th scope="col" class="p-3 text-end">{{ t('quotations.detail.lineTotal') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="line in quotation.items" :key="line.id" class="table-row">
                                <td data-label="#" class="p-3 tabular-nums">{{ line.line_no }}</td>
                                <td v-if="showsCosts" :data-label="t('quotations.detail.supplier')" class="p-3" data-testid="quotation-line-supplier">{{ text(line.supplier_name) }}</td>
                                <td :data-label="t('quotations.detail.product')" class="p-3" data-testid="quotation-line-product">{{ text(line.product_name) }}</td>
                                <td :data-label="t('quotations.detail.unit')" class="p-3" data-testid="quotation-line-unit">{{ unitLabel(line.unit_code) }}</td>
                                <td :data-label="t('quotations.detail.quantity')" class="p-3 text-end tabular-nums" data-testid="quotation-line-quantity">{{ displayQuantity(line.quantity) }}</td>
                                <td v-if="showsCosts" :data-label="t('quotations.detail.enteredPrice')" class="p-3 text-end tabular-nums" data-testid="quotation-line-entered_unit_price"><bdi dir="ltr">{{ displayDecimals(line.entered_unit_price) }} {{ quotation.currency }}</bdi></td>
                                <td :data-label="t('quotations.detail.taxRate')" class="p-3 text-end tabular-nums" data-testid="quotation-line-tax_percent"><bdi dir="ltr">{{ percent(line.tax_percent) }}</bdi></td>
                                <td :data-label="t('quotations.detail.unitCost')" v-if="showsCosts" class="p-3 text-end tabular-nums" data-testid="quotation-line-unit_cost"><bdi dir="ltr">{{ displayDecimals(line.unit_cost_base) }} {{ quotation.currency }}</bdi></td>
                                <td :data-label="t('quotations.detail.margin')" v-if="showsCosts" class="p-3 text-end tabular-nums" data-testid="quotation-line-effective_margin_percent"><bdi dir="ltr">{{ percent(line.effective_margin_percent) }}</bdi></td>
                                <td :data-label="t('quotations.detail.unitPrice')" class="p-3 text-end tabular-nums" data-testid="quotation-line-unit_price">{{ displayDecimals(line.unit_price) }}</td>
                                <td :data-label="t('quotations.detail.lineTotal')" class="p-3 text-end tabular-nums">{{ displayDecimals(line.line_total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- `D-62`: never taxed — their own group, after the lines. -->
            <section v-if="quotation.additional_items.length > 0" class="flex flex-col gap-2">
                <h2 class="text-card-title">{{ t('quotations.detail.additionalItems') }}</h2>
                <div class="table-frame overflow-x-auto rounded-xl">
                    <table class="card-table w-full text-table">
                        <thead>
                            <tr class="table-head">
                                <th scope="col" class="p-3 text-start">{{ t('quotations.detail.description') }}</th>
                                <th scope="col" class="p-3 text-end">{{ t('quotations.detail.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in quotation.additional_items" :key="item.id" class="table-row">
                                <td :data-label="t('quotations.detail.description')" class="p-3" data-testid="quotation-additional-description">{{ item.description }}</td>
                                <td :data-label="t('quotations.detail.amount')" class="p-3 text-end tabular-nums" data-testid="quotation-additional-amount">{{ displayDecimals(item.amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- §7.2's totals block, in the engine's order (§5.5). Money and currency visibly paired (§6.3),
                 one left-to-right run so Arabic keeps a minus before its digits (U-6, A-26). -->
            <dl class="totals rounded-xl p-4" data-testid="quotation-detail-totals">
                <dt>{{ t('quotations.detail.subtotal') }}</dt>
                <dd class="whitespace-nowrap tabular-nums" data-testid="quotation-total-subtotal"><bdi dir="ltr">{{ displayDecimals(quotation.subtotal) }} {{ quotation.currency }}</bdi></dd>

                <dt>{{ t('quotations.detail.discount', { percent: displayPercent(quotation.discount_percent) }) }}</dt>
                <dd class="whitespace-nowrap tabular-nums" data-testid="quotation-total-discount_amount"><bdi dir="ltr">{{ displayDecimals(quotation.discount_amount) }} {{ quotation.currency }}</bdi></dd>

                <!-- `D-63`: an exempt quotation renders no tax line at all. -->
                <template v-if="quotation.tax_amount !== null">
                    <dt>{{ t('quotations.detail.taxBase') }}</dt>
                    <dd class="whitespace-nowrap tabular-nums" data-testid="quotation-total-tax_base"><bdi dir="ltr">{{ displayDecimals(quotation.tax_base) }} {{ quotation.currency }}</bdi></dd>
                    <dt>{{ quotation.tax_percent === null ? t('quotations.detail.taxByLine') : t('quotations.detail.tax', { percent: displayPercent(quotation.tax_percent) }) }}</dt>
                    <dd class="whitespace-nowrap tabular-nums" data-testid="quotation-total-tax_amount"><bdi dir="ltr">{{ displayDecimals(quotation.tax_amount) }} {{ quotation.currency }}</bdi></dd>
                </template>

                <dt>{{ t('quotations.detail.additionalTotal') }}</dt>
                <dd class="whitespace-nowrap tabular-nums" data-testid="quotation-total-additional_total"><bdi dir="ltr">{{ displayDecimals(quotation.additional_total) }} {{ quotation.currency }}</bdi></dd>

                <!-- `D-65`: rounding is per currency; off means no row, not a zero. -->
                <template v-if="quotation.rounding_enabled">
                    <dt>{{ t('quotations.detail.rounding', { unit: roundingUnit(quotation.rounding_unit) }) }}</dt>
                    <dd class="whitespace-nowrap tabular-nums" data-testid="quotation-total-rounding_diff"><bdi dir="ltr">{{ displayDecimals(quotation.rounding_diff) }} {{ quotation.currency }}</bdi></dd>
                </template>

                <dt class="totals-final">{{ t('quotations.detail.finalTotal') }}</dt>
                <dd class="totals-final whitespace-nowrap tabular-nums" data-testid="quotation-total-final_total"><bdi dir="ltr">{{ displayDecimals(quotation.final_total) }} {{ quotation.currency }}</bdi></dd>
            </dl>
        </template>
    </section>
</template>

<style scoped>
.detail-grid {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
    background-color: var(--color-surface);
    border: 1px solid var(--color-border);
}

.totals {
    display: grid;
    gap: 0.5rem 1.5rem;
    grid-template-columns: max-content 1fr;
    max-width: 32rem;
    background-color: var(--color-surface);
    border: 1px solid var(--color-border);
}

.totals dd {
    text-align: end;
}

.totals-final {
    border-block-start: 1px solid var(--color-border-strong);
    padding-block-start: 0.5rem;
    font-weight: 600;
}

.table-frame {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border);
}

.table-head {
    background-color: var(--color-surface-muted);
    color: var(--color-text-muted);
}

.table-row {
    border-block-start: 1px solid var(--color-border);
}

.form-alert {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-border-strong);
}

/* D-108: the response form's inputs read as fields — the three lines every form declares
   (`.form-field` is scoped per component; a shared rule is a debt row). */
.form-field {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}
.row-link {
    color: var(--color-primary);
    text-decoration: underline;
}
</style>
