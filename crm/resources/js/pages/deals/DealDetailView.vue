<script setup lang="ts">
/**
 * One deal — Design System §5.2's Detail view (Module 5, Point 6.6), on
 * `CustomerDetailView`'s shape.
 *
 * §5.2: "Summary first, related data/timeline second, action controls only by
 * permission." That is the shape below: §4.3's fields, then §4.4's timeline,
 * then the controls Points 6.4 and 6.5 already built.
 *
 * ── A 404 is one state and must stay one state ─────────────────────────────
 *
 * `OpenAPI §5.1` defines 404 as "Resource does not exist **or is not visible to
 * the caller**. Do not reveal which case applies", which is why `DealNotFound`
 * is a single exception covering both. A screen that said "you do not have
 * access to this deal" would undo that in the one place a person reads it, so
 * this one says the record could not be opened and stops there.
 *
 * A **403** is a different answer and gets a different screen: it is about the
 * caller lacking `deal.view` at all, and says nothing about any row.
 *
 * ── The timeline is a second permission, and a second refusal ──────────────
 *
 * §3.4 gives `view timeline` its own row, and `GET /deals/{id}/timeline`
 * carries `deal.view_timeline` rather than `deal.view` (Point 5.2). So the
 * timeline is loaded separately and its failure is contained: a caller who may
 * read the deal and not its history sees the summary with a refusal **in the
 * timeline section**, not an error page over a deal that loaded perfectly well.
 *
 * ── The actor is an identifier, honestly ───────────────────────────────────
 *
 * `DealTimelinePayload` sends `actor_id`, never a name: Identity publishes no
 * list this module may resolve one against, and `CLAUDE.md` forbids reading
 * another module's tables. Module 6 Point 6.2 took the same answer for a
 * currency it could not resolve. Owed to a later point, on the debt register.
 *
 * ── What is deliberately not here ──────────────────────────────────────────
 *
 * **No documents panel and no assign control** — both are Point 6.7, and
 * building them here would be building past the approved plan. **No Kanban
 * link**: §5.2's board is deferred with a `D-xx` owed.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { ApiError } from '@/api';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import DealApprovalControls from '@/pages/deals/DealApprovalControls.vue';
import DealStatusControl from '@/pages/deals/DealStatusControl.vue';
import { listDealTimeline, readDeal, type Deal, type DealTimelineEntry } from '@/services/deals';
import { readCustomer, type Customer } from '@/services/customers';

const route = useRoute();
const { t, locale } = useI18n();

const deal = ref<Deal | null>(null);
const customer = ref<Customer | null>(null);
const timeline = ref<DealTimelineEntry[]>([]);

const loading = ref(true);
const failed = ref(false);
const denied = ref(false);
const missing = ref(false);

const timelineLoading = ref(true);
const timelineDenied = ref(false);
const timelineFailed = ref(false);

const id = computed(() => String(route.params.id ?? ''));

/**
 * §4.3's fields, as label/value pairs. A list rather than markup per field, so
 * a field cannot be added to §4.3 and drawn in one place only.
 */
const SUMMARY_FIELDS = [
    { key: 'code', label: 'deals.column.code' },
    { key: 'title', label: 'deals.column.title' },
    { key: 'owner_id', label: 'deals.column.owner' },
] as const;

function summaryValue(key: (typeof SUMMARY_FIELDS)[number]['key']): string {
    const value = deal.value?.[key];

    return value === null || value === undefined || value === '' ? '—' : String(value);
}

function statusLabel(code: string): string {
    return t(`deals.status.${code}`);
}

/** `DB-08`: stored UTC, shown in the reader's locale, date and time both. */
function onMoment(value: string): string {
    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-GB');
}

/**
 * One timeline line. A row that changed no status still belongs in the
 * history — §4.4 describes what must be *in* the timeline, not what to filter
 * out — so it is drawn by its event alone.
 */
function entryDescription(entry: DealTimelineEntry): string {
    if (entry.old_status !== null && entry.new_status !== null) {
        return t('deals.timeline.statusChange', {
            from: statusLabel(entry.old_status),
            to: statusLabel(entry.new_status),
        });
    }

    if (entry.new_status !== null) {
        return t('deals.timeline.statusSet', { to: statusLabel(entry.new_status) });
    }

    return t(`deals.timeline.event.${entry.event}`);
}

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    denied.value = false;
    missing.value = false;

    try {
        deal.value = await readDeal(id.value);
    } catch (error) {
        const status = error instanceof ApiError ? error.status : 0;

        // §5.1: a 404 says the record could not be opened and never which of
        // the two reasons applies.
        missing.value = status === 404;
        denied.value = status === 403;
        failed.value = !missing.value && !denied.value;
    } finally {
        loading.value = false;
    }
}

/**
 * The history, on its own permission. Contained deliberately: a caller with
 * `deal.view` and without `deal.view_timeline` reads the deal and is refused
 * the history, which is a refusal about a section and not about the page.
 */
async function loadTimeline(): Promise<void> {
    timelineLoading.value = true;
    timelineDenied.value = false;
    timelineFailed.value = false;

    try {
        timeline.value = (await listDealTimeline(id.value)).items;
    } catch (error) {
        const status = error instanceof ApiError ? error.status : 0;

        timelineDenied.value = status === 403;
        timelineFailed.value = !timelineDenied.value;
    } finally {
        timelineLoading.value = false;
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

/** A decision or a transition rewrites both the row and its history. */
async function refresh(): Promise<void> {
    await Promise.all([load(), loadTimeline()]);

    if (deal.value !== null) {
        await loadCustomer(deal.value.customer_id);
    }
}

onMounted(async () => {
    await refresh();
});
</script>

<template>
    <section class="flex flex-col gap-4">
        <LoadingState v-if="loading" label-key="deals.loading" />
        <PermissionDeniedState v-else-if="denied" />
        <ErrorState v-else-if="failed" @retry="refresh" />

        <!-- One state, one sentence: never which of the two reasons applies. -->
        <p v-else-if="missing" class="form-alert rounded-lg p-3" role="alert" data-testid="deal-detail-missing">
            {{ t('deals.detail.missing') }}
        </p>

        <template v-else-if="deal !== null">
            <header class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-page-title" data-testid="deal-detail-title">
                    {{ deal.code }}
                </h1>
                <p data-testid="deal-detail-status">{{ statusLabel(deal.status) }}</p>
            </header>

            <!-- §5.2: summary first. -->
            <dl class="detail-grid rounded-xl p-4" data-testid="deal-detail-summary">
                <div v-for="field in SUMMARY_FIELDS" :key="field.key" class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t(field.label) }}</dt>
                    <dd :data-testid="`deal-detail-${field.key}`">{{ summaryValue(field.key) }}</dd>
                </div>

                <div class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('deals.column.customer') }}</dt>
                    <!-- The name when it could be read, the identifier when it
                         could not — never a blank. -->
                    <dd data-testid="deal-detail-customer">{{ customer?.name ?? deal.customer_id }}</dd>
                </div>

                <div class="flex flex-col gap-1">
                    <dt class="text-[var(--color-text-muted)]">{{ t('deals.column.lastActivity') }}</dt>
                    <dd class="tabular-nums" data-testid="deal-detail-activity">{{ onMoment(deal.last_activity_at) }}</dd>
                </div>
            </dl>

            <!-- §5.2: "action controls only by permission" — each draws itself. -->
            <div class="flex flex-wrap items-start gap-4" data-testid="deal-detail-actions">
                <DealApprovalControls
                    v-if="deal.approval_status !== null"
                    :deal="deal"
                    @decided="refresh"
                />
                <DealStatusControl :deal="deal" @changed="refresh" />
            </div>

            <!-- §5.2: related data / timeline second. -->
            <section class="flex flex-col gap-2" data-testid="deal-detail-timeline">
                <h2 class="text-card-title">{{ t('deals.timeline.title') }}</h2>

                <LoadingState v-if="timelineLoading" label-key="deals.timeline.loading" />
                <PermissionDeniedState v-else-if="timelineDenied" />
                <ErrorState v-else-if="timelineFailed" @retry="loadTimeline" />

                <p v-else-if="timeline.length === 0" data-testid="deal-detail-timeline-empty">
                    {{ t('deals.timeline.empty') }}
                </p>

                <ol v-else class="flex flex-col gap-2">
                    <li
                        v-for="entry in timeline"
                        :key="entry.id"
                        class="timeline-entry rounded-lg p-3"
                        data-testid="deal-detail-timeline-entry"
                    >
                        <p data-testid="deal-detail-timeline-what">{{ entryDescription(entry) }}</p>
                        <p class="text-[var(--color-text-muted)]">
                            <!-- ⚠️ An identifier: Identity publishes no list this
                                 module may resolve a name against. -->
                            <span data-testid="deal-detail-timeline-who">
                                {{ entry.actor_id ?? t('deals.timeline.systemActor') }}
                            </span>
                            <span class="tabular-nums" data-testid="deal-detail-timeline-when">
                                {{ onMoment(entry.occurred_at) }}
                            </span>
                        </p>
                        <!-- SEC-10: a history naming only the actor would read
                             identically whether or not somebody's account was used. -->
                        <p
                            v-if="entry.impersonated_user_id !== null"
                            class="text-[var(--color-warning)]"
                            data-testid="deal-detail-timeline-impersonated"
                        >
                            {{ t('deals.timeline.impersonated', { user: entry.impersonated_user_id }) }}
                        </p>
                    </li>
                </ol>
            </section>
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

.timeline-entry {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border);
}

.form-alert {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-border-strong);
}
</style>
