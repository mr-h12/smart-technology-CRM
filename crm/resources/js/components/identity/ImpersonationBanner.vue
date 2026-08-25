<script setup lang="ts">
/**
 * `SEC-10` — "Login As restricted to Super Admin, with mandatory logging."
 *
 * ── Why this component is the most important part of Point 3.4 ─────────────
 *
 * An impersonation session runs with the target's id, role and permissions.
 * That is the feature, and it is also the hazard: every screen looks exactly
 * as it does for that employee, so a Super Admin who forgets they are inside
 * somebody else's account will read the system as that person and act as them.
 * The audit trail records the truth either way (`impersonated_user_id`, Point
 * 3.4) — this is what keeps the person doing it from being the last to know.
 *
 * So it is deliberately **not** dismissible. A banner with a close button is a
 * banner that is closed, and the thing it warns about lasts until `D-29`'s
 * eight idle hours expire the session.
 *
 * §6.4's warning treatment, not danger: nothing has failed. `role="status"` and
 * a polite live region, because it appears as the result of an action the
 * person just took and §9.5 requires a state to be legible without relying on
 * colour alone — the text says it too.
 */
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { useAuth } from '@/stores/auth';
import { FALLBACK_ROUTE, landingRouteFor } from '@/router';

const { t } = useI18n();
const router = useRouter();
const auth = useAuth();

/**
 * Ends the Login As and returns the Super Admin to their own landing screen.
 *
 * Their own — not the one they were looking at. The screen they were on may be
 * the impersonated employee's and may not exist for them, and a redirect into a
 * route their role cannot enter would bounce straight to the denial screen.
 */
async function leave(): Promise<void> {
    await auth.leaveImpersonation();

    const target = auth.isAuthenticated.value
        ? landingRouteFor(auth.role.value, (name) => router.hasRoute(name))
        : FALLBACK_ROUTE;

    await router.replace({ name: target });
}
</script>

<template>
    <div
        v-if="auth.isImpersonating.value"
        class="impersonation-banner flex flex-wrap items-center justify-between gap-3 px-4 py-2.5"
        role="status"
        aria-live="polite"
        data-testid="impersonation-banner"
    >
        <p class="flex min-w-0 items-center gap-2">
            <svg class="size-5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M10 2a4 4 0 1 0 0 8 4 4 0 0 0 0-8zm-7 15c0-3 3.1-5 7-5s7 2 7 5v1H3z" />
            </svg>
            <span class="min-w-0">
                {{ t('impersonation.banner', {
                    name: auth.impersonating.value?.name ?? '',
                    role: auth.impersonating.value?.role ?? '',
                }) }}
            </span>
        </p>

        <button
            type="button"
            class="impersonation-leave min-h-11 shrink-0 rounded-lg px-3 py-1.5 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
            :disabled="auth.pending.value"
            data-testid="impersonation-leave"
            @click="leave"
        >
            {{ t('impersonation.leave') }}
        </button>
    </div>
</template>

<style scoped>
.impersonation-banner {
    background-color: var(--color-warning);
    color: var(--color-text-inverse);
    /* The block-end border, not a bottom one: the banner sits above the shell
       in both directions, and the block axis does not flip with `dir`. */
    border-block-end: 1px solid var(--color-border-strong);
}

.impersonation-leave {
    background-color: var(--color-surface);
    color: var(--color-text);
    border: 1px solid var(--color-border-strong);
}

.impersonation-leave:hover:not(:disabled) {
    background-color: var(--color-surface-muted);
}
</style>
