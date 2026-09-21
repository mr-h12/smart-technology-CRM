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
 * ── Warnings are the server's words ────────────────────────────────────────
 *
 * `meta.warnings` on a read is `supplier_price_changed` (Point 4.5, `D-36`):
 * a red line, "review pricing", never a block. The text is the server's
 * `message`, already in the caller's language.
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
 * Approve, return, send, PDF and the customer's response are Modules 8–10.
 * The supplier behind a line is not named: the line carries
 * `supplier_quotation_item_id` and this page reads nothing of Module 6's.
 * Edit links to `/quotations/:id/edit`, Point 6.7's builder.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { ApiError } from '@/api';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import QuotationStatusChip from '@/pages/quotations/QuotationStatusChip.vue';
import SelfApprovedBadge from '@/pages/quotations/SelfApprovedBadge.vue';
import { readCustomer, type Customer } from '@/services/customers';
import {
    createQuotationVersion,
    deleteQuotation,
    readQuotation,
    submitQuotation,
    type QuotationDetail,
    type QuotationWarning,
} from '@/services/quotations';
import { displayDecimals } from '@/domain/displayDecimals';
import { useAuth } from '@/stores/auth';

const route = useRoute();
const router = useRouter();
const { t, locale } = useI18n();
const { hasPermission } = useAuth();

const quotation = ref<QuotationDetail | null>(null);
const warnings = ref<QuotationWarning[]>([]);
const customer = ref<Customer | null>(null);

const loading = ref(true);
const failed = ref(false);
const denied = ref(false);
const missing = ref(false);

const busy = ref(false);
const conflict = ref(false);
const actionError = ref('');
const confirmingDelete = ref(false);

const id = computed(() => String(route.params.id ?? ''));

/** `D-08`'s statuses, as `QuotationStatusTransition::VERSIONABLE` names them. */
const VERSIONABLE = ['partial', 'counter', 'expired'];

const isDraft = computed(() => quotation.value?.status === 'draft');
const canEdit = computed(() => isDraft.value && hasPermission('quotation.edit'));
const canSubmit = computed(() => isDraft.value && hasPermission('quotation.submit_for_approval'));
const canDelete = computed(() => isDraft.value && hasPermission('quotation.delete'));
const canVersion = computed(() => VERSIONABLE.includes(quotation.value?.status ?? '') && hasPermission('quotation.edit'));
const hasActions = computed(() => canEdit.value || canSubmit.value || canDelete.value || canVersion.value);

/** Q7: the line carries the cost keys or it does not; the header follows the first line. */
const showsCosts = computed(() => quotation.value?.items[0]?.unit_cost !== undefined);

/** `DB-08`: a date-only field is shown as stored; a moment in the reader's locale. */
function onDate(value: string | null): string {
    return value === null ? '—' : new Date(value).toLocaleDateString(locale.value === 'ar' ? 'ar-EG' : 'en-GB');
}

function onMoment(value: string | null): string {
    return value === null ? '—' : new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-GB');
}

function text(value: string | null | undefined): string {
    return value === null || value === undefined || value === '' ? '—' : value;
}

function percent(value: string | null | undefined): string {
    return value === null || value === undefined ? '—' : `${value}%`;
}

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    denied.value = false;
    missing.value = false;

    try {
        const result = await readQuotation(id.value);
        quotation.value = result.quotation;
        warnings.value = result.warnings;
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

async function refresh(): Promise<void> {
    conflict.value = false;
    actionError.value = '';
    confirmingDelete.value = false;

    await load();

    if (quotation.value !== null) {
        await loadCustomer(quotation.value.customer_id);
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
                </p>
            </header>

            <!-- `D-36`: the red line, the server's words, never a block. -->
            <p
                v-for="warning in warnings"
                :key="warning.field + warning.code"
                class="warning-line rounded-lg p-3"
                role="alert"
                data-testid="quotation-detail-warning"
            >
                {{ warning.message }}
            </p>

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
                    <dd data-testid="quotation-detail-customer">{{ customer?.name ?? quotation.customer_id }}</dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('quotations.detail.deal') }}</dt>
                    <dd>
                        <RouterLink
                            :to="{ name: 'deal-detail', params: { id: quotation.deal_id } }"
                            class="row-link"
                            data-testid="quotation-detail-deal"
                        >
                            {{ quotation.deal_id }}
                        </RouterLink>
                    </dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('quotations.column.quotationDate') }}</dt>
                    <dd class="tabular-nums">{{ onDate(quotation.quotation_date) }}</dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('quotations.column.validUntil') }}</dt>
                    <dd class="tabular-nums">{{ onDate(quotation.valid_until) }}</dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('quotations.filter.currency') }}</dt>
                    <dd>{{ quotation.currency }}</dd>
                </div>
                <div v-if="quotation.default_margin !== undefined" class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('quotations.detail.defaultMargin') }}</dt>
                    <dd class="tabular-nums" data-testid="quotation-detail-default_margin">{{ percent(quotation.default_margin) }}</dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('quotations.detail.submittedAt') }}</dt>
                    <dd class="tabular-nums">{{ onMoment(quotation.submitted_at) }}</dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('quotations.detail.sentAt') }}</dt>
                    <dd class="tabular-nums">{{ onMoment(quotation.sent_at) }}</dd>
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
                <div v-if="quotation.rejection_reason !== null" class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('quotations.detail.rejectionReason') }}</dt>
                    <dd data-testid="quotation-detail-rejection_reason">{{ quotation.rejection_reason }}</dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('quotations.detail.paymentTerms') }}</dt>
                    <dd data-testid="quotation-detail-payment_terms">{{ text(quotation.payment_terms) }}</dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('quotations.detail.warranty') }}</dt>
                    <dd data-testid="quotation-detail-warranty">{{ text(quotation.warranty) }}</dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">
                        {{ t('quotations.detail.deliveryTerms') }}
                        <span v-if="!quotation.show_delivery_terms">{{ t('quotations.detail.notOnPdf') }}</span>
                    </dt>
                    <dd data-testid="quotation-detail-delivery_terms">{{ text(quotation.delivery_terms) }}</dd>
                </div>
            </dl>

            <!-- §5.2: "action controls only by permission". -->
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
                    class="row-action min-h-11 rounded-lg px-3 disabled:cursor-not-allowed disabled:opacity-60"
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
                    v-if="canDelete"
                    type="button"
                    class="row-action min-h-11 rounded-lg px-3 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="busy"
                    data-testid="quotation-action-delete"
                    @click="confirmingDelete = true"
                >
                    {{ t('quotations.detail.delete') }}
                </button>
            </div>

            <!-- `Design System §6.6`: the consequence, in the page, never a native confirm(). -->
            <div
                v-if="confirmingDelete"
                class="form-alert flex flex-wrap items-center justify-between gap-3 rounded-lg p-3"
                role="alertdialog"
                data-testid="quotation-delete-confirm"
            >
                <span>{{ t('quotations.detail.deletePrompt') }}</span>
                <span class="flex gap-2">
                    <button type="button" class="row-action min-h-11 rounded-lg px-3" data-testid="quotation-delete-keep" @click="confirmingDelete = false">
                        {{ t('quotations.detail.deleteKeep') }}
                    </button>
                    <button type="button" class="row-action min-h-11 rounded-lg px-3" data-testid="quotation-delete-proceed" @click="remove">
                        {{ t('quotations.detail.deleteProceed') }}
                    </button>
                </span>
            </div>

            <!-- §7.2: the lines, with the cost group only when the body carries it. -->
            <section class="flex flex-col gap-2" data-testid="quotation-lines">
                <h2 class="text-card-title">{{ t('quotations.detail.lines') }}</h2>
                <div class="table-frame overflow-x-auto rounded-xl">
                    <table class="w-full text-table">
                        <thead>
                            <tr class="table-head">
                                <th scope="col" class="p-3 text-start">#</th>
                                <th scope="col" class="p-3 text-end">{{ t('quotations.detail.quantity') }}</th>
                                <th v-if="showsCosts" scope="col" class="p-3 text-end">{{ t('quotations.detail.unitCost') }}</th>
                                <th v-if="showsCosts" scope="col" class="p-3 text-end">{{ t('quotations.detail.margin') }}</th>
                                <th scope="col" class="p-3 text-end">{{ t('quotations.detail.unitPrice') }}</th>
                                <th scope="col" class="p-3 text-end">{{ t('quotations.detail.lineTotal') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="line in quotation.items" :key="line.id" class="table-row">
                                <td class="p-3 tabular-nums">{{ line.line_no }}</td>
                                <td class="p-3 text-end tabular-nums" data-testid="quotation-line-quantity">{{ displayDecimals(line.quantity) }}</td>
                                <td v-if="showsCosts" class="p-3 text-end tabular-nums" data-testid="quotation-line-unit_cost">{{ text(displayDecimals(line.unit_cost)) }}</td>
                                <td v-if="showsCosts" class="p-3 text-end tabular-nums" data-testid="quotation-line-margin_percent">{{ percent(line.margin_percent) }}</td>
                                <td class="p-3 text-end tabular-nums" data-testid="quotation-line-unit_price">{{ displayDecimals(line.unit_price) }}</td>
                                <td class="p-3 text-end tabular-nums">{{ displayDecimals(line.line_total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- `D-62`: never taxed — their own group, after the lines. -->
            <section v-if="quotation.additional_items.length > 0" class="flex flex-col gap-2">
                <h2 class="text-card-title">{{ t('quotations.detail.additionalItems') }}</h2>
                <div class="table-frame overflow-x-auto rounded-xl">
                    <table class="w-full text-table">
                        <thead>
                            <tr class="table-head">
                                <th scope="col" class="p-3 text-start">{{ t('quotations.detail.description') }}</th>
                                <th scope="col" class="p-3 text-end">{{ t('quotations.detail.amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in quotation.additional_items" :key="item.id" class="table-row">
                                <td class="p-3" data-testid="quotation-additional-description">{{ item.description }}</td>
                                <td class="p-3 text-end tabular-nums" data-testid="quotation-additional-amount">{{ displayDecimals(item.amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- §7.2's totals block, in the engine's order (§5.5). Money and currency visibly paired (§6.3). -->
            <dl class="totals rounded-xl p-4" data-testid="quotation-detail-totals">
                <dt>{{ t('quotations.detail.subtotal') }}</dt>
                <dd class="whitespace-nowrap tabular-nums" data-testid="quotation-total-subtotal">{{ displayDecimals(quotation.subtotal) }} {{ quotation.currency }}</dd>

                <dt>{{ t('quotations.detail.discount', { percent: quotation.discount_percent }) }}</dt>
                <dd class="whitespace-nowrap tabular-nums" data-testid="quotation-total-discount_amount">{{ displayDecimals(quotation.discount_amount) }} {{ quotation.currency }}</dd>

                <!-- `D-63`: an exempt quotation renders no tax line at all. -->
                <template v-if="quotation.tax_amount !== null">
                    <dt>{{ t('quotations.detail.taxBase') }}</dt>
                    <dd class="whitespace-nowrap tabular-nums" data-testid="quotation-total-tax_base">{{ displayDecimals(quotation.tax_base) }} {{ quotation.currency }}</dd>
                    <dt>{{ t('quotations.detail.tax', { percent: quotation.tax_percent }) }}</dt>
                    <dd class="whitespace-nowrap tabular-nums" data-testid="quotation-total-tax_amount">{{ displayDecimals(quotation.tax_amount) }} {{ quotation.currency }}</dd>
                </template>

                <dt>{{ t('quotations.detail.additionalTotal') }}</dt>
                <dd class="whitespace-nowrap tabular-nums" data-testid="quotation-total-additional_total">{{ displayDecimals(quotation.additional_total) }} {{ quotation.currency }}</dd>

                <!-- `D-65`: rounding is per currency; off means no row, not a zero. -->
                <template v-if="quotation.rounding_enabled">
                    <dt>{{ t('quotations.detail.rounding', { unit: displayDecimals(quotation.rounding_unit) }) }}</dt>
                    <dd class="whitespace-nowrap tabular-nums" data-testid="quotation-total-rounding_diff">{{ displayDecimals(quotation.rounding_diff) }} {{ quotation.currency }}</dd>
                </template>

                <dt class="totals-final">{{ t('quotations.detail.finalTotal') }}</dt>
                <dd class="totals-final whitespace-nowrap tabular-nums" data-testid="quotation-total-final_total">{{ displayDecimals(quotation.final_total) }} {{ quotation.currency }}</dd>
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

/* §6.5's yellow badge — `Design System §6.4`'s Warning tone, the word beside it. */
/* `Design System §6.4`: red icon + label; the words carry the meaning, the colour only underlines it. */
.warning-line {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-danger);
    color: var(--color-danger);
}

.form-alert {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-border-strong);
}

.row-link {
    color: var(--color-primary);
    text-decoration: underline;
}

.row-action {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}
</style>
