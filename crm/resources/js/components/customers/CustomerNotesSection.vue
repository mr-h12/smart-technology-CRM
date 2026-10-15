<script setup lang="ts">
/**
 * `D-122` (F-50 · 3) — a customer's notes as separate entries, each with an
 * optional date that is a label only (`D-15` stays deferred).
 *
 * The list is the server's, in the server's order (newest date first, undated
 * last); nothing here re-sorts it (`D-67`). Writing needs `customer.edit`,
 * which the page decides and passes in; the server checks it again at the
 * customer's scope.
 *
 * `D-124`: no Save, Edit or Cancel. A note saves itself 2 s after typing stops
 * and at once when its box is left. An empty box at the top creates a note on
 * its first save. A cleared note is never saved: the box says so and the
 * stored text stays until real text is typed. Remove keeps its confirm.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import {
    addCustomerNote,
    listCustomerNotes,
    removeCustomerNote,
    updateCustomerNote,
    type CustomerNote,
    type CustomerNoteDraft,
} from '@/services/customers';

const props = defineProps<{ customerId: string; canEdit: boolean }>();

const { t } = useI18n();

/** `D-124`'s pause before a save. */
const PAUSE_MS = 2000;

/** `NEW` is the empty box at the top; every other row is keyed by its note's id. */
const NEW = 'new';

interface Row {
    /** The note this box writes to; the empty box at the top has none until its first save. */
    id: string | null;
    body: string;
    date: string;
    /** What the server holds; a save is sent only when the box differs from it. */
    stored: CustomerNoteDraft | null;
    status: 'idle' | 'saving' | 'saved' | 'failed';
    error: string | null;
}

const notes = ref<CustomerNote[] | null>(null);
const failed = ref(false);
const rows = ref<Record<string, Row>>({});

/** The empty box at the top (`D-124`). */
const fresh = ref<Row>(blankRow());

/** Each note beside its box, in the server's order. */
const entries = computed(() => (notes.value ?? []).flatMap((note) => {
    const row = rows.value[note.id];

    return row === undefined ? [] : [{ note, row }];
}));
const removeFailed = ref(false);

/** The note whose removal is waiting for its confirm. */
const confirmingRemove = ref<string | null>(null);

const timers = new Map<string, ReturnType<typeof setTimeout>>();
const inFlight = new Set<string>();

function blankRow(): Row {
    return { id: null, body: '', date: '', stored: null, status: 'idle', error: null };
}

function rowOf(note: CustomerNote): Row {
    return { id: note.id, body: note.body, date: note.note_date ?? '', stored: { body: note.body, note_date: note.note_date }, status: 'idle', error: null };
}

async function load(): Promise<void> {
    failed.value = false;

    try {
        const loaded = (await listCustomerNotes(props.customerId)).items;
        notes.value = loaded;
        // A box still waiting for its save keeps what was typed.
        const keep = (id: string): Row | undefined => (timers.has(id) || inFlight.has(id) ? rows.value[id] : undefined);
        rows.value = Object.fromEntries(loaded.map((note) => [note.id, keep(note.id) ?? rowOf(note)]));
    } catch {
        notes.value = null;
        failed.value = true;
    }
}

function draftOf(row: Row): CustomerNoteDraft {
    return { body: row.body.trim(), note_date: row.date === '' ? null : row.date };
}

/** Typing restarts the pause; leaving the box saves at once. */
function typed(key: string): void {
    clearTimeout(timers.get(key));
    timers.set(key, setTimeout(() => void save(key), PAUSE_MS));
}

async function save(key: string): Promise<void> {
    clearTimeout(timers.get(key));
    timers.delete(key);

    const row = key === NEW ? fresh.value : rows.value[key];

    // One request per box at a time; the next pause or leave sends what is left.
    if (row === undefined || inFlight.has(key)) {
        return;
    }

    const draft = draftOf(row);

    if (draft.body === '') {
        // The empty box at the top waits quietly; a cleared note says why it is not saved.
        row.error = key === NEW ? null : t('customers.notes.required');

        return;
    }

    if (row.stored !== null && row.stored.body === draft.body && row.stored.note_date === draft.note_date) {
        row.error = null;

        return;
    }

    inFlight.add(key);
    row.status = 'saving';
    row.error = null;

    try {
        // The first save of the box at the top creates its note; every later one updates it,
        // so a pause mid-sentence never splits one note in two.
        const saved = row.id === null
            ? await addCustomerNote(props.customerId, draft)
            : await updateCustomerNote(props.customerId, row.id, draft);
        row.id = saved.id;
        row.stored = { body: saved.body, note_date: saved.note_date };
        row.status = 'saved';
    } catch (error) {
        row.status = 'failed';
        row.error = error instanceof ApiError ? (error.messageFor('body') ?? error.messageFor('note_date')) : null;
    } finally {
        inFlight.delete(key);
    }
}

const freshBox = ref<HTMLElement | null>(null);

/** Leaving the box at the top saves it and, once it holds a note, moves that note into the list. */
async function leaveFresh(event: FocusEvent): Promise<void> {
    // Moving between its text and its date is not leaving it.
    if (event.relatedTarget instanceof Node && freshBox.value?.contains(event.relatedTarget)) {
        return;
    }

    await save(NEW);

    if (fresh.value.id !== null && !timers.has(NEW) && fresh.value.status === 'saved') {
        fresh.value = blankRow();
        await load();
    }
}

async function remove(noteId: string): Promise<void> {
    removeFailed.value = false;

    try {
        clearTimeout(timers.get(noteId));
        timers.delete(noteId);
        await removeCustomerNote(props.customerId, noteId);
        confirmingRemove.value = null;
        await load();
    } catch {
        removeFailed.value = true;
    }
}

function statusText(row: Row): string {
    return row.status === 'saving'
        ? t('customers.notes.saving')
        : row.status === 'saved'
            ? t('customers.notes.saved')
            : row.status === 'failed'
                ? t('customers.notes.notSaved')
                : '';
}

onMounted(load);
watch(() => props.customerId, load);

// Leaving the page saves what is still waiting for its pause.
onBeforeUnmount(() => {
    for (const key of [...timers.keys()]) {
        void save(key);
    }
});
</script>

<template>
    <section class="detail-card flex flex-col gap-2 rounded-xl p-4" data-testid="customer-notes-section">
        <h2 class="text-card-title">{{ t('customers.notes.title') }}</h2>

        <div v-if="failed" class="flex flex-wrap items-center gap-2" role="alert" data-testid="customer-notes-error">
            <p class="text-[var(--color-danger)]">{{ t('customers.notes.loadFailed') }}</p>
            <button type="button" class="row-action min-h-11 rounded-lg px-4" @click="load">{{ t('customers.notes.retry') }}</button>
        </div>
        <p v-else-if="notes === null" class="text-[var(--color-text-muted)]" data-testid="customer-notes-loading">{{ t('customers.notes.loading') }}</p>

        <template v-else-if="canEdit">
            <!-- D-124: the empty box that becomes a note on its first save. -->
            <div ref="freshBox" class="note-new flex flex-wrap items-start gap-2 rounded-lg p-3" data-testid="customer-note-new">
                <textarea
                    v-model="fresh.body"
                    rows="2"
                    maxlength="5000"
                    dir="auto"
                    :placeholder="t('customers.notes.newPlaceholder')"
                    :aria-label="t('customers.notes.body')"
                    class="form-field min-w-0 flex-[1_1_16rem] rounded-lg px-3 py-2"
                    data-testid="customer-note-new-body"
                    @input="typed(NEW)"
                    @blur="leaveFresh"
                />
                <input
                    v-model="fresh.date"
                    type="date"
                    :aria-label="t('customers.notes.date')"
                    class="form-field min-h-11 rounded-lg px-3 py-2"
                    data-testid="customer-note-new-date"
                    @change="typed(NEW)"
                    @blur="leaveFresh"
                />
                <span v-if="statusText(fresh) !== ''" class="basis-full text-sm text-[var(--color-text-muted)]" aria-live="polite">{{ statusText(fresh) }}</span>
            </div>

            <p v-if="notes.length === 0" class="text-[var(--color-text-muted)] text-pretty" data-testid="customer-notes-empty">
                {{ t('customers.notes.empty') }}
            </p>
            <p v-if="removeFailed" class="text-[var(--color-danger)]" role="alert">{{ t('customers.notes.removeFailed') }}</p>

            <ul class="flex flex-col divide-y divide-[var(--color-border)]">
                <li v-for="{ note, row } in entries" :key="note.id" class="flex flex-col gap-1 py-3" data-testid="customer-note">
                    <div class="flex flex-wrap items-start gap-2">
                        <textarea
                            v-model="row.body"
                            rows="2"
                            maxlength="5000"
                            dir="auto"
                            :aria-label="t('customers.notes.body')"
                            :aria-invalid="row.error !== null"
                            :aria-describedby="row.error !== null ? `customer-note-error-${note.id}` : undefined"
                            class="form-field min-w-0 flex-[1_1_16rem] rounded-lg px-3 py-2"
                            data-testid="customer-note-body"
                            @input="typed(note.id)"
                            @blur="save(note.id)"
                        />
                        <input
                            v-model="row.date"
                            type="date"
                            :aria-label="t('customers.notes.date')"
                            class="form-field min-h-11 rounded-lg px-3 py-2"
                            data-testid="customer-note-date"
                            @change="typed(note.id)"
                            @blur="save(note.id)"
                        />
                        <!-- DS §6.2: Remove is a text danger; the filled danger lives only inside the confirm. -->
                        <template v-if="confirmingRemove === note.id">
                            <span class="self-center text-sm">{{ t('customers.notes.removeConfirm') }}</span>
                            <button type="button" class="danger-action min-h-11 rounded-lg px-4" data-testid="customer-note-remove-confirm" @click="remove(note.id)">
                                {{ t('customers.notes.remove') }}
                            </button>
                            <button type="button" class="row-action min-h-11 rounded-lg px-4" @click="confirmingRemove = null">
                                {{ t('customers.notes.keep') }}
                            </button>
                        </template>
                        <button v-else type="button" class="danger-text min-h-11 rounded-lg px-3" data-testid="customer-note-remove" @click="confirmingRemove = note.id">
                            {{ t('customers.notes.remove') }}
                        </button>
                    </div>
                    <span
                        v-if="row.error !== null"
                        :id="`customer-note-error-${note.id}`"
                        class="text-[var(--color-danger)]"
                        data-testid="customer-note-body-error"
                    >{{ row.error }}</span>
                    <span class="text-sm text-[var(--color-text-muted)]" aria-live="polite" data-testid="customer-note-status">{{ statusText(row) }}</span>
                </li>
            </ul>
        </template>

        <template v-else>
            <p v-if="notes.length === 0" class="text-[var(--color-text-muted)] text-pretty" data-testid="customer-notes-empty">
                {{ t('customers.notes.empty') }}
            </p>
            <ul v-else class="flex flex-col divide-y divide-[var(--color-border)]">
                <li v-for="note in notes" :key="note.id" class="flex flex-col gap-1 py-3" data-testid="customer-note">
                    <!-- Printed as stored: a calendar date has no timezone. -->
                    <time v-if="note.note_date !== null" :datetime="note.note_date" dir="ltr" class="self-start text-sm tabular-nums text-[var(--color-text-muted)]">{{ note.note_date }}</time>
                    <!-- A note runs in its own language's direction, whichever the screen is in. -->
                    <p dir="auto" class="whitespace-pre-line text-pretty [overflow-wrap:anywhere]">{{ note.body }}</p>
                </li>
            </ul>
        </template>
    </section>
</template>

<style scoped>
/* The box that becomes a note sits on the muted surface, apart from the saved ones. */
.note-new {
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
