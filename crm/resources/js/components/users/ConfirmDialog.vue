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
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';

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

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && props.open) {
        emit('cancel');
    }
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));

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
    <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
        data-testid="confirm-dialog"
    >
        <div class="dialog-scrim absolute inset-0" @click="emit('cancel')" />

        <div
            class="dialog-panel relative flex w-full max-w-md flex-col gap-4 rounded-2xl p-6"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="confirm-dialog-title"
            aria-describedby="confirm-dialog-message"
        >
            <h2 id="confirm-dialog-title" class="text-card-title">{{ t(titleKey) }}</h2>
            <p id="confirm-dialog-message" class="text-[var(--color-text-muted)] text-pretty">
                {{ t(messageKey, { name: subject }) }}
            </p>

            <div class="flex flex-wrap justify-end gap-2">
                <button
                    type="button"
                    class="dialog-cancel min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :disabled="busy"
                    data-testid="confirm-cancel"
                    @click="emit('cancel')"
                >
                    {{ t('action.cancel') }}
                </button>

                <button
                    ref="confirmButton"
                    type="button"
                    class="dialog-confirm min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :class="danger === true ? 'dialog-confirm--danger' : ''"
                    :disabled="busy"
                    data-testid="confirm-accept"
                    @click="emit('confirm')"
                >
                    {{ t(confirmKey) }}
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
