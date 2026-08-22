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
        class="flex w-full flex-col items-center justify-center gap-2 p-8 text-center"
        role="alert"
        data-testid="error-state"
    >
        <svg class="size-8 text-[var(--color-danger)]" viewBox="0 0 20 20" aria-hidden="true" fill="currentColor">
            <path d="M10 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16zm-1 4h2v6H9zm0 8h2v2H9z" />
        </svg>

        <h2 class="text-card-title text-[var(--color-danger)]">{{ t(titleKey) }}</h2>
        <p class="text-[var(--color-text-muted)]">{{ t(messageKey) }}</p>

        <!-- Diagnostic detail, when the caller has one. Data, not copy. -->
        <div class="empty:hidden text-table text-[var(--color-text-muted)]">
            <slot name="detail" />
        </div>

        <button
            v-if="retryable"
            type="button"
            class="mt-2 inline-flex min-h-11 min-w-11 items-center justify-center rounded-md border border-[var(--color-border)] px-4 text-[var(--color-text)] hover:bg-[var(--color-surface-muted)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)]"
            data-testid="error-retry"
            @click="$emit('retry')"
        >
            {{ t('state.retry') }}
        </button>
    </div>
</template>
