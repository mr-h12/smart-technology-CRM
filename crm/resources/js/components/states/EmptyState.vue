<script setup lang="ts">
/**
 * Design System §2 asks a data list for "an empty state with the permitted next
 * action", and §8 lists empty among the states a screen is not complete
 * without.
 *
 * "Permitted" is why the action is a slot and not a prop. What a user may do
 * next is a permission decision, and SEC-09 keeps those on the server — this
 * component renders whatever the caller was allowed to offer, and renders
 * nothing when the caller was allowed to offer nothing. An empty list with a
 * disabled Create button is worse than an empty list with none: it tells the
 * user about a capability they do not have.
 *
 * The icon is neutral by §6.4: nothing has gone wrong here, and colouring an
 * empty result as a warning turns "no matches" into "a problem".
 */
import { useI18n } from 'vue-i18n';

withDefaults(defineProps<{
    titleKey?: string;
    messageKey?: string;
}>(), { titleKey: 'state.empty.title', messageKey: 'state.empty.message' });

const { t } = useI18n();
</script>

<template>
    <div
        class="flex w-full flex-col items-center justify-center gap-3 p-8 text-center"
        data-testid="empty-state"
    >
        <span class="state-plate grid size-14 place-items-center rounded-2xl" aria-hidden="true">
            <svg class="size-7 text-[var(--color-status-neutral)]" viewBox="0 0 20 20" aria-hidden="true" fill="currentColor">
                <path d="M3 5a1 1 0 0 1 1-1h5l2 2h5a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z" opacity="0.35" />
                <path d="M4 8h12a1 1 0 0 1 1 1v5a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z" />
            </svg>
        </span>

        <!-- A heading, not a styled paragraph: §8 asks for semantic headings, and
             this is the heading of the region that has no content. -->
        <div class="flex max-w-sm flex-col gap-1">
            <h2 class="text-card-title text-balance">{{ t(titleKey) }}</h2>
            <p class="text-[var(--color-text-muted)] text-pretty">{{ t(messageKey) }}</p>
        </div>

        <div class="mt-1 empty:hidden">
            <slot name="action" />
        </div>
    </div>
</template>

<style scoped>
.state-plate {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-border);
}
</style>
