<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * §7.1's colour chip — "beside the supplier name on **every** screen".
 *
 * ── §6.4 asks for an icon AND text, and Point 4.0 delivered only the text ──
 *
 * The rule is one sentence: "Success … **Green icon + text/chip**; never color
 * alone", and "Danger … **Red icon** + label". Point 4.0 read the second half
 * and shipped the word by itself, tinted at 14% — which over white computes to
 * `#DEE9E3` for `--color-success`'s `#166534`, a grey. §7.1 says "The colour
 * **appears** as a chip", and it did not: on screen it was indistinguishable
 * from the neutral status chip in the next column. Fixed at Point 5.0, after
 * the owner found it on the running screen and no test could.
 *
 * So the chip now carries both halves. The **word** remains the meaning and the
 * whole accessible name — `title` repeats it, and the circle is `aria-hidden`
 * so it adds nothing for a screen reader. The **circle** is §6.4's icon, and it
 * is what makes the rating legible at a glance in a table.
 *
 * §7.1's meanings: 🟢 excellent · 🟡 average · 🔴 problematic · ⚪ not yet rated.
 *
 * ── Four classes, not four inline colours ──────────────────────────────────
 *
 * Each rating gets a modifier and the colour lives in a token, which is what
 * keeps the chip correct in both themes without this component knowing there
 * are two. The circle takes `currentColor`, so it needs no class of its own and
 * cannot drift from the word beside it.
 */

const props = defineProps<{ rating: 'green' | 'yellow' | 'red' | 'white' }>();

const { t } = useI18n();

const label = computed(() => t(`suppliers.rating.${props.rating}`));
</script>

<template>
    <span
        class="rating-chip inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-table"
        :class="`rating-chip--${props.rating}`"
        :title="label"
        data-testid="supplier-rating"
    >
        <!-- §6.4's icon half. `aria-hidden`, because the word beside it is
             already the meaning and announcing both would say it twice. -->
        <svg
            class="rating-dot"
            viewBox="0 0 8 8"
            aria-hidden="true"
            focusable="false"
            data-testid="supplier-rating-dot"
        >
            <circle cx="4" cy="4" r="4" fill="currentColor" />
        </svg>
        {{ label }}
    </span>
</template>

<style scoped>
.rating-chip {
    border: 1px solid;
    white-space: nowrap;
}

/* Sized in `em` so the circle tracks the label rather than a fixed pixel size,
   and `flex-shrink` guards it in a narrow table cell. */
.rating-dot {
    block-size: 0.5em;
    flex-shrink: 0;
    inline-size: 0.5em;
}

/* §7.1's green — "excellent". `success` is the token §6.4 already spends on a
   positive state, and the chip is what §6.4 restricts it to here.

   The percentages are the Point 5.0 repair. At 14% over white this rule
   produced `#DEE9E3`; the fill now carries the hue and the border states it
   outright, so the chip reads as green in a row of neutral chips. Both are
   mixes of the same token, so the dark theme follows without a second rule. */
.rating-chip--green {
    background-color: color-mix(in srgb, var(--color-success) 20%, transparent);
    border-color: color-mix(in srgb, var(--color-success) 45%, transparent);
    color: var(--color-success);
}

.rating-chip--yellow {
    background-color: color-mix(in srgb, var(--color-warning) 22%, transparent);
    border-color: color-mix(in srgb, var(--color-warning) 45%, transparent);
    color: var(--color-warning);
}

.rating-chip--red {
    background-color: color-mix(in srgb, var(--color-danger) 18%, transparent);
    border-color: color-mix(in srgb, var(--color-danger) 45%, transparent);
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
