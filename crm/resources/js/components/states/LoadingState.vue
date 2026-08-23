<script setup lang="ts">
/**
 * Design System §8 lists loading among the five states every screen owes, and
 * §6.1 is specific about how: "Loading controls retain their width and show a
 * text alternative."
 *
 * Both halves matter. A spinner that collapses its container makes the page
 * jump when the data lands, and a spinner with no text says nothing to a screen
 * reader or to anyone who cannot see it move. §9.5 puts it plainly: a state
 * must be "visible without relying on color alone" — and an animation alone is
 * a weaker signal than a colour.
 *
 * The skeleton rows exist for the same reason as w-full: they hold the shape of
 * what is coming, so the layout does not lurch when it arrives. They are
 * decorative and hidden from assistive technology — the announced state is the
 * text, once, not a list of grey bars.
 */
import { useI18n } from 'vue-i18n';

withDefaults(defineProps<{
    /** Lang-file key, so a caller states what is loading rather than that something is. */
    labelKey?: string;
}>(), { labelKey: 'state.loading' });

const { t } = useI18n();
</script>

<template>
    <!-- role="status" with a polite live region is what makes this an announced
         state change (§8) rather than a picture of one. -->
    <div
        class="flex w-full flex-col items-center justify-center gap-4 p-8"
        role="status"
        aria-live="polite"
        data-testid="loading-state"
    >
        <div class="flex items-center gap-3">
            <svg
                class="size-5 shrink-0 animate-spin text-[var(--color-primary)] motion-reduce:animate-none"
                viewBox="0 0 20 20"
                aria-hidden="true"
                fill="none"
            >
                <!-- Two arcs rather than one: the track keeps the shape legible when
                     motion-reduce stops the animation, so the control still reads as
                     busy instead of as a broken glyph. -->
                <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-opacity="0.25" stroke-width="2.5" />
                <path d="M18 10a8 8 0 0 0-8-8" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
            </svg>

            <p class="text-[var(--color-text-muted)]">{{ t(labelKey) }}</p>
        </div>

        <div class="skeleton flex w-full max-w-md flex-col gap-2.5" aria-hidden="true">
            <span class="skeleton__bar h-3 w-full animate-pulse rounded-full motion-reduce:animate-none" />
            <span class="skeleton__bar h-3 w-5/6 animate-pulse rounded-full motion-reduce:animate-none" />
            <span class="skeleton__bar h-3 w-2/3 animate-pulse rounded-full motion-reduce:animate-none" />
        </div>
    </div>
</template>

<style scoped>
.skeleton__bar {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-border);
}

/* Each bar starts a little later than the one above it, so the group reads as
   one wave rather than three things blinking in lockstep. */
.skeleton__bar:nth-child(2) {
    animation-delay: 120ms;
}

.skeleton__bar:nth-child(3) {
    animation-delay: 240ms;
}
</style>
