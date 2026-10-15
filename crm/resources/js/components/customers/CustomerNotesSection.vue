<script setup lang="ts">
/**
 * `D-122` (F-50 · 3) — a customer's notes as separate entries, each with an
 * optional date that is a label only (`D-15` stays deferred).
 *
 * The list is the server's, in the server's order (newest date first, undated
 * last); nothing here re-sorts it (`D-67`). Writing needs `customer.edit`,
 * which the page decides and passes in; the server checks it again at the
 * customer's scope. After every write the list is read again, so what shows is
 * what the server holds.
 */
import { onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import {
    addCustomerNote,
    listCustomerNotes,
    removeCustomerNote,
    updateCustomerNote,
    type CustomerNote,
} from '@/services/customers';

const props = defineProps<{ customerId: string; canEdit: boolean }>();

const { t } = useI18n();

const notes = ref<CustomerNote[] | null>(null);
const failed = ref(false);

/** `null`: no form; `'new'`: adding; a note id: editing that note. */
const editing = ref<string | null>(null);
const body = ref('');
const noteDate = ref('');
const saving = ref(false);
const bodyError = ref<string | null>(null);
const formError = ref<string | null>(null);

/** The note whose removal is waiting for its confirm. */
const confirmingRemove = ref<string | null>(null);

async function load(): Promise<void> {
    failed.value = false;

    try {
        notes.value = (await listCustomerNotes(props.customerId)).items;
    } catch {
        notes.value = null;
        failed.value = true;
    }
}

function open(note: CustomerNote | null): void {
    editing.value = note?.id ?? 'new';
    body.value = note?.body ?? '';
    noteDate.value = note?.note_date ?? '';
    bodyError.value = null;
    formError.value = null;
    confirmingRemove.value = null;
}

function close(): void {
    editing.value = null;
}

async function save(): Promise<void> {
    if (saving.value || editing.value === null) {
        return;
    }

    saving.value = true;
    bodyError.value = null;
    formError.value = null;

    const draft = { body: body.value.trim(), note_date: noteDate.value === '' ? null : noteDate.value };

    try {
        if (editing.value === 'new') {
            await addCustomerNote(props.customerId, draft);
        } else {
            await updateCustomerNote(props.customerId, editing.value, draft);
        }

        close();
        await load();
    } catch (error) {
        // The server's sentence under the field it names; anything else above the buttons.
        bodyError.value = error instanceof ApiError ? error.messageFor('body') : null;
        const dateError = error instanceof ApiError ? error.messageFor('note_date') : null;
        formError.value = bodyError.value !== null ? null : (dateError ?? t('customers.notes.saveFailed'));
    } finally {
        saving.value = false;
    }
}

async function remove(noteId: string): Promise<void> {
    if (saving.value) {
        return;
    }

    saving.value = true;

    try {
        await removeCustomerNote(props.customerId, noteId);
        confirmingRemove.value = null;
        await load();
    } catch {
        formError.value = t('customers.notes.removeFailed');
    } finally {
        saving.value = false;
    }
}

onMounted(load);
watch(() => props.customerId, load);
</script>

<template>
    <section class="detail-card flex flex-col gap-2 rounded-xl p-4" data-testid="customer-notes-section">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-card-title">{{ t('customers.notes.title') }}</h2>
            <button
                v-if="canEdit && editing !== 'new'"
                type="button"
                class="create-action min-h-11 rounded-lg px-4"
                data-testid="customer-note-add"
                @click="open(null)"
            >
                {{ t('customers.notes.add') }}
            </button>
        </div>

        <form v-if="editing !== null" class="note-form flex flex-col gap-3 rounded-lg p-3" novalidate data-testid="customer-note-form" @submit.prevent="save">
            <label class="flex flex-col gap-1.5" for="customer-note-body">
                <span>{{ t('customers.notes.body') }}</span>
                <textarea
                    id="customer-note-body"
                    v-model="body"
                    rows="4"
                    maxlength="5000"
                    :disabled="saving"
                    :aria-invalid="bodyError !== null"
                    :aria-describedby="bodyError !== null ? 'customer-note-body-error' : undefined"
                    class="form-field rounded-lg px-3 py-2"
                    data-testid="customer-note-body"
                />
                <span v-if="bodyError !== null" id="customer-note-body-error" class="text-[var(--color-danger)]" data-testid="customer-note-body-error">
                    {{ bodyError }}
                </span>
            </label>
            <label class="flex flex-col gap-1.5 sm:max-w-56" for="customer-note-date">
                <span>{{ t('customers.notes.date') }}</span>
                <input
                    id="customer-note-date"
                    v-model="noteDate"
                    type="date"
                    :disabled="saving"
                    class="form-field min-h-11 rounded-lg px-3 py-2"
                    data-testid="customer-note-date"
                />
            </label>
            <p v-if="formError !== null" class="text-[var(--color-danger)]" role="alert">{{ formError }}</p>
            <div class="flex flex-wrap gap-2">
                <button
                    type="submit"
                    class="create-action min-h-11 rounded-lg px-4 py-2 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="saving"
                >
                    {{ t('customers.notes.save') }}
                </button>
                <button type="button" class="row-action min-h-11 rounded-lg px-4 py-2" :disabled="saving" @click="close">
                    {{ t('customers.notes.cancel') }}
                </button>
            </div>
        </form>

        <div v-if="failed" class="flex flex-wrap items-center gap-2" role="alert" data-testid="customer-notes-error">
            <p class="text-[var(--color-danger)]">{{ t('customers.notes.loadFailed') }}</p>
            <button type="button" class="row-action min-h-11 rounded-lg px-4" @click="load">{{ t('customers.notes.retry') }}</button>
        </div>
        <p v-else-if="notes === null" class="text-[var(--color-text-muted)]" data-testid="customer-notes-loading">{{ t('customers.notes.loading') }}</p>
        <p v-else-if="notes.length === 0" class="text-[var(--color-text-muted)] text-pretty" data-testid="customer-notes-empty">
            {{ t('customers.notes.empty') }}
        </p>
        <ul v-else class="flex flex-col divide-y divide-[var(--color-border)]">
            <li v-for="note in notes" :key="note.id" class="flex flex-wrap items-start gap-x-4 gap-y-2 py-3" data-testid="customer-note">
                <div class="flex min-w-0 flex-[1_1_16rem] flex-col gap-1">
                    <!-- Printed as stored: a calendar date has no timezone. -->
                    <time v-if="note.note_date !== null" :datetime="note.note_date" dir="ltr" class="self-start text-sm tabular-nums text-[var(--color-text-muted)]">{{ note.note_date }}</time>
                    <!-- A note runs in its own language's direction, whichever the screen is in. -->
                    <p dir="auto" class="whitespace-pre-line text-pretty [overflow-wrap:anywhere]">{{ note.body }}</p>
                </div>
                <!-- DS §6.2: Edit is the row's secondary, Remove a text danger; the filled danger lives only inside the confirm. -->
                <div v-if="canEdit" class="flex shrink-0 flex-wrap items-center gap-2">
                    <template v-if="confirmingRemove === note.id">
                        <span class="text-sm">{{ t('customers.notes.removeConfirm') }}</span>
                        <button type="button" class="danger-action min-h-11 rounded-lg px-4" :disabled="saving" data-testid="customer-note-remove-confirm" @click="remove(note.id)">
                            {{ t('customers.notes.remove') }}
                        </button>
                        <button type="button" class="row-action min-h-11 rounded-lg px-4" :disabled="saving" @click="confirmingRemove = null">
                            {{ t('customers.notes.keep') }}
                        </button>
                    </template>
                    <template v-else>
                        <button type="button" class="row-action min-h-11 rounded-lg px-3" data-testid="customer-note-edit" @click="open(note)">
                            {{ t('customers.notes.edit') }}
                        </button>
                        <button type="button" class="danger-text min-h-11 rounded-lg px-3" data-testid="customer-note-remove" @click="confirmingRemove = note.id">
                            {{ t('customers.notes.remove') }}
                        </button>
                    </template>
                </div>
            </li>
        </ul>
    </section>
</template>

<style scoped>
/* The form sits on the muted surface, so it reads as one block inside the card. */
.note-form {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-border);
}

/* ponytail: the form-field copy every form holds (the scoped-copies debt row); one global rule when that row is paid. */
.form-field {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}
</style>
