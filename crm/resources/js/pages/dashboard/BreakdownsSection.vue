<script setup lang="ts">
/**
 * Module 14, slice 5 — the Sales tab II (`D-144`, `D-145`, `D-147`, `D-150`):
 * lost by reason, by employee, by sector, top customers, best and worst products.
 * The server leaves out what a role may not see; this draws what it sends.
 * Reasons and sectors are named from their managed lists (`DB-05`).
 */
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ErrorState from '@/components/states/ErrorState.vue';
import { displayDecimals } from '@/domain/displayDecimals';
import { entryLabel, listEntries, type ListEntry } from '@/services/admin';
import { readDashboardBreakdowns, type DashboardBreakdowns, type DashboardMoneyLine } from '@/services/dashboard';

const props = defineProps<{ from: string; to: string }>();
const { t, locale } = useI18n();

const parts = ref<DashboardBreakdowns | null>(null);
const failed = ref(false);
const reasons = ref<ListEntry[]>([]);
const sectors = ref<ListEntry[]>([]);
let latest = 0;

async function load(): Promise<void> {
    const request = ++latest;
    failed.value = false;
    try {
        const result = await readDashboardBreakdowns({ from: props.from, to: props.to });
        if (request === latest) {
            parts.value = result;
        }
    } catch {
        if (request === latest) {
            failed.value = true;
        }
    }
}

async function loadLabels(): Promise<void> {
    const [r, s] = await Promise.allSettled([listEntries('lost_reasons', 1), listEntries('sectors', 1)]);
    reasons.value = r.status === 'fulfilled' ? r.value.items : [];
    sectors.value = s.status === 'fulfilled' ? s.value.items : [];
}

onMounted(() => Promise.all([load(), loadLabels()]));
watch(() => [props.from, props.to], () => load());

const label = (list: ListEntry[], code: string | null): string => {
    if (code === null) {
        return t('dashboard.breakdowns.none');
    }
    const entry = list.find((e) => e.code === code);
    return entry === undefined ? code : entryLabel(entry, locale.value);
};
const money = (lines: DashboardMoneyLine[] | undefined): string =>
    (lines ?? []).map((m) => `${displayDecimals(m.won_value)} ${m.currency}`).join(' · ');
const rankings = computed(() => (parts.value === null ? [] : [
    { key: 'top-customers', title: 'dashboard.breakdowns.top_customers', rows: parts.value.top_customers },
    { key: 'products-best', title: 'dashboard.breakdowns.best_products', rows: parts.value.products?.best },
    { key: 'products-worst', title: 'dashboard.breakdowns.worst_products', rows: parts.value.products?.worst },
].filter((r) => r.rows !== undefined)));
</script>

<template>
    <div v-if="failed" data-testid="dashboard-breakdowns-error">
        <ErrorState @retry="load()" />
    </div>
    <div v-else-if="parts !== null" class="grid gap-4 lg:grid-cols-2">
        <section class="flex flex-col gap-2" data-testid="dashboard-lost-by-reason">
            <h2 class="font-semibold">{{ t('dashboard.breakdowns.lost_by_reason') }}</h2>
            <p v-if="parts.lost_by_reason.length === 0" class="text-[var(--color-text-muted)]">{{ t('dashboard.breakdowns.empty') }}</p>
            <ul v-else class="flex flex-col gap-1">
                <li v-for="row in parts.lost_by_reason" :key="row.code" class="flex justify-between gap-2 border-b border-[var(--color-border)] py-1">
                    <span>{{ label(reasons, row.code) }}</span>
                    <span class="tabular-nums">{{ row.deals }}</span>
                </li>
            </ul>
        </section>

        <section v-if="parts.by_employee !== undefined" class="flex flex-col gap-2" data-testid="dashboard-by-employee">
            <h2 class="font-semibold">{{ t('dashboard.breakdowns.by_employee') }}</h2>
            <ul class="flex flex-col gap-1">
                <li v-for="row in parts.by_employee" :key="row.owner_id" class="flex flex-wrap justify-between gap-2 border-b border-[var(--color-border)] py-1">
                    <span>{{ row.name }}</span>
                    <span class="tabular-nums">{{ t('dashboard.breakdowns.won_deals', { count: row.won_deals }) }}<template v-if="row.money !== undefined"> · <bdi dir="ltr">{{ money(row.money) }}</bdi></template></span>
                </li>
            </ul>
        </section>

        <section v-if="parts.by_sector !== undefined" class="flex flex-col gap-2" data-testid="dashboard-by-sector">
            <h2 class="font-semibold">{{ t('dashboard.breakdowns.by_sector') }}</h2>
            <ul class="flex flex-col gap-1">
                <li v-for="row in parts.by_sector" :key="row.sector ?? ''" class="flex flex-wrap justify-between gap-2 border-b border-[var(--color-border)] py-1">
                    <span>{{ label(sectors, row.sector) }}</span>
                    <span class="tabular-nums">{{ t('dashboard.breakdowns.won_deals', { count: row.won_chains }) }} · <bdi dir="ltr">{{ money(row.money) }}</bdi></span>
                </li>
            </ul>
        </section>

        <section v-for="ranking in rankings" :key="ranking.key" class="flex flex-col gap-2" :data-testid="`dashboard-${ranking.key}`">
            <h2 class="font-semibold">{{ t(ranking.title) }}</h2>
            <ol class="flex flex-col gap-1">
                <li v-for="(row, i) in ranking.rows" :key="i" class="flex flex-wrap justify-between gap-2 border-b border-[var(--color-border)] py-1">
                    <span>{{ i + 1 }}. {{ row.name }}</span>
                    <span class="tabular-nums"><bdi dir="ltr">{{ money(row.money) }}</bdi></span>
                </li>
            </ol>
        </section>
    </div>
</template>
