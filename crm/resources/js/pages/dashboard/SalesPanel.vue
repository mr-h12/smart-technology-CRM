<script setup lang="ts">
/**
 * Module 14, slice 4 — the Sales tab I (`D-144`, `D-151`): the funnel in §4.4's
 * order, conversion and win/loss, the sales cycle, average deal size (money
 * only) and workload (drill-down holders only). What the answer leaves out is
 * not drawn; a rate with nothing to divide by reads "—".
 */
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import BreakdownsSection from '@/pages/dashboard/BreakdownsSection.vue';
import { displayDecimals } from '@/domain/displayDecimals';
import { readDashboardSales, type DashboardSales } from '@/services/dashboard';

/** `drill`: the meta's `drill_down` (`D-145`) — stages and rows open their lists, or are only text. */
const props = defineProps<{ from: string; to: string; drill: boolean }>();
const { t } = useI18n();

const sales = ref<DashboardSales | null>(null);
const loading = ref(true);
const failed = ref(false);
let latest = 0;

async function load(): Promise<void> {
    const request = ++latest;
    loading.value = true;
    failed.value = false;
    try {
        const result = await readDashboardSales({ from: props.from, to: props.to });
        if (request === latest) {
            sales.value = result;
        }
    } catch {
        if (request === latest) {
            failed.value = true;
        }
    } finally {
        if (request === latest) {
            loading.value = false;
        }
    }
}

onMounted(() => load());
watch(() => [props.from, props.to], () => load());

const widest = computed(() => Math.max(1, ...(sales.value?.funnel ?? []).map((s) => s.deals)));
const rate = (value: string | null): string => (value === null ? '—' : `${value}%`);
</script>

<template>
    <LoadingState v-if="loading && sales === null" label-key="dashboard.loading" />
    <div v-else-if="failed" data-testid="dashboard-sales-error">
        <ErrorState @retry="load()" />
    </div>
    <div v-else-if="sales !== null" class="flex flex-col gap-4" :aria-busy="loading">
        <ul class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <li class="flex flex-col gap-1 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-3" data-testid="dashboard-conversion">
                <span class="text-table text-[var(--color-text-muted)]">{{ t('dashboard.sales.conversion') }}</span>
                <span class="text-page-title tabular-nums"><bdi dir="ltr">{{ rate(sales.conversion.rate_percent) }}</bdi></span>
                <span class="text-table text-[var(--color-text-muted)] tabular-nums">{{ t('dashboard.sales.of_created', { won: sales.conversion.reached_won, created: sales.conversion.created }) }}</span>
            </li>
            <li class="flex flex-col gap-1 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-3" data-testid="dashboard-win-rate">
                <span class="text-table text-[var(--color-text-muted)]">{{ t('dashboard.sales.win_rate') }}</span>
                <span class="text-page-title tabular-nums"><bdi dir="ltr">{{ rate(sales.win_loss.win_rate_percent) }}</bdi></span>
                <span class="text-table text-[var(--color-text-muted)] tabular-nums">{{ t('dashboard.sales.won_lost', { won: sales.win_loss.won, lost: sales.win_loss.lost }) }}</span>
            </li>
            <li class="flex flex-col gap-1 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-3" data-testid="dashboard-sales-cycle">
                <span class="text-table text-[var(--color-text-muted)]">{{ t('dashboard.sales.cycle') }}</span>
                <span class="text-page-title tabular-nums">{{ sales.average_sales_cycle_days === null ? '—' : t('dashboard.sales.days', { days: sales.average_sales_cycle_days }) }}</span>
            </li>
            <li
                v-for="size in sales.average_deal_size ?? []"
                :key="size.currency"
                class="flex flex-col gap-1 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-3"
                :data-testid="`dashboard-deal-size-${size.currency}`"
            >
                <span class="text-table text-[var(--color-text-muted)]">{{ t('dashboard.sales.deal_size') }} · <bdi dir="ltr">{{ size.currency }}</bdi></span>
                <span class="text-page-title tabular-nums">{{ displayDecimals(size.value) }}</span>
            </li>
        </ul>

        <section class="flex flex-col gap-2">
            <h2 class="font-semibold">{{ t('dashboard.sales.funnel') }}</h2>
            <ul class="flex flex-col gap-1">
                <li v-for="stage in sales.funnel" :key="stage.status" class="flex items-center gap-2" data-testid="dashboard-funnel-stage" :data-status="stage.status">
                    <RouterLink
                        v-if="drill"
                        :to="{ name: 'deals', query: { status: stage.status, created_from: from, created_to: to } }"
                        class="row-link w-40 shrink-0 text-table"
                    >{{ t(`deals.status.${stage.status}`) }}</RouterLink>
                    <span v-else class="w-40 shrink-0 text-table">{{ t(`deals.status.${stage.status}`) }}</span>
                    <span class="h-3 flex-1 rounded-full bg-[var(--color-surface-muted)]" aria-hidden="true">
                        <span class="block h-3 rounded-full bg-[var(--color-primary)]" :style="{ width: `${(stage.deals / widest) * 100}%` }" />
                    </span>
                    <span class="w-10 text-end tabular-nums">{{ stage.deals }}</span>
                </li>
            </ul>
        </section>

        <section v-if="sales.workload !== undefined" class="flex flex-col gap-2" data-testid="dashboard-workload">
            <h2 class="font-semibold">{{ t('dashboard.sales.workload') }} <span class="text-table text-[var(--color-text-muted)]">· {{ t('dashboard.overview.now') }}</span></h2>
            <ul class="flex flex-col gap-1">
                <li v-for="row in sales.workload" :key="row.owner_id" class="flex justify-between gap-2 border-b border-[var(--color-border)] py-1">
                    <RouterLink v-if="drill" :to="{ name: 'deals', query: { owner: row.owner_id, progress: 'open' } }" class="row-link">{{ row.name }}</RouterLink>
                    <span v-else>{{ row.name }}</span>
                    <span class="tabular-nums">{{ row.open_deals }}</span>
                </li>
            </ul>
        </section>

        <BreakdownsSection :from="from" :to="to" />
    </div>
</template>
