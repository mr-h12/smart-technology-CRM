<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * §6.1's nine statuses drawn as `Design System §6.4`'s four tones — "never
 * color alone": the word is the meaning and the whole accessible name, with no
 * dot (A-55); the recipe is app.css's `.status-chip`. The palette is §6.4's
 * generic one, which `SupplierRatingChip`'s rule forbids reusing for ratings and
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
        class="status-chip inline-flex items-center rounded-full px-2 py-0.5 text-table"
        :class="`status-chip--${tone}`"
        :title="label"
        data-testid="quotation-status"
    >
        {{ label }}
    </span>
</template>

