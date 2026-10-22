<script setup lang="ts">
/**
 * Module 14, slice 3 — the Finance tab (`D-144`, `D-149`, `D-150`): per currency
 * the won money, cost, profits, margin and delivery cost; month by month; and,
 * on request, the base-currency totals with what could not be converted.
 */
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import { displayDecimals } from '@/domain/displayDecimals';
import { readDashboardMoney, type DashboardMoney } from '@/services/dashboard';

const props = defineProps<{ from: string; to: string }>();
const { t } = useI18n();

const money = ref<DashboardMoney | null>(null);
const loading = ref(true);
const failed = ref(false);
const combine = ref(false);
let latest = 0;

async function load(): Promise<void> {
    const request = ++latest;
    loading.value = true;
    failed.value = false;
    try {
        const result = await readDashboardMoney({ from: props.from, to: props.to });
        if (request === latest) {
            money.value = result;
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

/** Each bar against the largest month of its own currency: currencies are never put on one scale. */
const bars = computed(() => {
    const months = money.value?.monthly ?? [];
    const peak = new Map<string, number>();
    for (const m of months) {
        peak.set(m.currency, Math.max(peak.get(m.currency) ?? 0, Number(m.won_value)));
    }
    return months.map((m) => ({ ...m, width: (peak.get(m.currency) ?? 0) > 0 ? Math.max(2, (Number(m.won_value) / peak.get(m.currency)!) * 100) : 0 }));
});
</script>

<template>
    <LoadingState v-if="loading && money === null" label-key="dashboard.loading" />
    <div v-else-if="failed" data-testid="dashboard-finance-error">
        <ErrorState @retry="load()" />
    </div>
    <div v-else-if="money !== null" class="flex flex-col gap-4" :aria-busy="loading">
        <p class="text-[var(--color-text-muted)]" data-testid="dashboard-net-profit-note">{{ t('dashboard.money.net_profit_note') }}</p>

        <div class="overflow-x-auto">
            <table class="w-full text-table">
                <thead>
                    <tr class="text-start text-[var(--color-text-muted)]">
                        <th class="p-2 text-start">{{ t('dashboard.money.currency') }}</th>
                        <th class="p-2 text-end">{{ t('dashboard.money.won_value') }}</th>
                        <th class="p-2 text-end">{{ t('dashboard.money.cost') }}</th>
                        <th class="p-2 text-end">{{ t('dashboard.money.gross_profit') }}</th>
                        <th class="p-2 text-end">{{ t('dashboard.money.net_profit') }}</th>
                        <th class="p-2 text-end">{{ t('dashboard.money.margin') }}</th>
                        <th class="p-2 text-end">{{ t('dashboard.money.delivery_cost') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in money.currencies" :key="row.currency" class="border-t border-[var(--color-border)]" :data-testid="`dashboard-finance-${row.currency}`">
                        <td class="p-2"><bdi dir="ltr">{{ row.currency }}</bdi></td>
                        <td class="p-2 text-end tabular-nums">{{ displayDecimals(row.won_value) }}</td>
                        <td class="p-2 text-end tabular-nums">{{ displayDecimals(row.cost) }}</td>
                        <td class="p-2 text-end tabular-nums">{{ displayDecimals(row.gross_profit) }}</td>
                        <td class="p-2 text-end tabular-nums">{{ displayDecimals(row.net_profit) }}</td>
                        <td class="p-2 text-end tabular-nums"><bdi dir="ltr">{{ row.margin_percent }}%</bdi></td>
                        <td class="p-2 text-end tabular-nums">{{ displayDecimals(row.delivery_cost) }}</td>
                    </tr>
                    <tr v-if="money.currencies.length === 0">
                        <td class="p-2 text-[var(--color-text-muted)]" colspan="7">{{ t('dashboard.money.none') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <label class="flex min-h-11 items-center gap-2 self-start">
            <input v-model="combine" type="checkbox" data-testid="dashboard-combine" />
            <span>{{ t('dashboard.money.combine') }}</span>
        </label>
        <div v-if="combine" class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-3" data-testid="dashboard-combined">
            <p class="tabular-nums">
                {{ t('dashboard.money.won_value') }}: {{ displayDecimals(money.combined.won_value) }} <bdi dir="ltr">{{ money.combined.currency }}</bdi>
                · {{ t('dashboard.money.gross_profit') }}: {{ displayDecimals(money.combined.gross_profit) }}
                · {{ t('dashboard.money.delivery_cost') }}: {{ displayDecimals(money.combined.delivery_cost) }}
            </p>
            <p v-if="money.combined.unconverted > 0" class="text-[var(--color-warning)]">{{ t('dashboard.money.unconverted', { count: money.combined.unconverted }) }}</p>
        </div>

        <section class="flex flex-col gap-2">
            <h2 class="font-semibold">{{ t('dashboard.money.monthly') }}</h2>
            <ul class="flex flex-col gap-2">
                <li v-for="bar in bars" :key="`${bar.month}-${bar.currency}`" class="flex flex-col gap-1" data-testid="dashboard-month-bar">
                    <span class="flex justify-between gap-2 text-table tabular-nums">
                        <bdi dir="ltr">{{ bar.month }} · {{ bar.currency }}</bdi>
                        <span>{{ displayDecimals(bar.won_value) }} / {{ displayDecimals(bar.gross_profit) }}</span>
                    </span>
                    <span class="h-2 rounded-full bg-[var(--color-surface-muted)]" aria-hidden="true">
                        <span class="block h-2 rounded-full bg-[var(--color-primary)]" :style="{ width: `${bar.width}%` }" />
                    </span>
                </li>
            </ul>
        </section>
    </div>
</template>
