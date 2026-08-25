<script setup lang="ts">
/**
 * The confirmation in front of `PATCH /roles/{id}/permissions`.
 *
 * ── Why a diff and not "Save these permissions?" ───────────────────────────
 *
 * The request body is the **complete** desired grant set, so an unchecked box
 * halfway down a 143-row grid and a box that was never checked look identical
 * to the endpoint. The one thing an administrator cannot see from the grid is
 * what they *changed* — and §3.12 rule 5 makes this the screen that alters who
 * may do what across the whole system, with no deployment to notice it. §6.6:
 * an action shows its consequence before submission.
 *
 * The triples listed here are the same strings `AUD-02` stores in the audit
 * row's old/new values, sorted the same way, so the confirmation and the log
 * are readable against each other.
 *
 * ── It confirms; it does not decide ────────────────────────────────────────
 *
 * No request is made here and no rule is evaluated. The parent owns the call,
 * and the server owns every refusal — §3.1's immutable role, §3.12 rule 3's
 * forbidden action, and the permission that no longer exists.
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
    open: boolean;
    roleName: string;
    /** Sorted triples, from `diffGrants`. */
    granted: readonly string[];
    revoked: readonly string[];
    busy: boolean;
}>();

const emit = defineEmits<{ confirm: []; cancel: [] }>();

const { t } = useI18n();
const confirmButton = ref<HTMLButtonElement | null>(null);

// §6.1: "Escape closes dialogs/menus without discarding silently." Closing this
// returns to the staged grid — the changes are still there, unsent.
function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && props.open && !props.busy) {
        emit('cancel');
    }
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));

watch(() => props.open, async (open) => {
    if (open) {
        await new Promise((resolve) => setTimeout(resolve, 0));
        confirmButton.value?.focus();
    }
});
</script>

<template>
    <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
        data-testid="permission-diff-modal"
    >
        <div class="dialog-scrim absolute inset-0" @click="busy ? null : emit('cancel')" />

        <div
            class="dialog-panel relative flex max-h-full w-full max-w-lg flex-col gap-4 overflow-y-auto rounded-2xl p-6"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="permission-diff-title"
            aria-describedby="permission-diff-summary"
        >
            <h2 id="permission-diff-title" class="text-card-title">
                {{ t('roles.diff.title', { role: roleName }) }}
            </h2>

            <p id="permission-diff-summary" class="tabular-nums text-[var(--color-text-muted)] text-pretty">
                {{ t('roles.diff.summary', { granted: granted.length, revoked: revoked.length }) }}
            </p>

            <!-- Both lists are always rendered, empty included. A modal that
                 hides the revoked section when nothing is revoked reads as "no
                 revocations" and as "the section was forgotten" in exactly the
                 same way. -->
            <section class="flex flex-col gap-2" data-testid="diff-granted">
                <h3 class="diff-heading diff-heading--granted">
                    {{ t('roles.diff.granted', { count: granted.length }) }}
                </h3>

                <ul v-if="granted.length > 0" class="flex flex-col gap-1">
                    <li v-for="triple in granted" :key="triple" class="triple">{{ triple }}</li>
                </ul>
                <p v-else class="text-[var(--color-text-muted)]">{{ t('roles.diff.none') }}</p>
            </section>

            <section class="flex flex-col gap-2" data-testid="diff-revoked">
                <h3 class="diff-heading diff-heading--revoked">
                    {{ t('roles.diff.revoked', { count: revoked.length }) }}
                </h3>

                <ul v-if="revoked.length > 0" class="flex flex-col gap-1">
                    <li v-for="triple in revoked" :key="triple" class="triple">{{ triple }}</li>
                </ul>
                <p v-else class="text-[var(--color-text-muted)]">{{ t('roles.diff.none') }}</p>
            </section>

            <!-- §3.12 rule 5, said out loud: this takes effect on the next
                 request, with nothing to restart. -->
            <p class="text-[var(--color-text-muted)] text-pretty">{{ t('roles.diff.effect') }}</p>

            <div class="flex flex-wrap justify-end gap-2">
                <button
                    type="button"
                    class="dialog-cancel min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :disabled="busy"
                    data-testid="diff-cancel"
                    @click="emit('cancel')"
                >
                    {{ t('action.cancel') }}
                </button>

                <button
                    ref="confirmButton"
                    type="button"
                    class="dialog-confirm min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="busy"
                    data-testid="diff-confirm"
                    @click="emit('confirm')"
                >
                    {{ busy ? t('action.saving') : t('roles.diff.confirm') }}
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.dialog-scrim {
    background-color: color-mix(in srgb, var(--color-text) 45%, transparent);
}

.dialog-panel {
    background-color: var(--color-surface-raised);
    border: 1px solid var(--color-border);
    box-shadow: var(--shadow-3, var(--shadow-2));
}

.diff-heading {
    font-weight: 600;
}

/* §6.4 and §9.5: the heading carries its own word and its own count, so the
   colour is an accent on a distinction that is already legible without it. */
.diff-heading--granted {
    color: var(--color-success);
}

.diff-heading--revoked {
    color: var(--color-danger);
}

.triple {
    font-family: var(--font-mono, ui-monospace, monospace);
    padding-block: 0.125rem;
}

.dialog-cancel {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.dialog-confirm {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
}
</style>
