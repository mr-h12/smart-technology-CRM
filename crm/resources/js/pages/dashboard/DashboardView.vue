<script setup lang="ts">
/**
 * Module 14, slice 1 — the dashboard opens per role (`D-145`, §12.1).
 *
 * The server decides which tabs a caller has (`GET /dashboard/meta`); this
 * screen draws what it returns. Procurement and Outdoor are empty states until
 * Modules 11 and 12 fill them (`D-143`), never zeros. The figures of the other
 * tabs arrive from slice 2 onward.
 */
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import EmptyState from '@/components/states/EmptyState.vue';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import { displayDateRange, displayTimestamp } from '@/domain/displayDate';
import { PERIOD_KINDS, periodFor, rememberedPeriod, rememberPeriod, type Period, type PeriodKind } from '@/domain/dashboardPeriod';
import FinancePanel from '@/pages/dashboard/FinancePanel.vue';
import OverviewPanel from '@/pages/dashboard/OverviewPanel.vue';
import SalesPanel from '@/pages/dashboard/SalesPanel.vue';
import { readDashboardMeta, type DashboardMeta, type DashboardTab } from '@/services/dashboard';

const { t, locale } = useI18n();

const meta = ref<DashboardMeta | null>(null);
const loading = ref(true);
const failed = ref(false);
const denied = ref(false);
const active = ref<DashboardTab>('overview');
const period = ref<Period | null>(null);
/** J-09's newest finished run (`D-145`): from the meta, then from each Overview answer. */
const lastUpdatedAt = ref<string | null>(null);

/** `D-145`: "Last updated" turns amber past two hours. */
const STALE_AFTER_MS = 2 * 60 * 60 * 1000;
const stale = computed(() => lastUpdatedAt.value !== null && Date.now() - new Date(lastUpdatedAt.value).getTime() > STALE_AFTER_MS);

/** The tabs Modules 11 and 12 fill (`D-143`); until then, an empty state naming the module. */
const EMPTY_TABS: readonly DashboardTab[] = ['procurement', 'outdoor'];

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    denied.value = false;

    try {
        meta.value = await readDashboardMeta();
        active.value = meta.value.tabs[0] ?? 'overview';
        period.value = rememberedPeriod(meta.value.today);
        lastUpdatedAt.value = meta.value.last_updated_at;
    } catch (error) {
        meta.value = null;
        if (error instanceof ApiError && error.status === 403) {
            denied.value = true;
        } else {
            failed.value = true;
        }
    } finally {
        loading.value = false;
    }
}

function choose(kind: PeriodKind): void {
    if (meta.value !== null) {
        period.value = periodFor(kind, meta.value.today, period.value ?? undefined);
    }
}

function setCustom(edge: 'from' | 'to', value: string): void {
    if (meta.value !== null && period.value !== null && value !== '') {
        period.value = periodFor('custom', meta.value.today, { from: period.value.from, to: period.value.to, [edge]: value });
    }
}

watch(period, (value) => {
    if (value !== null) {
        rememberPeriod(value);
    }
});

const range = computed(() => (period.value === null ? '' : displayDateRange(period.value.from, period.value.to, locale.value)));

onMounted(() => load());
</script>

<template>
    <section class="flex flex-col gap-4">
        <header class="flex flex-col gap-1">
            <h1 class="text-page-title">{{ t('dashboard.title') }}</h1>
            <p
                v-if="meta !== null"
                :class="stale ? 'text-[var(--color-warning)]' : 'text-[var(--color-text-muted)]'"
                :data-stale="String(stale)"
                data-testid="dashboard-last-updated"
            >
                {{ lastUpdatedAt === null ? t('dashboard.last_updated_never') : t('dashboard.last_updated', { time: displayTimestamp(lastUpdatedAt, locale) }) }}
                <span v-if="stale">— {{ t('dashboard.stale') }}</span>
            </p>
        </header>

        <LoadingState v-if="loading" label-key="dashboard.loading" />
        <PermissionDeniedState v-else-if="denied" />
        <div v-else-if="failed" data-testid="dashboard-error">
            <ErrorState @retry="load()" />
        </div>

        <template v-else-if="meta !== null && period !== null">
            <div class="flex flex-wrap items-end gap-3">
                <label class="flex flex-col gap-1">
                    <span class="text-table text-[var(--color-text-muted)]">{{ t('dashboard.period.label') }}</span>
                    <select
                        class="form-field min-h-11 rounded-lg px-3"
                        :value="period.kind"
                        data-testid="dashboard-period-kind"
                        @change="choose(($event.target as HTMLSelectElement).value as PeriodKind)"
                    >
                        <option v-for="kind in PERIOD_KINDS" :key="kind" :value="kind">{{ t(`dashboard.period.${kind}`) }}</option>
                    </select>
                </label>
                <template v-if="period.kind === 'custom'">
                    <label class="flex flex-col gap-1">
                        <span class="text-table text-[var(--color-text-muted)]">{{ t('dashboard.period.from') }}</span>
                        <input type="date" class="form-field min-h-11 rounded-lg px-3" :value="period.from" :max="period.to" @change="setCustom('from', ($event.target as HTMLInputElement).value)" />
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-table text-[var(--color-text-muted)]">{{ t('dashboard.period.to') }}</span>
                        <input type="date" class="form-field min-h-11 rounded-lg px-3" :value="period.to" :min="period.from" @change="setCustom('to', ($event.target as HTMLInputElement).value)" />
                    </label>
                </template>
                <p
                    class="min-h-11 content-center tabular-nums text-[var(--color-text-muted)]"
                    data-testid="dashboard-period-range"
                    :data-from="period.from"
                    :data-to="period.to"
                >
                    <bdi>{{ range }}</bdi>
                </p>
            </div>

            <div
                class="segmented flex max-w-full gap-0.5 self-start overflow-x-auto rounded-xl border border-[var(--color-border)] bg-[var(--color-surface-muted)] p-1"
                role="tablist"
                :aria-label="t('dashboard.tabs_label')"
            >
                <button
                    v-for="tab in meta.tabs"
                    :id="`dashboard-tab-${tab}`"
                    :key="tab"
                    type="button"
                    role="tab"
                    class="segmented__option min-h-11 shrink-0 rounded-lg px-3 text-[var(--color-text-muted)] hover:text-[var(--color-text)]"
                    :class="active === tab ? 'segmented__option--on' : ''"
                    :aria-selected="active === tab"
                    aria-controls="dashboard-panel"
                    :data-testid="`dashboard-tab-${tab}`"
                    @click="active = tab"
                >
                    {{ t(`dashboard.tab.${tab}`) }}
                </button>
            </div>

            <div id="dashboard-panel" role="tabpanel" :aria-labelledby="`dashboard-tab-${active}`">
                <div v-if="EMPTY_TABS.includes(active)" :data-testid="`dashboard-empty-${active}`">
                    <EmptyState :title-key="`dashboard.tab.${active}`" :message-key="`dashboard.empty.${active}`" />
                </div>
                <OverviewPanel v-else-if="active === 'overview'" :from="period.from" :to="period.to" :money="meta.money" @refreshed="lastUpdatedAt = $event" />
                <FinancePanel v-else-if="active === 'finance'" :from="period.from" :to="period.to" />
                <SalesPanel v-else-if="active === 'sales'" :from="period.from" :to="period.to" />
                <!-- Every tab with figures is listed above; this is only reached by a tab added without a panel. -->
                <EmptyState v-else title-key="dashboard.pending.title" message-key="dashboard.pending.message" />
            </div>
        </template>
    </section>
</template>
