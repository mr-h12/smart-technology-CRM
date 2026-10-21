<script setup lang="ts">
/**
 * Module 14, slice 2 — the Overview tab's counts (`D-144`) for the chosen period.
 * The live figures (pending approvals, returned quotations) are "now" whatever
 * the period. A failure here is the tab's own: the tabs and the period stay usable.
 */
import { onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import { readDashboardOverview, type DashboardOverview } from '@/services/dashboard';

const props = defineProps<{ from: string; to: string }>();
const emit = defineEmits<{ refreshed: [lastUpdatedAt: string | null] }>();

const { t } = useI18n();

const overview = ref<DashboardOverview | null>(null);
const loading = ref(true);
const failed = ref(false);
/** Only the newest request may answer: a slow reply for an old period must not overwrite a newer one. */
let latest = 0;

async function load(): Promise<void> {
    const request = ++latest;
    loading.value = true;
    failed.value = false;

    try {
        const result = await readDashboardOverview({ from: props.from, to: props.to });
        if (request === latest) {
            overview.value = result;
            emit('refreshed', result.last_updated_at);
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

interface Tile {
    name: string;
    labelKey: string;
    value: (o: DashboardOverview) => number;
    now?: true;
}

const TILES: readonly Tile[] = [
    { name: 'customers', labelKey: 'dashboard.overview.customers', value: (o) => o.customers },
    { name: 'deals-total', labelKey: 'dashboard.overview.deals_total', value: (o) => o.deals.total },
    { name: 'deals-open', labelKey: 'dashboard.overview.deals_open', value: (o) => o.deals.open },
    { name: 'deals-closed', labelKey: 'dashboard.overview.deals_closed', value: (o) => o.deals.closed },
    { name: 'self-approvals', labelKey: 'dashboard.overview.self_approvals', value: (o) => o.self_approvals },
    { name: 'pending-approvals', labelKey: 'dashboard.overview.pending_approvals', value: (o) => o.now.pending_approvals, now: true },
    { name: 'returned', labelKey: 'dashboard.overview.returned', value: (o) => o.now.returned, now: true },
];
</script>

<template>
    <LoadingState v-if="loading && overview === null" label-key="dashboard.loading" />
    <div v-else-if="failed" data-testid="dashboard-overview-error">
        <ErrorState @retry="load()" />
    </div>
    <ul v-else-if="overview !== null" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4" :aria-busy="loading">
        <li
            v-for="tile in TILES"
            :key="tile.name"
            class="flex flex-col gap-1 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-3"
            :data-testid="`dashboard-tile-${tile.name}`"
        >
            <span class="flex items-center gap-2 text-table text-[var(--color-text-muted)]">
                {{ t(tile.labelKey) }}
                <span v-if="tile.now" class="rounded-full bg-[var(--color-surface-muted)] px-2 py-0.5 text-table">{{ t('dashboard.overview.now') }}</span>
            </span>
            <span class="text-page-title tabular-nums">{{ tile.value(overview) }}</span>
        </li>
    </ul>
</template>
