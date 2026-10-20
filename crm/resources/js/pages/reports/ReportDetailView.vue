<script setup lang="ts">
/**
 * Module 13, first slice — one daily report (RPT-DY).
 *
 * The author reads the day's records grouped by kind
 * (`D-126`; found by the server through the audit log, never showing it, `D-130`), chooses recipients from the server's
 * candidates with its default already chosen (`D-127`, `D-129`), and submits.
 * Once submitted the page shows the frozen copy and no form (§11.4); a
 * returned report is the author's to edit and submit again as its next version.
 *
 * Slice 2 — the same page for a recipient (`/reports/received/:id`): the
 * server marks it Viewed on that read (`D-127`), and the page offers
 * Acknowledge and Return-with-a-reason to a recipient who holds them (§3.10).
 *
 * Slice 3 (`D-131`) — follow-ups (a customer and a note) and several notes,
 * added with «+ إضافة» and saved by themselves (`useAutosave`, `D-124`'s
 * pattern): 2 s after typing stops and at once when a box is left. An empty
 * required text is not sent and says so; «حذف» asks first. The page reads
 * ① summary → ② activity → ③ follow-ups → ④ notes → ⑤ recipients → ⑥ submit.
 */
import { computed, nextTick, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute } from 'vue-router';
import { ApiError } from '@/api';
import CustomerPicker from '@/components/customers/CustomerPicker.vue';
import ReportCommentsSection from '@/components/reports/ReportCommentsSection.vue';
import ReportPdfButton from '@/components/reports/ReportPdfButton.vue';
import EmptyState from '@/components/states/EmptyState.vue';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import { useAutosave } from '@/components/useAutosave';
import { useModalDialog } from '@/components/useModalDialog';
import { displayDate, displayReportPeriod } from '@/domain/displayDate';
import { displayDecimals } from '@/domain/displayDecimals';
import DealStatusChip from '@/pages/deals/DealStatusChip.vue';
import QuotationStatusChip from '@/pages/quotations/QuotationStatusChip.vue';
import ReportStatusChip from '@/pages/reports/ReportStatusChip.vue';
import { useAuth } from '@/stores/auth';
import {
    ACTIVITY_COUNTS,
    acknowledgeReport,
    addReportFollowUp,
    addReportNote,
    readReceivedReport,
    readRecipientCandidates,
    readReport,
    removeReportFollowUp,
    removeReportNote,
    returnReport,
    submitReport,
    updateReportFollowUp,
    updateReportNote,
    type ActivityCount,
    type RecipientCandidate,
    type ReportComment,
    type ReportDetail,
} from '@/services/reports';

const { t, locale } = useI18n();
const route = useRoute();
const reportId = String(route.params.id);
const received = route.name === 'received-report-detail';
const auth = useAuth();

const report = ref<ReportDetail | null>(null);
const candidates = ref<RecipientCandidate[]>([]);
const chosen = ref<string[]>([]);

const loading = ref(true);
const failed = ref(false);
const denied = ref(false);
const notFound = ref(false);
const busy = ref(false);
const conflict = ref(false);
const actionFailed = ref<string | null>(null);

const returning = ref(false);
const reason = ref('');
const reasonMissing = ref(false);
const reasonField = ref<HTMLTextAreaElement | null>(null);
const returnDialog = ref<HTMLDialogElement | null>(null);

useModalDialog(returnDialog);

/** Each kind's column headings; the cells below follow the same order. */
const COLUMNS: Record<ActivityCount, string[]> = {
    deals_created: ['deals.column.code', 'deals.column.customer', 'deals.column.status'],
    deal_status_moves: ['deals.column.code', 'deals.column.customer', 'reports.column.from', 'reports.column.to'],
    quotations_created: ['quotations.column.code', 'quotations.column.version', 'quotations.column.customer', 'quotations.column.status', 'quotations.column.total'],
    quotations_sent: ['quotations.column.code', 'quotations.column.version', 'quotations.column.customer', 'quotations.column.status', 'quotations.column.total'],
    customer_responses: ['quotations.column.code', 'quotations.column.version', 'quotations.column.customer', 'reports.column.response', 'quotations.column.status', 'quotations.column.total'],
    purchase_orders: ['purchaseOrders.column.poNumber', 'purchaseOrders.column.quotation', 'quotations.column.customer', 'reports.column.po_date', 'quotations.column.total'],
};

/** `D-137`: a recipient reads the author's own counts in the third person — «سجّلها», not «سجّلتها». */
const activityLabel = (key: ActivityCount): string => `reports.activity.${key === 'purchase_orders' && received ? 'purchase_orders_received' : key}`;

const hasActivity = computed(() => ACTIVITY_COUNTS.some((key) => (report.value?.activity?.sections[key].length ?? 0) > 0));
/** `D-140`: RPT-MN's three quotation counts, in the server's words. */
const MONTHLY_QUOTATIONS = ['sent', 'won', 'lost'] as const;
/** §11.4: the author writes a draft (a Missed one too, `D-135`), and a returned report on its way to the next version. */
const isEditable = computed(() => !received && (['draft', 'returned', 'missed'].includes(report.value?.status ?? '')));
/** The server refuses anyone else; the page draws the buttons only where they can work (§3.10). */
const awaitsAnswer = computed(() => received && (report.value?.status === 'submitted' || report.value?.status === 'viewed'));
const canAcknowledge = computed(() => awaitsAnswer.value && auth.hasPermission('report.acknowledge'));
const canReturn = computed(() => awaitsAnswer.value && auth.hasPermission('report.return_report'));
/** `D-127`: only a recipient comments — the author reads. The server refuses anyone else. */
const canComment = computed(() => received && auth.hasPermission('report.comment'));

function addComment(comment: ReportComment): void {
    if (report.value !== null) {
        report.value.comments = [...(report.value.comments ?? []), comment];
    }
}

/** `D-132`: a submitted version has its stored PDF; a draft and a returned report (the author's next version) do not. §11.4: an archived one keeps it, if it was ever submitted (a Missed one was not). */
const hasStoredPdf = computed(() => ['submitted', 'viewed', 'acknowledged'].includes(report.value?.status ?? '')
    || (report.value?.status === 'archived' && report.value.submitted_at !== null));

/** `D-131`: one box per follow-up or note; `key` names its pause, `stored` is what the server holds. */
interface FollowUpRow {
    key: string;
    id: string | null;
    customerId: string;
    customerName: string;
    note: string;
    stored: { customer_id: string; note: string } | null;
    customerMissing: boolean;
    noteMissing: boolean;
}

interface NoteRow {
    key: string;
    id: string | null;
    body: string;
    stored: string | null;
    missing: boolean;
}

type SaveStatus = 'idle' | 'saving' | 'saved' | 'failed';

const followUps = ref<FollowUpRow[]>([]);
const noteRows = ref<NoteRow[]>([]);
const followUpStatus = ref<SaveStatus>('idle');
const noteStatus = ref<SaveStatus>('idle');
/** The row whose «حذف» waits for its confirm. */
const confirming = ref<string | null>(null);

let added = 0;
const saving = new Map<string, Promise<void>>();
const { typed, left, cancel, pending } = useAutosave(save);

function adopt(next: ReportDetail): void {
    report.value = next;
    followUps.value = next.follow_ups.map((row) => ({
        key: `follow-up-${row.id}`, id: row.id ?? null, customerId: row.customer_id, customerName: row.customer_name,
        note: row.note, stored: { customer_id: row.customer_id, note: row.note }, customerMissing: false, noteMissing: false,
    }));
    noteRows.value = next.notes.map((note) => ({ key: `note-${note.id}`, id: note.id ?? null, body: note.body, stored: note.body, missing: false }));
}

function addFollowUp(): void {
    followUps.value.push({ key: `follow-up-new-${++added}`, id: null, customerId: '', customerName: '', note: '', stored: null, customerMissing: false, noteMissing: false });
}

function addNote(): void {
    noteRows.value.push({ key: `note-new-${++added}`, id: null, body: '', stored: null, missing: false });
}

function pickCustomer(row: FollowUpRow, customerId: string): void {
    row.customerId = customerId;
    typed(row.key);
}

/** One request per box at a time; the next pause or leave sends what is left. */
function save(key: string): Promise<void> {
    const running = saving.get(key) ?? (key.startsWith('note-') ? saveNote(key) : saveFollowUp(key)).finally(() => saving.delete(key));
    saving.set(key, running);

    return running;
}

async function saveFollowUp(key: string): Promise<void> {
    const row = followUps.value.find((candidate) => candidate.key === key);
    const note = row?.note.trim() ?? '';

    if (report.value === null || row === undefined) {
        return;
    }

    // `D-131`: a cleared or empty required text is not sent; the stored one stays.
    row.customerMissing = row.customerId === '';
    row.noteMissing = note === '';
    if (row.customerMissing || row.noteMissing) {
        followUpStatus.value = 'idle'; // the error speaks; «تم الحفظ» would claim what was not saved
        return;
    }
    if (row.stored?.customer_id === row.customerId && row.stored.note === note) {
        return;
    }

    followUpStatus.value = 'saving';
    try {
        const draft = { customer_id: row.customerId, note };
        // The first save creates the row; every later one updates it, so a pause mid-sentence never splits it.
        const saved = row.id === null ? await addReportFollowUp(report.value.id, draft) : await updateReportFollowUp(report.value.id, row.id, draft);
        row.id = saved.id;
        row.stored = { customer_id: saved.customer_id, note: saved.note };
        followUpStatus.value = 'saved';
    } catch {
        followUpStatus.value = 'failed';
    }
}

async function saveNote(key: string): Promise<void> {
    const row = noteRows.value.find((candidate) => candidate.key === key);
    const body = row?.body.trim() ?? '';

    if (report.value === null || row === undefined) {
        return;
    }

    row.missing = body === '';
    if (row.missing) {
        noteStatus.value = 'idle';
        return;
    }
    if (row.stored === body) {
        return;
    }

    noteStatus.value = 'saving';
    try {
        const saved = row.id === null ? await addReportNote(report.value.id, body) : await updateReportNote(report.value.id, row.id, body);
        row.id = saved.id;
        row.stored = saved.body;
        noteStatus.value = 'saved';
    } catch {
        noteStatus.value = 'failed';
    }
}

async function removeFollowUp(row: FollowUpRow): Promise<void> {
    cancel(row.key);
    try {
        if (row.id !== null) {
            await removeReportFollowUp(report.value!.id, row.id);
        }
        followUps.value = followUps.value.filter((candidate) => candidate !== row);
        confirming.value = null;
    } catch {
        followUpStatus.value = 'failed';
    }
}

async function removeNote(row: NoteRow): Promise<void> {
    cancel(row.key);
    try {
        if (row.id !== null) {
            await removeReportNote(report.value!.id, row.id);
        }
        noteRows.value = noteRows.value.filter((candidate) => candidate !== row);
        confirming.value = null;
    } catch {
        noteStatus.value = 'failed';
    }
}

/** What is still waiting is saved before Submit, so what is frozen is what is on screen. */
async function flush(): Promise<void> {
    for (const row of [...followUps.value, ...noteRows.value]) {
        if (pending(row.key)) {
            left(row.key);
        }
    }
    await Promise.all(saving.values());
}

function statusText(status: SaveStatus): string {
    return status === 'idle' ? '' : t(`reports.autosave.${status}`);
}

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    denied.value = false;
    notFound.value = false;

    try {
        adopt(await (received ? readReceivedReport(reportId) : readReport(reportId)));

        if (isEditable.value) {
            candidates.value = await readRecipientCandidates(reportId);
            // A returned report keeps who it went to (the owner, 2026-10-16); a draft starts from the defaults.
            const previous = report.value!.recipients.map((recipient) => recipient.id);
            chosen.value = previous.length > 0
                ? previous
                : candidates.value.filter((candidate) => candidate.is_default).map((candidate) => candidate.id);
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

/** A refusal is said, and what was typed stays on screen. */
function refused(error: unknown): void {
    if (error instanceof ApiError && error.status === 409) {
        conflict.value = true;
    } else {
        actionFailed.value = error instanceof ApiError ? error.message : t('reports.error.save');
    }
}

async function submit(): Promise<void> {
    if (report.value === null || chosen.value.length === 0) {
        return;
    }

    busy.value = true;
    conflict.value = false;
    actionFailed.value = null;

    try {
        await flush();
        const ordered = candidates.value.map((candidate) => candidate.id).filter((id) => chosen.value.includes(id));
        adopt(await submitReport(report.value.id, report.value.etag, ordered));
    } catch (error) {
        refused(error);
    } finally {
        busy.value = false;
    }
}

async function acknowledge(): Promise<void> {
    if (report.value === null) {
        return;
    }

    busy.value = true;
    conflict.value = false;
    actionFailed.value = null;

    try {
        adopt(await acknowledgeReport(report.value.id, report.value.etag));
    } catch (error) {
        refused(error);
    } finally {
        busy.value = false;
    }
}

async function openReturn(): Promise<void> {
    returning.value = true;
    reason.value = '';
    reasonMissing.value = false;
    actionFailed.value = null;
    await nextTick();
    reasonField.value?.focus();
}

/** A Return in flight is not walked away from. */
function closeReturn(): void {
    if (!busy.value) {
        returning.value = false;
    }
}

/** §11.4's mandatory reason, mirrored for the person and enforced by the server. */
async function sendReturn(): Promise<void> {
    reasonMissing.value = reason.value.trim() === '';

    if (report.value === null || reasonMissing.value) {
        reasonField.value?.focus();

        return;
    }

    busy.value = true;
    conflict.value = false;
    actionFailed.value = null;

    try {
        adopt(await returnReport(report.value.id, report.value.etag, reason.value));
        returning.value = false;
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
        <RouterLink :to="{ name: received ? 'received-reports' : 'reports' }" class="row-link w-fit">
            {{ t(received ? 'reports.received_back' : 'reports.back') }}
        </RouterLink>

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
                        <span class="tabular-nums" data-testid="report-period">{{ displayReportPeriod(report, locale) }}</span>
                        <span v-if="report.author_name">{{ t('reports.from_author', { name: report.author_name }) }}</span>
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <ReportPdfButton
                        v-if="hasStoredPdf"
                        :report-id="report.id"
                        :report-code="report.code"
                        :version="report.version"
                    />
                    <ReportStatusChip :status="report.status" data-testid="report-status" />
                    <span v-if="report.submitted_late" class="rounded-full bg-[var(--color-surface-muted)] px-2.5 py-0.5 text-table text-[var(--color-warning)]" data-testid="report-late">{{ t('reports.late') }}</span>
                    <span v-if="report.not_reviewed" class="rounded-full bg-[var(--color-surface-muted)] px-2.5 py-0.5 text-table text-[var(--color-warning)]" data-testid="report-not-reviewed">{{ t('reports.not_reviewed') }}</span>
                </div>
            </header>

            <!-- D-131's order: ①–④ in the main column from 1024 px, ⑤⑥ beside them; one column ①→⑥ on a phone. -->
            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
                <div class="flex min-w-0 flex-col gap-8">
                <!-- ①② the author's activity — none on RPT-MN, which is company-wide (`D-140`). -->
                <template v-if="report.activity">
                <!-- ① `D-126` RPT-DY: the counts. -->
                <section class="flex flex-col gap-4" aria-labelledby="report-summary-heading" data-testid="report-section-summary">
                    <h2 id="report-summary-heading" class="text-section-title">{{ t(report.type === 'weekly' ? 'reports.summary_title_weekly' : 'reports.summary_title') }}</h2>
                    <ul class="grid grid-cols-2 gap-2 md:grid-cols-3">
                        <li v-for="key in ACTIVITY_COUNTS" :key="key" :data-testid="`activity-count-${key}`">
                            <component
                                :is="hasActivity ? 'a' : 'div'"
                                :href="hasActivity ? `#activity-section-${key}` : undefined"
                                class="flex h-full min-h-11 flex-col gap-1 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-3"
                                :class="{ 'hover:bg-[var(--color-surface-muted)]': hasActivity }"
                            >
                                <span class="text-page-title tabular-nums">{{ report.activity.counts[key] }}</span>
                                <span class="text-table text-[var(--color-text-muted)]">{{ t(activityLabel(key)) }}</span>
                            </component>
                        </li>
                    </ul>
                </section>

                <!-- ② `D-130`: the records themselves, one section per kind — the server's, read-only. -->
                <section class="flex flex-col gap-4 border-t border-[var(--color-border)] pt-6" aria-labelledby="report-activity-heading" data-testid="report-section-activity">
                    <h2 id="report-activity-heading" class="text-section-title">
                        {{ t(report.type === 'weekly' ? 'reports.activity_title_weekly' : 'reports.activity_title') }}
                        <span class="text-table font-normal text-[var(--color-text-muted)]">· {{ t('reports.filled.auto') }}</span>
                    </h2>
                    <p v-if="!hasActivity" class="text-[var(--color-text-muted)]" data-testid="activity-empty">
                        {{ t(report.type === 'weekly' ? 'reports.activity_empty_weekly' : 'reports.activity_empty') }}
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
                                {{ t(activityLabel(key)) }}
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
                </template>

                <!-- `D-126`/`D-134` RPT-WK: the week's own sections. -->
                <template v-if="report.type === 'weekly'">
                    <section class="flex flex-col gap-3 border-t border-[var(--color-border)] pt-6" aria-labelledby="report-weekly-daily-heading" data-testid="report-section-weekly-daily">
                        <h2 id="report-weekly-daily-heading" class="text-section-title">{{ t('reports.weekly_daily_reports_title') }}</h2>
                        <p v-if="(report.daily_reports ?? []).length === 0" class="text-[var(--color-text-muted)]">{{ t('reports.weekly_daily_reports_empty') }}</p>
                        <ul v-else class="flex flex-col divide-y divide-[var(--color-border)]">
                            <li v-for="row in report.daily_reports" :key="row.code" class="flex flex-wrap items-center gap-x-4 gap-y-1 py-3" data-testid="weekly-daily-report-row">
                                <span class="tabular-nums"><bdi dir="ltr">{{ row.code }}</bdi></span>
                                <span class="tabular-nums text-[var(--color-text-muted)]">{{ displayDate(row.period_start, locale) }}</span>
                                <ReportStatusChip :status="row.status" />
                            </li>
                        </ul>
                    </section>
                    <section class="flex flex-col gap-3 border-t border-[var(--color-border)] pt-6" aria-labelledby="report-weekly-po-heading">
                        <h2 id="report-weekly-po-heading" class="text-section-title">{{ t(received ? 'reports.weekly_purchase_orders_title_received' : 'reports.weekly_purchase_orders_title') }}</h2>
                        <p class="text-page-title tabular-nums" data-testid="weekly-purchase-orders-count">{{ report.purchase_orders_received ?? 0 }}</p>
                    </section>
                    <section class="flex flex-col gap-3 border-t border-[var(--color-border)] pt-6" aria-labelledby="report-weekly-customers-heading">
                        <h2 id="report-weekly-customers-heading" class="text-section-title">
                            {{ t('reports.weekly_new_customers_title') }}
                            <span class="tabular-nums text-[var(--color-text-muted)]" data-testid="weekly-new-customers-count">({{ report.new_customers?.count ?? 0 }})</span>
                        </h2>
                        <p v-if="(report.new_customers?.items ?? []).length === 0" class="text-[var(--color-text-muted)]">{{ t('reports.weekly_new_customers_empty') }}</p>
                        <ul v-else class="flex flex-col divide-y divide-[var(--color-border)]">
                            <li v-for="row in report.new_customers?.items" :key="row.customer_id" class="py-3" data-testid="weekly-new-customer-row">{{ row.name }}</li>
                        </ul>
                    </section>
                </template>

                <!-- `D-140` RPT-MN: the company's month in counts, as the server counts them; `D-141` its money. -->
                <template v-if="report.type === 'monthly'">
                    <section class="flex flex-col gap-3 border-t border-[var(--color-border)] pt-6" aria-labelledby="report-monthly-deals-heading" data-testid="report-section-monthly-deals">
                        <h2 id="report-monthly-deals-heading" class="text-section-title">{{ t('reports.monthly_deals_title') }}</h2>
                        <p v-if="!report.deals_by_status?.length" class="text-[var(--color-text-muted)]">{{ t('reports.monthly_deals_empty') }}</p>
                        <dl v-else class="grid grid-cols-2 gap-2 md:grid-cols-3">
                            <div v-for="row in report.deals_by_status" :key="row.status" class="flex flex-col gap-1 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-3" data-testid="monthly-deals-row">
                                <dt><DealStatusChip :status="row.status" /></dt>
                                <dd class="text-page-title tabular-nums">{{ row.count }}</dd>
                            </div>
                        </dl>
                    </section>
                    <section class="flex flex-col gap-3 border-t border-[var(--color-border)] pt-6" aria-labelledby="report-monthly-quotations-heading" data-testid="report-section-monthly-quotations">
                        <h2 id="report-monthly-quotations-heading" class="text-section-title">{{ t('reports.monthly_quotations_title') }}</h2>
                        <dl class="grid grid-cols-3 gap-2">
                            <div v-for="key in MONTHLY_QUOTATIONS" :key="key" class="flex flex-col gap-1 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-3">
                                <dt class="text-table text-[var(--color-text-muted)]">{{ t(`reports.monthly_quotations_${key}`) }}</dt>
                                <dd class="text-page-title tabular-nums" :data-testid="`monthly-quotations-${key}`">{{ report.quotations?.[key] ?? 0 }}</dd>
                            </div>
                        </dl>
                    </section>
                    <!-- `D-141`: one row per currency as the server gives it, never added across; cost and profit only when sent (`D-126`). -->
                    <section v-if="report.money?.length" class="flex flex-col gap-3 border-t border-[var(--color-border)] pt-6" aria-labelledby="report-monthly-money-heading" data-testid="report-section-monthly-money">
                        <h2 id="report-monthly-money-heading" class="text-section-title">{{ t('reports.monthly_money_title') }}</h2>
                        <div class="overflow-x-clip rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)]">
                            <table class="card-table w-full text-table">
                                <thead>
                                    <tr class="bg-[var(--color-surface-muted)] text-[var(--color-text-muted)]">
                                        <th scope="col" class="p-3 text-start font-medium">{{ t('reports.monthly_money_currency') }}</th>
                                        <th scope="col" class="p-3 text-end font-medium">{{ t('reports.monthly_money_won_value') }}</th>
                                        <template v-if="report.money[0]?.cost !== undefined">
                                            <th scope="col" class="p-3 text-end font-medium">{{ t('reports.monthly_money_cost') }}</th>
                                            <th scope="col" class="p-3 text-end font-medium">{{ t('reports.monthly_money_profit') }}</th>
                                        </template>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in report.money" :key="row.currency" class="border-t border-[var(--color-border)]" data-testid="monthly-money-row">
                                        <td :data-label="t('reports.monthly_money_currency')" class="p-3" data-testid="monthly-money-currency"><bdi dir="ltr">{{ row.currency }}</bdi></td>
                                        <td :data-label="t('reports.monthly_money_won_value')" class="whitespace-nowrap p-3 text-end tabular-nums" data-testid="monthly-money-won-value"><bdi dir="ltr">{{ displayDecimals(row.won_value) }}</bdi></td>
                                        <template v-if="row.cost !== undefined">
                                            <td :data-label="t('reports.monthly_money_cost')" class="whitespace-nowrap p-3 text-end tabular-nums" data-testid="monthly-money-cost"><bdi dir="ltr">{{ displayDecimals(row.cost) }}</bdi></td>
                                            <td :data-label="t('reports.monthly_money_profit')" class="whitespace-nowrap p-3 text-end tabular-nums" data-testid="monthly-money-profit"><bdi dir="ltr">{{ displayDecimals(row.profit) }}</bdi></td>
                                        </template>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </template>

                <!-- ③ D-131: follow-ups — a customer and a note per row, saved by themselves; RPT-DY's alone (`D-137`). -->
                <section v-if="report.type === 'daily'" class="flex flex-col gap-3 border-t border-[var(--color-border)] pt-6" aria-labelledby="report-follow-ups-heading" data-testid="report-section-follow-ups">
                    <h2 id="report-follow-ups-heading" class="text-section-title">
                        {{ t('reports.follow_ups_title') }}
                        <span v-if="isEditable" class="text-table font-normal text-[var(--color-text-muted)]">· {{ t('reports.filled.follow_ups') }}</span>
                    </h2>
                    <template v-if="isEditable">
                        <p v-if="followUps.length === 0" class="text-[var(--color-text-muted)]">{{ t('reports.no_follow_ups') }}</p>
                        <ul class="flex flex-col divide-y divide-[var(--color-border)]">
                            <li v-for="row in followUps" :key="row.key" class="flex flex-col gap-2 py-3 sm:flex-row sm:items-start" data-testid="report-follow-up-row">
                                <div class="flex flex-col gap-1 sm:w-64 sm:shrink-0">
                                    <label :for="`${row.key}-customer`" class="text-table text-[var(--color-text-muted)]">{{ t('reports.follow_up_customer') }}</label>
                                    <CustomerPicker
                                        :id="`${row.key}-customer`"
                                        v-model:name="row.customerName"
                                        :model-value="row.customerId"
                                        test-id="report-follow-up-customer"
                                        :aria-invalid="row.customerMissing"
                                        @update:model-value="pickCustomer(row, $event)"
                                    />
                                    <span v-if="row.customerMissing" class="text-[var(--color-danger)]">{{ t('reports.follow_up_customer_required') }}</span>
                                </div>
                                <div class="flex min-w-0 flex-1 flex-col gap-1">
                                    <label :for="`${row.key}-note`" class="text-table text-[var(--color-text-muted)]">
                                        {{ row.customerName === '' ? t('reports.follow_up_note') : t('reports.follow_up_note_about', { name: row.customerName }) }}
                                    </label>
                                    <textarea
                                        :id="`${row.key}-note`"
                                        v-model="row.note"
                                        rows="2"
                                        maxlength="5000"
                                        dir="auto"
                                        :aria-invalid="row.noteMissing"
                                        :aria-describedby="row.noteMissing ? `${row.key}-note-error` : undefined"
                                        class="form-field rounded-lg px-3 py-2"
                                        data-testid="report-follow-up-note"
                                        @input="typed(row.key)"
                                        @blur="left(row.key)"
                                    />
                                    <span v-if="row.noteMissing" :id="`${row.key}-note-error`" class="text-[var(--color-danger)]" data-testid="report-follow-up-note-error">
                                        {{ t('reports.text_required') }}
                                    </span>
                                </div>
                                <!-- DS §6.2: Remove is a text danger; the filled danger lives only inside the confirm. -->
                                <div class="flex flex-wrap items-center gap-2 sm:pt-6">
                                    <template v-if="confirming === row.key">
                                        <span class="text-sm">{{ t('reports.follow_up_remove_confirm') }}</span>
                                        <button type="button" class="danger-action min-h-11 rounded-lg px-4" data-testid="report-follow-up-remove-confirm" @click="removeFollowUp(row)">
                                            {{ t('reports.remove') }}
                                        </button>
                                        <button type="button" class="row-action min-h-11 rounded-lg px-4" @click="confirming = null">{{ t('reports.keep') }}</button>
                                    </template>
                                    <button v-else type="button" class="danger-text min-h-11 rounded-lg px-3" data-testid="report-follow-up-remove" @click="confirming = row.key">
                                        {{ t('reports.remove') }}
                                    </button>
                                </div>
                            </li>
                        </ul>
                        <div class="flex flex-wrap items-center gap-3">
                            <button type="button" class="row-action min-h-11 rounded-lg px-4" data-testid="report-follow-up-add" @click="addFollowUp">
                                {{ t('reports.follow_up_add') }}
                            </button>
                            <p class="text-sm text-[var(--color-text-muted)]" aria-live="polite" data-testid="report-autosave-status">{{ statusText(followUpStatus) }}</p>
                        </div>
                    </template>
                    <template v-else>
                        <p v-if="report.follow_ups.length === 0" class="text-[var(--color-text-muted)]">{{ t('reports.no_follow_ups') }}</p>
                        <ul v-else class="flex flex-col divide-y divide-[var(--color-border)]">
                            <li v-for="(row, index) in report.follow_ups" :key="index" class="flex flex-col gap-1 py-3" data-testid="report-follow-up-row">
                                <span class="font-semibold">{{ row.customer_name }}</span>
                                <p dir="auto" class="whitespace-pre-line text-pretty [overflow-wrap:anywhere]">{{ row.note }}</p>
                            </li>
                        </ul>
                    </template>
                </section>

                <!-- ④ D-131: several notes, one box each, saved by themselves. -->
                <section class="flex flex-col gap-3 border-t border-[var(--color-border)] pt-6" aria-labelledby="report-notes-heading" data-testid="report-section-notes">
                    <h2 id="report-notes-heading" class="text-section-title">
                        {{ t('reports.notes') }}
                        <span v-if="isEditable" class="text-table font-normal text-[var(--color-text-muted)]">· {{ t('reports.filled.notes') }}</span>
                    </h2>
                    <template v-if="isEditable">
                        <p v-if="noteRows.length === 0" class="text-[var(--color-text-muted)]">{{ t('reports.no_notes') }}</p>
                        <ul class="flex flex-col divide-y divide-[var(--color-border)]">
                            <li v-for="(row, index) in noteRows" :key="row.key" class="flex flex-col gap-2 py-3 sm:flex-row sm:items-start" data-testid="report-note-row">
                                <div class="flex min-w-0 flex-1 flex-col gap-1">
                                    <label :for="`${row.key}-body`" class="text-table text-[var(--color-text-muted)]">{{ t('reports.note_label', { number: index + 1 }) }}</label>
                                    <textarea
                                        :id="`${row.key}-body`"
                                        v-model="row.body"
                                        rows="2"
                                        maxlength="5000"
                                        dir="auto"
                                        :aria-invalid="row.missing"
                                        :aria-describedby="row.missing ? `${row.key}-body-error` : undefined"
                                        class="form-field rounded-lg px-3 py-2"
                                        data-testid="report-note-body"
                                        @input="typed(row.key)"
                                        @blur="left(row.key)"
                                    />
                                    <span v-if="row.missing" :id="`${row.key}-body-error`" class="text-[var(--color-danger)]">{{ t('reports.text_required') }}</span>
                                </div>
                                <div class="flex flex-wrap items-center gap-2 sm:pt-6">
                                    <template v-if="confirming === row.key">
                                        <span class="text-sm">{{ t('reports.note_remove_confirm') }}</span>
                                        <button type="button" class="danger-action min-h-11 rounded-lg px-4" data-testid="report-note-remove-confirm" @click="removeNote(row)">
                                            {{ t('reports.remove') }}
                                        </button>
                                        <button type="button" class="row-action min-h-11 rounded-lg px-4" @click="confirming = null">{{ t('reports.keep') }}</button>
                                    </template>
                                    <button v-else type="button" class="danger-text min-h-11 rounded-lg px-3" data-testid="report-note-remove" @click="confirming = row.key">
                                        {{ t('reports.remove') }}
                                    </button>
                                </div>
                            </li>
                        </ul>
                        <div class="flex flex-wrap items-center gap-3">
                            <button type="button" class="row-action min-h-11 rounded-lg px-4" data-testid="report-note-add" @click="addNote">
                                {{ t('reports.note_add') }}
                            </button>
                            <p class="text-sm text-[var(--color-text-muted)]" aria-live="polite" data-testid="report-autosave-status">{{ statusText(noteStatus) }}</p>
                        </div>
                    </template>
                    <template v-else>
                        <p v-if="report.notes.length === 0" class="text-[var(--color-text-muted)]">{{ t('reports.no_notes') }}</p>
                        <ul v-else class="flex flex-col divide-y divide-[var(--color-border)]">
                            <li v-for="(note, index) in report.notes" :key="index" class="py-3" data-testid="report-note-row">
                                <p dir="auto" class="whitespace-pre-line text-pretty [overflow-wrap:anywhere]">{{ note.body }}</p>
                            </li>
                        </ul>
                    </template>
                </section>
                </div>

                <!-- ⑤⑥ Recipients and Submit: the side column on a wide screen, last on a phone. -->
                <div class="flex flex-col gap-6">
                <!-- ⑤ `D-127` / `D-129`: who it goes to. The server's candidates, its default already chosen. -->
                <section class="flex flex-col gap-2" aria-labelledby="report-recipients-heading" data-testid="report-section-recipients">
                    <h2 id="report-recipients-heading" class="text-card-title">{{ t('reports.recipients_title') }}</h2>
                    <template v-if="isEditable">
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
                    </template>
                    <ul v-else class="flex flex-col gap-1">
                        <li v-for="recipient in report.recipients" :key="recipient.id" data-testid="report-recipient">{{ recipient.name }}</li>
                    </ul>
                </section>

                <!-- ⑥ Submit: always last, on a phone too. -->
                <section v-if="isEditable" class="flex flex-col gap-2" aria-labelledby="report-submit-heading" data-testid="report-section-submit">
                    <h2 id="report-submit-heading" class="text-card-title">{{ t('reports.submit_title') }}</h2>
                    <p v-if="conflict" class="text-[var(--color-danger)]" role="alert">
                        {{ t('reports.conflict') }}
                    </p>
                    <p v-if="actionFailed !== null" class="text-[var(--color-danger)]" role="alert">{{ actionFailed }}</p>

                    <button
                        type="button"
                        class="create-action min-h-11 w-fit rounded-lg px-4 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="busy || chosen.length === 0"
                        data-testid="report-submit"
                        @click="submit"
                    >
                        {{ t('reports.submit') }}
                    </button>
                </section>

                <!-- §11.4: the recipient's answer — Acknowledge, or Return with a reason. -->
                <section v-if="canAcknowledge || canReturn" class="flex flex-col gap-2">
                    <p v-if="conflict" class="text-[var(--color-danger)]" role="alert" data-testid="report-conflict">
                        {{ t('reports.conflict') }}
                    </p>
                    <p v-if="actionFailed !== null" class="text-[var(--color-danger)]" role="alert">{{ actionFailed }}</p>
                    <div class="flex flex-wrap gap-3">
                        <button
                            v-if="canAcknowledge"
                            type="button"
                            class="create-action min-h-11 rounded-lg px-4 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="busy"
                            data-testid="report-acknowledge"
                            @click="acknowledge"
                        >
                            {{ t('reports.acknowledge') }}
                        </button>
                        <button
                            v-if="canReturn"
                            type="button"
                            class="row-action min-h-11 rounded-lg px-4 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="busy"
                            data-testid="report-return"
                            @click="openReturn"
                        >
                            {{ t('reports.return') }}
                        </button>
                    </div>
                </section>

                <!-- `D-127`: comments, after the recipients and the answer. -->
                <ReportCommentsSection :report-id="report.id" :comments="report.comments ?? []" :can-comment="canComment" @added="addComment" />
                </div>
            </div>

            <dialog
                v-if="returning"
                ref="returnDialog"
                class="fixed inset-0 z-50 flex items-center justify-center p-4"
                aria-labelledby="report-return-title"
                @cancel.prevent="closeReturn"
            >
                <div class="dialog-scrim absolute inset-0" @click="closeReturn" />

                <form
                    class="dialog-panel relative flex w-full max-w-md flex-col gap-4 rounded-2xl p-6"
                    data-testid="report-return-dialog"
                    @submit.prevent="sendReturn"
                >
                    <h2 id="report-return-title" class="text-card-title">
                        {{ t('reports.return_title') }} — <bdi dir="ltr">{{ report.code }}</bdi>
                    </h2>
                    <label class="flex flex-col gap-1">
                        <span>{{ t('reports.return_reason') }}</span>
                        <textarea
                            ref="reasonField"
                            v-model="reason"
                            required
                            rows="3"
                            maxlength="2000"
                            :aria-invalid="reasonMissing"
                            :aria-describedby="reasonMissing ? 'report-return-reason-error' : undefined"
                            class="form-field rounded-lg p-3"
                            data-testid="report-return-reason"
                        ></textarea>
                        <span v-if="reasonMissing" id="report-return-reason-error" class="text-[var(--color-danger)]" data-testid="report-return-reason-error">
                            {{ t('reports.return_reason_required') }}
                        </span>
                    </label>
                    <p v-if="actionFailed !== null" class="text-[var(--color-danger)]" role="alert">{{ actionFailed }}</p>
                    <span class="flex justify-end gap-2">
                        <button type="button" class="row-action min-h-11 rounded-lg px-3" :disabled="busy" @click="closeReturn">
                            {{ t('action.cancel') }}
                        </button>
                        <button type="submit" class="create-action min-h-11 rounded-lg px-3 disabled:opacity-60" :disabled="busy">
                            {{ busy ? t('action.saving') : t('reports.return_send') }}
                        </button>
                    </span>
                </form>
            </dialog>
        </template>
    </section>
</template>
