<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * `D-109`: a deal's status as `Design System §6.4`'s chip — success for Won and
 * Delivery complete, danger for Lost, info for the rest. The word is the
 * meaning; the shape is `QuotationStatusChip`'s, without its dot (the owner,
 * 2026-10-12: the word alone, as on the customers' chip).
 */

const props = defineProps<{ status: string }>();

const { t } = useI18n();

const TONES: Record<string, 'success' | 'danger'> = {
    won: 'success',
    delivery_complete: 'success',
    lost: 'danger',
};

const tone = computed(() => TONES[props.status] ?? 'info');
</script>

<template>
    <span
        class="status-chip inline-flex w-fit items-center whitespace-nowrap rounded-full px-2 py-0.5"
        :class="`status-chip--${tone}`"
    >
        {{ t(`deals.status.${props.status}`) }}
    </span>
</template>

<style scoped>
.status-chip {
    background-color: color-mix(in srgb, currentColor 14%, transparent);
}

.status-chip--success {
    color: var(--color-success);
}

.status-chip--danger {
    color: var(--color-danger);
}

.status-chip--info {
    color: var(--color-info);
}
</style>
