<script setup lang="ts">
/**
 * `D-133` (Module 13, slice 3 · 3.3) — a customer's «المتابعات»: the follow-ups of each
 * submitted daily report, read-only, in the server's order (newest first). Who may see
 * them is the server's `customer.view` at the customer's row scope.
 */
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { displayTimestamp } from '@/domain/displayDate';
import { listCustomerFollowUps, type CustomerFollowUp } from '@/services/customers';

const props = defineProps<{ customerId: string }>();

const { t, locale } = useI18n();

const followUps = ref<CustomerFollowUp[] | null>(null);
const failed = ref(false);

async function load(): Promise<void> {
    failed.value = false;

    try {
        followUps.value = await listCustomerFollowUps(props.customerId);
    } catch {
        followUps.value = null;
        failed.value = true;
    }
}

onMounted(load);
</script>

<template>
    <section class="detail-card flex flex-col gap-2 rounded-xl p-4" data-testid="customer-follow-ups-section">
        <h2 class="text-card-title">{{ t('customers.followUps.title') }}</h2>
        <div v-if="failed" class="flex flex-wrap items-center gap-2" role="alert" data-testid="customer-follow-ups-error">
            <p class="text-[var(--color-danger)]">{{ t('customers.followUps.loadFailed') }}</p>
            <button type="button" class="row-action min-h-11 rounded-lg px-4" @click="load">{{ t('state.retry') }}</button>
        </div>
        <p v-else-if="followUps === null" class="text-[var(--color-text-muted)]">{{ t('state.loading') }}</p>
        <p v-else-if="followUps.length === 0" class="text-[var(--color-text-muted)] text-pretty" data-testid="customer-follow-ups-empty">
            {{ t('customers.followUps.empty') }}
        </p>
        <ul v-else class="flex flex-col divide-y divide-[var(--color-border)]">
            <li v-for="(followUp, index) in followUps" :key="index" class="flex flex-col gap-1 py-3" data-testid="customer-follow-up-row">
                <div class="flex flex-wrap items-baseline gap-x-3 text-sm text-[var(--color-text-muted)]">
                    <time :datetime="followUp.followed_up_at" class="tabular-nums">{{ displayTimestamp(followUp.followed_up_at, locale) }}</time>
                    <span v-if="followUp.author_name !== null" dir="auto">{{ followUp.author_name }}</span>
                </div>
                <!-- A note runs in its own language's direction, whichever the screen is in. -->
                <p dir="auto" class="whitespace-pre-line text-pretty [overflow-wrap:anywhere]">{{ followUp.note }}</p>
            </li>
        </ul>
    </section>
</template>
