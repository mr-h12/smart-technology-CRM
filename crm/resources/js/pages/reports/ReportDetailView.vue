<script setup lang="ts">
/**
 * Module 13, first slice — one daily report (RPT-DY).
 *
 * The author writes notes (§11.3), reads the day's records grouped by kind
 * (`D-126`; found by the server through the audit log, never showing it, `D-130`), chooses recipients from the server's
 * candidates with its default already chosen (`D-127`, `D-129`), and submits.
 * Once submitted the page shows the frozen copy and no form (§11.4).
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute } from 'vue-router';
import { ApiError } from '@/api';
import EmptyState from '@/components/states/EmptyState.vue';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import { displayDate } from '@/domain/displayDate';
import { displayDecimals } from '@/domain/displayDecimals';
import DealStatusChip from '@/pages/deals/DealStatusChip.vue';
import QuotationStatusChip from '@/pages/quotations/QuotationStatusChip.vue';
import {
    ACTIVITY_COUNTS,
    readRecipientCandidates,
    readReport,
    saveReportNotes,
    submitReport,
    type ActivityCount,
    type RecipientCandidate,
    type ReportDetail,
} from '@/services/reports';

const { t, locale } = useI18n();
const route = useRoute();
const reportId = String(route.params.id);

const report = ref<ReportDetail | null>(null);
const candidates = ref<RecipientCandidate[]>([]);
const chosen = ref<string[]>([]);
const notes = ref('');

const loading = ref(true);
const failed = ref(false);
const denied = ref(false);
const notFound = ref(false);
const busy = ref(false);
const saved = ref(false);
const conflict = ref(false);
const actionFailed = ref<string | null>(null);

/** Each kind's column headings; the cells below follow the same order. */
const COLUMNS: Record<ActivityCount, string[]> = {
    deals_created: ['deals.column.code', 'deals.column.customer', 'deals.column.status'],
    deal_status_moves: ['deals.column.code', 'deals.column.customer', 'reports.column.from', 'reports.column.to'],
    quotations_created: ['quotations.column.code', 'quotations.column.version', 'quotations.column.customer', 'quotations.column.status', 'quotations.column.total'],
    quotations_sent: ['quotations.column.code', 'quotations.column.version', 'quotations.column.customer', 'quotations.column.status', 'quotations.column.total'],
    customer_responses: ['quotations.column.code', 'quotations.column.version', 'quotations.column.customer', 'reports.column.response', 'quotations.column.status', 'quotations.column.total'],
    purchase_orders: ['purchaseOrders.column.poNumber', 'purchaseOrders.column.quotation', 'quotations.column.customer', 'reports.column.po_date', 'quotations.column.total'],
};

const hasActivity = computed(() => report.value !== null && ACTIVITY_COUNTS.some((key) => report.value!.activity.sections[key].length > 0));
const isDraft = computed(() => report.value?.status === 'draft');
const notesChanged = computed(() => notes.value !== (report.value?.notes ?? ''));

function adopt(next: ReportDetail): void {
    report.value = next;
    notes.value = next.notes ?? '';
}

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    denied.value = false;
    notFound.value = false;

    try {
        adopt(await readReport(reportId));

        if (isDraft.value) {
            candidates.value = await readRecipientCandidates(reportId);
            chosen.value = candidates.value.filter((candidate) => candidate.is_default).map((candidate) => candidate.id);
        }
    } catch (error) {
        if (error instanceof ApiError && error.status === 404) {
            notFound.value = true;
        } else if (error instanceof ApiError && error.status === 403) {
            denied.value = true;
        } else {
            failed.value = true;
        }
    } finally {
        loading.value = false;
    }
}

/** A refusal is said, and the typed notes stay on screen. */
function refused(error: unknown): void {
    if (error instanceof ApiError && error.status === 409) {
        conflict.value = true;
    } else {
        actionFailed.value = error instanceof ApiError ? error.message : t('reports.error.save');
    }
}

async function saveNotes(): Promise<boolean> {
    if (report.value === null) {
        return false;
    }

    try {
        const typed = notes.value;
        adopt(await saveReportNotes(report.value.id, report.value.etag, typed));
        saved.value = true;

        return true;
    } catch (error) {
        refused(error);

        return false;
    }
}

async function save(): Promise<void> {
    busy.value = true;
    saved.value = false;
    conflict.value = false;
    actionFailed.value = null;
    await saveNotes();
    busy.value = false;
}

/** Unsaved notes are saved first, so what is frozen is what is on screen. */
async function submit(): Promise<void> {
    if (report.value === null || chosen.value.length === 0) {
        return;
    }

    busy.value = true;
    saved.value = false;
    conflict.value = false;
    actionFailed.value = null;

    try {
        if (notesChanged.value && !(await saveNotes())) {
            return;
        }

        const ordered = candidates.value.map((candidate) => candidate.id).filter((id) => chosen.value.includes(id));
        adopt(await submitReport(report.value.id, report.value.etag, ordered));
        saved.value = false;
    } catch (error) {
        refused(error);
    } finally {
        busy.value = false;
    }
}

onMounted(load);
</script>

<template>
    <section class="flex flex-col gap-6">
        <RouterLink :to="{ name: 'reports' }" class="row-link w-fit">{{ t('reports.back') }}</RouterLink>

        <LoadingState v-if="loading" label-key="reports.loading" />
        <div v-else-if="notFound" data-testid="not-found-state">
            <EmptyState title-key="reports.not_found.title" message-key="reports.not_found.message" />
        </div>
        <PermissionDeniedState v-else-if="denied" />
        <ErrorState v-else-if="failed || report === null" @retry="load" />

        <template v-else>
            <header class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <h1 class="text-page-title"><bdi dir="ltr">{{ report.code }}</bdi></h1>
                    <p class="flex flex-wrap gap-x-3 text-[var(--color-text-muted)]">
                        <span>{{ t(`reports.type.${report.type}`) }}</span>
                        <span class="tabular-nums">{{ displayDate(report.period_start, locale) }}</span>
                    </p>
                </div>
                <span class="rounded-full bg-[var(--color-surface-muted)] px-3 py-1" data-testid="report-status">
                    {{ t(`reports.status.${report.status}`) }}
                </span>
            </header>

            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
                <!-- `D-126` RPT-DY, `D-130`: the counts, then the records themselves, one section per kind. -->
                <section class="flex min-w-0 flex-col gap-4" :aria-label="t('reports.activity_title')">
                    <h2 class="text-section-title">{{ t('reports.activity_title') }}</h2>
                    <ul class="grid grid-cols-2 gap-2 md:grid-cols-3">
                        <li v-for="key in ACTIVITY_COUNTS" :key="key" :data-testid="`activity-count-${key}`">
                            <component
                                :is="hasActivity ? 'a' : 'div'"
                                :href="hasActivity ? `#activity-section-${key}` : undefined"
                                class="flex h-full min-h-11 flex-col gap-1 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-3"
                                :class="{ 'hover:bg-[var(--color-surface-muted)]': hasActivity }"
                            >
                                <span class="text-page-title tabular-nums">{{ report.activity.counts[key] }}</span>
                                <span class="text-table text-[var(--color-text-muted)]">{{ t(`reports.activity.${key}`) }}</span>
                            </component>
                        </li>
                    </ul>

                    <p v-if="!hasActivity" class="text-[var(--color-text-muted)]" data-testid="activity-empty">
                        {{ t('reports.activity_empty') }}
                    </p>
                    <template v-else>
                        <section
                            v-for="key in ACTIVITY_COUNTS"
                            :id="`activity-section-${key}`"
                            :key="key"
                            class="flex scroll-mt-36 flex-col gap-2"
                            :aria-labelledby="`activity-heading-${key}`"
                            :data-testid="`activity-section-${key}`"
                        >
                            <h3 :id="`activity-heading-${key}`" class="text-card-title">
                                {{ t(`reports.activity.${key}`) }}
                                <span class="tabular-nums text-[var(--color-text-muted)]">({{ report.activity.counts[key] }})</span>
                            </h3>
                            <p
                                v-if="report.activity.sections[key].length === 0"
                                class="text-[var(--color-text-muted)]"
                                :data-testid="`activity-section-empty-${key}`"
                            >
                                {{ t('reports.activity_none') }}
                            </p>
                            <div v-else class="overflow-x-clip rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)]">
                                <table class="card-table w-full text-table">
                                    <thead>
                                        <tr class="bg-[var(--color-surface-muted)] text-[var(--color-text-muted)]">
                                            <th
                                                v-for="column in COLUMNS[key]"
                                                :key="column"
                                                scope="col"
                                                class="p-3 font-medium"
                                                :class="column === 'quotations.column.total' ? 'text-end' : 'text-start'"
                                            >
                                                {{ t(column) }}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template v-if="key === 'deals_created'">
                                            <tr v-for="row in report.activity.sections.deals_created" :key="row.deal_id" class="border-t border-[var(--color-border)]" data-testid="activity-row">
                                                <td :data-label="t('deals.column.code')" class="whitespace-nowrap p-3 tabular-nums">
                                                    <RouterLink :to="{ name: 'deal-detail', params: { id: row.deal_id } }" class="row-link">{{ row.code }}</RouterLink>
                                                </td>
                                                <td :data-label="t('deals.column.customer')" class="p-3">{{ row.customer_name }}</td>
                                                <td :data-label="t('deals.column.status')" class="p-3"><DealStatusChip :status="row.status" /></td>
                                            </tr>
                                        </template>
                                        <template v-else-if="key === 'deal_status_moves'">
                                            <tr v-for="(row, index) in report.activity.sections.deal_status_moves" :key="`${row.deal_id}-${index}`" class="border-t border-[var(--color-border)]" data-testid="activity-row">
                                                <td :data-label="t('deals.column.code')" class="whitespace-nowrap p-3 tabular-nums">
                                                    <RouterLink :to="{ name: 'deal-detail', params: { id: row.deal_id } }" class="row-link">{{ row.code }}</RouterLink>
                                                </td>
                                                <td :data-label="t('deals.column.customer')" class="p-3">{{ row.customer_name }}</td>
                                                <td :data-label="t('reports.column.from')" class="p-3"><DealStatusChip :status="row.from_status" /></td>
                                                <td :data-label="t('reports.column.to')" class="p-3"><DealStatusChip :status="row.to_status" /></td>
                                            </tr>
                                        </template>
                                        <template v-else-if="key === 'quotations_created' || key === 'quotations_sent' || key === 'customer_responses'">
                                            <!-- One row shape for the three quotation kinds; a response adds its word (`D-130`). -->
                                            <tr v-for="(row, index) in report.activity.sections[key]" :key="`${row.quotation_id}-${index}`" class="border-t border-[var(--color-border)]" data-testid="activity-row">
                                                <td :data-label="t('quotations.column.code')" class="whitespace-nowrap p-3 tabular-nums">
                                                    <RouterLink :to="{ name: 'quotation-detail', params: { id: row.quotation_id } }" class="row-link">{{ row.code }}</RouterLink>
                                                </td>
                                                <td :data-label="t('quotations.column.version')" class="p-3 tabular-nums">{{ t('quotations.version', { version: row.version }) }}</td>
                                                <td :data-label="t('quotations.column.customer')" class="p-3">{{ row.customer_name }}</td>
                                                <td v-if="'response' in row" :data-label="t('reports.column.response')" class="p-3">{{ t(`reports.response.${row.response}`) }}</td>
                                                <td :data-label="t('quotations.column.status')" class="p-3"><QuotationStatusChip :status="row.status" /></td>
                                                <td :data-label="t('quotations.column.total')" class="whitespace-nowrap p-3 text-end tabular-nums">
                                                    <bdi dir="ltr">{{ displayDecimals(row.final_total) }} {{ row.currency }}</bdi>
                                                </td>
                                            </tr>
                                        </template>
                                        <template v-else>
                                            <tr v-for="row in report.activity.sections.purchase_orders" :key="row.purchase_order_id" class="border-t border-[var(--color-border)]" data-testid="activity-row">
                                                <td :data-label="t('purchaseOrders.column.poNumber')" class="whitespace-nowrap p-3 tabular-nums">{{ row.po_number }}</td>
                                                <td :data-label="t('purchaseOrders.column.quotation')" class="whitespace-nowrap p-3 tabular-nums">
                                                    <RouterLink :to="{ name: 'quotation-detail', params: { id: row.quotation_id } }" class="row-link">{{ row.quotation_code }}</RouterLink>
                                                </td>
                                                <td :data-label="t('quotations.column.customer')" class="p-3">{{ row.customer_name }}</td>
                                                <td :data-label="t('reports.column.po_date')" class="p-3 tabular-nums">{{ displayDate(row.po_date, locale) }}</td>
                                                <td :data-label="t('quotations.column.total')" class="whitespace-nowrap p-3 text-end tabular-nums">
                                                    <bdi dir="ltr">{{ displayDecimals(row.final_total) }} {{ row.currency }}</bdi>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </template>
                </section>

                <!-- Notes, recipients and Submit: the side column on a wide screen, last on a phone. -->
                <div class="flex flex-col gap-6">
                <!-- §11.3's free-text notes: a form on a draft, text once submitted. -->
                <section class="flex flex-col gap-2">
                    <h2 class="text-card-title">{{ t('reports.notes') }}</h2>
                    <template v-if="isDraft">
                        <label class="flex flex-col gap-1.5">
                            <span class="text-table text-[var(--color-text-muted)]">{{ t('reports.notes_hint') }}</span>
                            <textarea
                                v-model="notes"
                                rows="5"
                                class="form-field rounded-lg px-3 py-2"
                                data-testid="report-notes"
                            />
                        </label>
                        <div class="flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                class="row-action min-h-11 rounded-lg px-3 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="busy"
                                data-testid="report-save"
                                @click="save"
                            >
                                {{ t('reports.save') }}
                            </button>
                            <p v-if="saved" class="text-[var(--color-success)]" role="status">{{ t('reports.saved') }}</p>
                        </div>
                    </template>
                    <p v-else class="whitespace-pre-line text-pretty">{{ report.notes ?? t('reports.no_notes') }}</p>
                </section>

                <!-- `D-127` / `D-129`: who it goes to. The server's candidates, its default already chosen. -->
                <section class="flex flex-col gap-2">
                    <h2 class="text-card-title">{{ t('reports.recipients_title') }}</h2>
                    <template v-if="isDraft">
                        <p class="text-table text-[var(--color-text-muted)]">{{ t('reports.recipients_hint') }}</p>
                        <p v-if="candidates.length === 0" class="text-[var(--color-text-muted)]">{{ t('reports.recipients_none') }}</p>
                        <label
                            v-for="candidate in candidates"
                            :key="candidate.id"
                            class="flex min-h-11 items-center gap-3"
                            data-testid="recipient-option"
                        >
                            <input v-model="chosen" type="checkbox" :value="candidate.id" class="size-5">
                            <span>{{ candidate.name }}</span>
                        </label>

                        <p v-if="conflict" class="text-[var(--color-danger)]" role="alert" data-testid="report-conflict">
                            {{ t('reports.conflict') }}
                        </p>
                        <p v-if="actionFailed !== null" class="text-[var(--color-danger)]" role="alert">{{ actionFailed }}</p>

                        <button
                            type="button"
                            class="primary-action min-h-11 w-fit rounded-lg px-4 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="busy || chosen.length === 0"
                            data-testid="report-submit"
                            @click="submit"
                        >
                            {{ t('reports.submit') }}
                        </button>
                    </template>
                    <ul v-else class="flex flex-col gap-1">
                        <li v-for="recipient in report.recipients" :key="recipient.id" data-testid="report-recipient">{{ recipient.name }}</li>
                    </ul>
                </section>
                </div>
            </div>
        </template>
    </section>
</template>
