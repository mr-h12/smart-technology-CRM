<script setup lang="ts">
/**
 * The visual half of a 403. SEC-09 and §9.3 are the constraint: hiding or
 * disabling is "a visual complement to API enforcement", never the enforcement
 * itself — the server already refused before this rendered.
 *
 * What this component deliberately cannot do is as important as what it does.
 * It takes no props. There is no permission name to display, no resource, no
 * route, no identity, no "request access" affordance and no way to ask again —
 * a 403 does not become a 200 by repeating it. A denial screen that names what
 * was denied turns an access-control boundary into an enumeration oracle: it
 * confirms the record exists and tells the reader what it is, which is precisely
 * what the refusal was protecting. So the message is fixed and says nothing
 * about the thing refused.
 *
 * §6.4 keeps it to icon + text, and it is warning rather than danger: nothing
 * failed and nothing is broken. The user is simply outside the boundary.
 */
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>

<template>
    <div
        class="flex w-full flex-col items-center justify-center gap-3 p-8 text-center"
        role="status"
        data-testid="permission-denied-state"
    >
        <span class="state-plate grid size-14 place-items-center rounded-2xl" aria-hidden="true">
            <svg class="size-7 text-[var(--color-warning)]" viewBox="0 0 20 20" aria-hidden="true" fill="currentColor">
                <path d="M10 2a4 4 0 0 0-4 4v2H5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V9a1 1 0 0 0-1-1h-1V6a4 4 0 0 0-4-4zm0 2a2 2 0 0 1 2 2v2H8V6a2 2 0 0 1 2-2z" />
            </svg>
        </span>

        <div class="flex max-w-sm flex-col gap-1">
            <h2 class="text-card-title text-balance">{{ t('state.denied.title') }}</h2>
            <p class="text-[var(--color-text-muted)] text-pretty">{{ t('state.denied.message') }}</p>
        </div>
    </div>
</template>

<style scoped>
.state-plate {
    background-color: var(--color-surface-muted);
    border-color: var(--color-warning);
    border-width: 1px;
    border-style: solid;
}
</style>
