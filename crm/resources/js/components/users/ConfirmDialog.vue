<script setup lang="ts">
/**
 * The confirmation `D-34` asks for before an account's `is_active` is flipped.
 *
 * §6.1: "Escape closes dialogs/menus without discarding silently" — there is
 * nothing to discard here, so Escape simply cancels. §8's five states are the
 * caller's; this is the question, not the outcome.
 *
 * Deliberately generic and text-driven: it takes lang-file keys rather than
 * prose, so the same dialog serves deactivation and reactivation without either
 * message being written into a component.
 */
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useModalDialog } from '@/components/useModalDialog';

const props = defineProps<{
    open: boolean;
    titleKey: string;
    messageKey: string;
    confirmKey: string;
    /** Interpolated into the message — the account's name. */
    subject: string;
    busy: boolean;
    /** Warning rather than danger: `D-34` deactivates, it never deletes. */
    danger?: boolean;
}>();

const emit = defineEmits<{ confirm: []; cancel: [] }>();

const { t } = useI18n();
const confirmButton = ref<HTMLButtonElement | null>(null);
const dialog = ref<HTMLDialogElement | null>(null);

useModalDialog(dialog);

/** A-30: while busy the request is in flight, so neither Escape nor the scrim walks away from it. */
function cancel(): void {
    if (!props.busy) {
        emit('cancel');
    }
}

// §8's keyboard path: opening a dialog and leaving focus behind it means the
// next Tab walks the page underneath.
watch(() => props.open, async (open) => {
    if (open) {
        await new Promise((resolve) => setTimeout(resolve, 0));
        confirmButton.value?.focus();
    }
});
</script>

<template>
    <!-- A-04: Escape arrives as the dialog's own `cancel`; prevented, so `open` stays the parent's to change. -->
    <dialog
        v-if="open"
        ref="dialog"
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
        role="alertdialog"
        aria-labelledby="confirm-dialog-title"
        aria-describedby="confirm-dialog-message"
        data-testid="confirm-dialog"
        @cancel.prevent="cancel"
    >
        <div class="dialog-scrim absolute inset-0" @click="cancel" />

        <div class="dialog-panel relative flex w-full max-w-md flex-col gap-4 rounded-2xl p-6">
            <h2 id="confirm-dialog-title" class="text-card-title">{{ t(titleKey) }}</h2>
            <p id="confirm-dialog-message" class="text-[var(--color-text-muted)] text-pretty">
                {{ t(messageKey, { name: subject }) }}
            </p>

            <div class="flex flex-wrap justify-end gap-2">
                <button
                    type="button"
                    class="dialog-cancel min-h-11 rounded-lg px-4 py-2"
                    :disabled="busy"
                    data-testid="confirm-cancel"
                    @click="emit('cancel')"
                >
                    {{ t('action.cancel') }}
                </button>

                <button
                    ref="confirmButton"
                    type="button"
                    class="dialog-confirm min-h-11 rounded-lg px-4 py-2 disabled:cursor-not-allowed disabled:opacity-60"
                    :class="danger === true ? 'dialog-confirm--danger' : ''"
                    :disabled="busy"
                    data-testid="confirm-accept"
                    @click="emit('confirm')"
                >
                    {{ busy ? t('action.saving') : t(confirmKey) }}
                </button>
            </div>
        </div>
    </dialog>
</template>

<style scoped>
.dialog-cancel {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.dialog-confirm {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
}

.dialog-confirm--danger {
    background-color: var(--color-danger);
    color: var(--color-text-inverse);
}
</style>
