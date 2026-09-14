<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * §6.1's nine statuses drawn as `Design System §6.4`'s four tones — "never
 * color alone": the word is the meaning and the whole accessible name, the dot
 * is the glance. The shape is `SupplierRatingChip`'s; the palette is §6.4's
 * generic one, which that component's rule forbids reusing for ratings and
 * which is exactly what a workflow status is for.
 *
 * §6.4's table, read literally: Success = Approved, Accepted · Warning =
 * Pending, expired · Danger = Rejected · Info = Draft and "neutral workflow
 * guidance", which is where Sent, Partial and Counter fall — none of the three
 * is a success, a wait or a refusal on its own.
 */

const props = defineProps<{ status: string }>();

const { t } = useI18n();

const TONES: Record<string, 'success' | 'warning' | 'danger' | 'info'> = {
    approved: 'success',
    accepted: 'success',
    pending: 'warning',
    expired: 'warning',
    rejected: 'danger',
};

const tone = computed(() => TONES[props.status] ?? 'info');
const label = computed(() => t(`quotations.status.${props.status}`));
</script>

<template>
    <span
        class="status-chip inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-table"
        :class="`status-chip--${tone}`"
        :title="label"
        data-testid="quotation-status"
    >
        <svg class="status-dot" viewBox="0 0 8 8" aria-hidden="true" focusable="false" data-testid="quotation-status-dot">
            <circle cx="4" cy="4" r="4" fill="currentColor" />
        </svg>
        {{ label }}
    </span>
</template>

<style scoped>
.status-chip {
    background-color: color-mix(in srgb, currentColor 14%, transparent);
}

.status-dot {
    inline-size: 0.5rem;
    block-size: 0.5rem;
}

.status-chip--success {
    color: var(--color-success);
}

.status-chip--warning {
    color: var(--color-warning);
}

.status-chip--danger {
    color: var(--color-danger);
}

.status-chip--info {
    color: var(--color-info);
}
</style>
