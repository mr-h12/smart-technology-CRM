<script setup lang="ts">
import { useI18n } from 'vue-i18n';

/**
 * A report's status word (Module 13 · 7.1), written once for the lists and the
 * detail page; `missed` alone carries the warning tone. The caller's
 * `data-testid` falls through onto the span.
 */

defineProps<{ status: string }>();

const { t } = useI18n();
</script>

<template>
    <span
        class="rounded-full bg-[var(--color-surface-muted)] px-2.5 py-0.5 text-table"
        :class="{ 'text-[var(--color-warning)]': status === 'missed' }"
    >{{ t(`reports.status.${status}`) }}</span>
</template>
