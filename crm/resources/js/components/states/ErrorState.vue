<script setup lang="ts">
/**
 * Design System §6.4 gives danger its presentation: "Red icon + label; mandatory
 * reason/action is clear." Both halves are load-bearing. §9.5 requires the state
 * to be "visible without relying on color alone", so the icon and the text carry
 * the meaning and the red is only reinforcement — which is also what makes this
 * legible in all three themes and to anyone who cannot distinguish it.
 *
 * The retry control is the "action" half. §6.6 says a toast "must not be the
 * only place an error is explained"; this is that other place, and it stays on
 * screen until the user does something about it.
 *
 * The optional detail slot exists because Ping.vue already needed one: a raw
 * exception message is diagnostic data, not a user-facing string, and it belongs
 * beside the translated explanation rather than instead of it.
 */
import { useI18n } from 'vue-i18n';

withDefaults(defineProps<{
    titleKey?: string;
    messageKey?: string;
    /** Whether to offer a retry. A 403 is not retryable; a failed fetch is. */
    retryable?: boolean;
}>(), { titleKey: 'state.error.title', messageKey: 'state.error.message', retryable: true });

defineEmits<{ retry: [] }>();

const { t } = useI18n();
</script>

<template>
    <!-- role="alert" is an assertive live region: an error that appears after the
         page has settled has to interrupt, or a screen-reader user carries on
         waiting for data that will never arrive (§8). -->
    <div
        class="flex w-full flex-col items-center justify-center gap-3 p-8 text-center"
        role="alert"
        data-testid="error-state"
    >
        <span class="state-plate state-plate--danger grid size-14 place-items-center rounded-2xl" aria-hidden="true">
            <svg class="size-7 text-[var(--color-danger)]" viewBox="0 0 20 20" aria-hidden="true" fill="currentColor">
                <path d="M10 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16zm-1 4h2v6H9zm0 8h2v2H9z" />
            </svg>
        </span>

        <div class="flex max-w-sm flex-col gap-1">
            <h2 class="text-card-title text-balance text-[var(--color-danger)]">{{ t(titleKey) }}</h2>
            <p class="text-[var(--color-text-muted)] text-pretty">{{ t(messageKey) }}</p>
        </div>

        <!-- Diagnostic detail, when the caller has one. Data, not copy. -->
        <div class="detail empty:hidden max-w-full text-table text-[var(--color-text-muted)]">
            <slot name="detail" />
        </div>

        <button
            v-if="retryable"
            type="button"
            class="action mt-1 inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-lg border border-[var(--color-border-strong)] px-4 text-[var(--color-text)] hover:bg-[var(--color-surface-muted)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)]"
            data-testid="error-retry"
            @click="$emit('retry')"
        >
            <svg viewBox="0 0 20 20" class="size-4" aria-hidden="true" fill="currentColor">
                <path d="M10 3a7 7 0 1 0 6.3 4h-2.2A5 5 0 1 1 10 5v2.5L14 4.2 10 1z" />
            </svg>
            {{ t('state.retry') }}
        </button>
    </div>
</template>

<style scoped>
.state-plate {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-border);
}

.state-plate--danger {
    border-color: var(--color-danger);
}

/* An exception message is one long unbroken token often enough that leaving it
   to wrap normally pushes the whole card sideways. */
.detail {
    overflow-wrap: anywhere;
    font-family: var(--font-mono);
}

.action {
    touch-action: manipulation;
    -webkit-tap-highlight-color: transparent;
    transition-property: background-color, color;
    transition-duration: 160ms;
    transition-timing-function: ease-out;
}

@media (prefers-reduced-motion: reduce) {
    .action {
        transition: none;
    }
}
</style>
