<script setup lang="ts">
import { useI18n } from 'vue-i18n';

/**
 * §6.5 / `D-50`: the yellow badge, wherever the quotation is seen —
 * `Design System §6.4`'s warning tone, the word carrying the meaning.
 */
const { t } = useI18n();
</script>

<template>
    <span class="self-badge rounded-full px-2 py-1 text-table" data-testid="quotation-self-approved">
        {{ t('quotations.detail.selfApproved') }}
    </span>
</template>

<style scoped>
.self-badge {
    background-color: color-mix(in srgb, var(--color-warning) 14%, transparent);
    color: var(--color-warning);
}
</style>
