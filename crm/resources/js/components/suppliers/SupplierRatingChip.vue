<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * §7.1's colour chip — "beside the supplier name on **every** screen".
 *
 * ── The word is the requirement ────────────────────────────────────────────
 *
 * Design System §6.4 closes its badge table with "never color alone", and
 * names these four as "supplier rating chips only" — so they are not reused
 * for a generic status. The label is therefore always rendered, never a
 * colour-only dot, and it is the accessible name too: `title` repeats it for a
 * pointer, and nothing here conveys meaning by hue alone.
 *
 * §7.1's meanings: 🟢 excellent · 🟡 average · 🔴 problematic · ⚪ not yet rated.
 *
 * ── Four classes, not four inline colours ──────────────────────────────────
 *
 * Each rating gets a modifier and the colour lives in a token, which is what
 * keeps the chip correct in both themes without this component knowing there
 * are two.
 */

const props = defineProps<{ rating: 'green' | 'yellow' | 'red' | 'white' }>();

const { t } = useI18n();

const label = computed(() => t(`suppliers.rating.${props.rating}`));
</script>

<template>
    <span
        class="rating-chip rounded-full px-2 py-0.5 text-table"
        :class="`rating-chip--${props.rating}`"
        :title="label"
        data-testid="supplier-rating"
    >
        {{ label }}
    </span>
</template>

<style scoped>
.rating-chip {
    border: 1px solid transparent;
    display: inline-block;
    white-space: nowrap;
}

/* §7.1's green — "excellent". `success` is the token §6.4 already spends on a
   positive state, and the chip is what §6.4 restricts it to here. */
.rating-chip--green {
    background-color: color-mix(in srgb, var(--color-success) 14%, transparent);
    color: var(--color-success);
}

.rating-chip--yellow {
    background-color: color-mix(in srgb, var(--color-warning) 16%, transparent);
    color: var(--color-warning);
}

.rating-chip--red {
    background-color: color-mix(in srgb, var(--color-danger) 12%, transparent);
    color: var(--color-danger);
}

/* ⚪ "new / not yet rated" — the absence of a rating, so it borrows no status
   colour at all. A white fill on a white surface would be invisible, so the
   border carries the shape and the muted text carries the word. */
.rating-chip--white {
    background-color: var(--color-surface-muted);
    border-color: var(--color-border-strong);
    color: var(--color-text-muted);
}
</style>
