<script setup lang="ts">
/**
 * §4.2's customer form — create and edit, one component (Point 4.3).
 *
 * ── The form sends ten fields, and deliberately not the other five ─────────
 *
 * §4.2 lists fifteen columns. `customer_status` is derived (§4.5, `D-49`),
 * `added_by` and `created_at` are automatic, `is_archived` is Flow 7's own
 * action and `is_incomplete` is `D-31`'s flag on an imported row. Three of
 * those are `prohibited` in {@see SaveCustomerRequest} — a 422, not a silent
 * drop — so a form that posted them would simply never save.
 *
 * ── No owner control, on purpose ───────────────────────────────────────────
 *
 * `sales_owner_id` is writable on create, and §4.2 says the Team Leader or
 * Manager "can change it". But changing it is `customer.assign` — §3.3 gives it
 * its own row and `OpenAPI §7.2` its own route (Point 3.5) — and filling a
 * picker needs `GET /users`, which `customer.create` does not carry. A control
 * that 403s for the person looking at it is worse than no control, so the owner
 * is set through the assign action. Recorded as a narrowing, not a decision.
 *
 * ── The duplicate warning arrives AFTER the write, because `D-35` says so ──
 *
 * §10.2: "The employee may proceed... **No blocking and no automatic merge**."
 * The server writes the row and reports the similar ones in `meta`, so this is
 * a warning about something that already happened. The dialog therefore stays
 * open to show it, rather than closing over it.
 *
 * The similar customers are listed by name and are **not** links: Point 4.4's
 * detail page does not exist yet, and `navigation.ts` already settles what to
 * do about that — "a dead link is not a permission problem, it is a lie".
 *
 * ── Server validation is the server's sentence ─────────────────────────────
 *
 * §6.1: "Display server validation near the affected field and preserve
 * entered values on validation failure." {@see ApiError.messageFor} carries the
 * message the server localised for this request, so the screen never keeps a
 * second copy of a rule — the copy that is wrong is always the one in the
 * screen.
 */
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import type { ListEntry } from '@/services/admin';
import { createCustomer, updateCustomer, type Customer, type CustomerDraft } from '@/services/customers';

const props = defineProps<{
    open: boolean;
    /** Null for a create. */
    editing: Customer | null;
    /** §4.2's reference list, already loaded by the list screen — not fetched twice. */
    sectors: readonly ListEntry[];
}>();

/**
 * `saved` carries `D-35`'s similar records beside the written one, because the
 * list screen needs them to decide whether to close: §10.2 wants the warning
 * *seen*, and closing over it is how a warning becomes a thing nobody read.
 */
const emit = defineEmits<{ saved: [Customer, Customer[]]; cancel: [] }>();

const { t, locale } = useI18n();

/**
 * §4.2's user-entered fields, in the order the form draws them. One list, so a
 * field cannot be added to the markup and forgotten by the reset, the dirty
 * check or the payload.
 */
const FIELDS = [
    'name', 'sector', 'region', 'contact_person',
    'phone', 'phone2', 'whatsapp', 'email', 'start_date', 'notes',
] as const;

type Field = (typeof FIELDS)[number];

function blank(): Record<Field, string> {
    return {
        name: '', sector: '', region: '', contact_person: '',
        phone: '', phone2: '', whatsapp: '', email: '', start_date: '', notes: '',
    };
}

const values = ref<Record<Field, string>>(blank());

/** What the form was opened with. The dirty check is against this, not against blank. */
const opened = ref<Record<Field, string>>(blank());

const saving = ref(false);
const confirmingDiscard = ref(false);

/** Lang keys for what this screen refuses; server sentences for what the server refuses. */
const errorKeys = ref<Partial<Record<Field | '_form', string>>>({});
const serverErrors = ref<Partial<Record<Field | '_form', string>>>({});

/** `D-35`'s similar records, as the server reported them on the last save. */
const similar = ref<Customer[]>([]);

const isEdit = computed(() => props.editing !== null);

const dirty = computed(() => FIELDS.some((field) => values.value[field] !== opened.value[field]));

/** The id of every control, so `<label for>` and `aria-describedby` agree. */
function fieldId(field: Field): string {
    return `customer-form-${field}`;
}

function testId(field: Field): string {
    return `customer-form-${field.replace(/_/g, '-')}`;
}

function errorFor(field: Field): string | null {
    const server = serverErrors.value[field];

    if (server !== undefined) {
        return server;
    }

    const key = errorKeys.value[field];

    return key === undefined ? null : t(key);
}

/** `DB-05` keeps both labels on every entry, so neither language falls back to a code. */
function sectorLabel(entry: ListEntry): string {
    return locale.value.startsWith('ar') ? entry.label_ar : entry.label_en;
}

watch(() => [props.open, props.editing] as const, ([open]) => {
    if (!open) {
        return;
    }

    const record = props.editing;
    const next = blank();

    if (record !== null) {
        for (const field of FIELDS) {
            next[field] = record[field] ?? '';
        }
    }

    values.value = { ...next };
    opened.value = { ...next };
    errorKeys.value = {};
    serverErrors.value = {};
    similar.value = [];
    confirmingDiscard.value = false;
}, { immediate: true });

/**
 * The one client-side rule, and it is a courtesy rather than the rule.
 *
 * §4.2 marks `name` required and `SaveCustomerRequest` carries `regex:/\S/`
 * beside `required`, because `required` accepts "   " and the table's
 * `CHECK (btrim(name) <> '')` would answer a blank one with a 500. Checking it
 * here saves a round trip; the server refuses regardless (`D-67`).
 */
function validate(): boolean {
    errorKeys.value = values.value.name.trim() === '' ? { name: 'customers.form.nameRequired' } : {};

    return Object.keys(errorKeys.value).length === 0;
}

/** `OpenAPI §5` — `details[]` names the field, so the sentence lands on the input that caused it. */
function applyServerErrors(error: unknown): void {
    if (!(error instanceof ApiError)) {
        serverErrors.value = { _form: t('customers.form.unreachable') };

        return;
    }

    const found: Partial<Record<Field | '_form', string>> = {};

    for (const field of FIELDS) {
        const message = error.messageFor(field);

        if (message !== null) {
            found[field] = message;
        }
    }

    if (Object.keys(found).length === 0) {
        found._form = error.status === 403 ? t('customers.form.forbidden') : error.message;
    }

    serverErrors.value = found;
}

/**
 * The payload: trimmed, with an empty box sent as `null` rather than `""`.
 *
 * The columns are nullable and "" is not the same absence — a blank `email`
 * stored as an empty string is a value that no filter, export or mail step
 * expects. `name` is the exception: it is required, so it is always a string.
 */
function draft(): CustomerDraft {
    const payload: CustomerDraft = { name: values.value.name.trim() };

    for (const field of FIELDS) {
        if (field === 'name') {
            continue;
        }

        const value = values.value[field].trim();

        payload[field] = value === '' ? null : value;
    }

    return payload;
}

async function save(): Promise<void> {
    if (!validate()) {
        return;
    }

    saving.value = true;
    serverErrors.value = {};

    try {
        const record = props.editing;
        const written = record === null
            ? await createCustomer(draft())
            : await updateCustomer(record.id, draft());

        similar.value = written.similar;
        opened.value = { ...values.value };
        emit('saved', written.customer, written.similar);
    } catch (error) {
        applyServerErrors(error);
    } finally {
        saving.value = false;
    }
}

/**
 * Design System §5.2's "unsaved-change warning", and §6.1's "Escape closes
 * dialogs/menus **without discarding silently**". They are one rule seen twice,
 * so Cancel and Escape take the same path: nothing typed closes at once,
 * something typed asks first.
 */
function requestClose(): void {
    if (dirty.value) {
        confirmingDiscard.value = true;

        return;
    }

    emit('cancel');
}

function discard(): void {
    confirmingDiscard.value = false;
    emit('cancel');
}
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4" data-testid="customer-form-modal">
        <div class="modal-scrim absolute inset-0" @click="requestClose()" />

        <form
            class="modal-panel relative flex max-h-[90vh] w-full max-w-2xl flex-col gap-4 overflow-y-auto rounded-2xl p-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="customer-form-title"
            novalidate
            data-testid="customer-form"
            @submit.prevent="save()"
            @keydown.escape.prevent="requestClose()"
        >
            <h2 id="customer-form-title" class="text-card-title">
                {{ isEdit ? t('customers.form.editTitle') : t('customers.form.createTitle') }}
            </h2>

            <p
                v-if="serverErrors._form !== undefined"
                class="form-alert rounded-lg p-3"
                role="alert"
                data-testid="customer-form-error"
            >
                {{ serverErrors._form }}
            </p>

            <!-- §10.2 · `D-35` · §6.4's warning: amber, with an icon AND a label,
                 "never color alone". The row is already saved — this reports it. -->
            <div
                v-if="similar.length > 0"
                class="form-warning rounded-lg p-3"
                role="status"
                data-testid="customer-form-similar"
            >
                <p class="flex items-center gap-2 font-medium">
                    <svg class="size-5 shrink-0 text-[var(--color-warning)]" viewBox="0 0 20 20" aria-hidden="true" fill="currentColor">
                        <path d="M10 2 1 18h18zm-1 6h2v5H9zm0 7h2v2H9z" />
                    </svg>
                    {{ t('customers.form.similarTitle') }}
                </p>
                <ul class="mt-1 list-disc ps-5">
                    <li v-for="match in similar" :key="match.id">{{ match.name }}</li>
                </ul>
                <p class="mt-1">{{ t('customers.form.similarHint') }}</p>
            </div>

            <!-- §4.5 · `D-49`: derived, "read-only in the UI, with an icon
                 explaining the reason". Text, not a disabled input — a disabled
                 input still reads as a control somebody could be given. -->
            <div v-if="isEdit && editing !== null" class="flex flex-col gap-1">
                <span class="text-[var(--color-text-muted)]">{{ t('customers.column.status') }}</span>
                <p class="font-medium" data-testid="customer-form-status">
                    {{ t(`customers.status.${editing.customer_status}`) }}
                </p>
                <p class="flex items-center gap-2 text-[var(--color-text-muted)] text-pretty" data-testid="customer-form-status-reason">
                    <svg class="size-4 shrink-0" viewBox="0 0 20 20" aria-hidden="true" fill="currentColor">
                        <path d="M10 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16zM9 5h2v2H9zm0 4h2v6H9z" />
                    </svg>
                    {{ t('customers.form.statusIsDerived') }}
                </p>
            </div>

            <!-- §5.2's "clear sections". §4.2's fields, grouped by what they are. -->
            <fieldset class="flex flex-col gap-3">
                <legend class="mb-2 font-medium">{{ t('customers.form.sectionIdentity') }}</legend>

                <label class="flex flex-col gap-1.5" :for="fieldId('name')">
                    <span>
                        {{ t('customers.form.name') }}
                        <!-- §6.3's "explicit required marker": a word, not only a glyph. -->
                        <span class="text-[var(--color-danger)]" data-testid="customer-form-name-required">
                            {{ t('customers.form.required') }}
                        </span>
                    </span>
                    <input
                        :id="fieldId('name')"
                        v-model="values.name"
                        type="text"
                        maxlength="255"
                        autocomplete="off"
                        :disabled="saving"
                        :aria-invalid="errorFor('name') !== null"
                        class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        :data-testid="testId('name')"
                    />
                    <span v-if="errorFor('name') !== null" class="text-[var(--color-danger)]" data-testid="customer-form-name-error">
                        {{ errorFor('name') }}
                    </span>
                </label>

                <label class="flex flex-col gap-1.5" :for="fieldId('sector')">
                    <span>{{ t('customers.column.sector') }}</span>
                    <select
                        :id="fieldId('sector')"
                        v-model="values.sector"
                        :disabled="saving"
                        :aria-invalid="errorFor('sector') !== null"
                        class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        :data-testid="testId('sector')"
                    >
                        <option value="">{{ t('customers.form.sectorNone') }}</option>
                        <option v-for="entry in sectors" :key="entry.code" :value="entry.code">{{ sectorLabel(entry) }}</option>
                    </select>
                    <span v-if="errorFor('sector') !== null" class="text-[var(--color-danger)]" data-testid="customer-form-sector-error">
                        {{ errorFor('sector') }}
                    </span>
                </label>

                <!-- `D-20` calls region "free text with suggestions". The
                     suggestions have no endpoint, so this is the free text half
                     and the other half is owed. -->
                <label class="flex flex-col gap-1.5" :for="fieldId('region')">
                    <span>{{ t('customers.form.region') }}</span>
                    <input
                        :id="fieldId('region')"
                        v-model="values.region"
                        type="text"
                        maxlength="128"
                        :disabled="saving"
                        class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        :data-testid="testId('region')"
                    />
                </label>

                <!-- §6.3's "Date field: locale-aware presentation, ISO-safe
                     value, calendar keyboard support" — which is what the native
                     control already is, in every locale, for free. -->
                <label class="flex flex-col gap-1.5" :for="fieldId('start_date')">
                    <span>{{ t('customers.column.startDate') }}</span>
                    <input
                        :id="fieldId('start_date')"
                        v-model="values.start_date"
                        type="date"
                        :disabled="saving"
                        :aria-invalid="errorFor('start_date') !== null"
                        class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        :data-testid="testId('start_date')"
                    />
                    <span v-if="errorFor('start_date') !== null" class="text-[var(--color-danger)]" data-testid="customer-form-start-date-error">
                        {{ errorFor('start_date') }}
                    </span>
                </label>
            </fieldset>

            <fieldset class="flex flex-col gap-3">
                <legend class="mb-2 font-medium">{{ t('customers.form.sectionContact') }}</legend>

                <!-- `D-18`: a single contact person, not a collection. -->
                <label class="flex flex-col gap-1.5" :for="fieldId('contact_person')">
                    <span>{{ t('customers.column.contact') }}</span>
                    <input
                        :id="fieldId('contact_person')"
                        v-model="values.contact_person"
                        type="text"
                        maxlength="255"
                        :disabled="saving"
                        class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        :data-testid="testId('contact_person')"
                    />
                </label>

                <label class="flex flex-col gap-1.5" :for="fieldId('phone')">
                    <span>{{ t('customers.column.phone') }}</span>
                    <input
                        :id="fieldId('phone')"
                        v-model="values.phone"
                        type="tel"
                        inputmode="tel"
                        maxlength="32"
                        :disabled="saving"
                        class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        :data-testid="testId('phone')"
                    />
                </label>

                <label class="flex flex-col gap-1.5" :for="fieldId('phone2')">
                    <span>{{ t('customers.form.phone2') }}</span>
                    <input
                        :id="fieldId('phone2')"
                        v-model="values.phone2"
                        type="tel"
                        inputmode="tel"
                        maxlength="32"
                        :disabled="saving"
                        class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        :data-testid="testId('phone2')"
                    />
                </label>

                <label class="flex flex-col gap-1.5" :for="fieldId('whatsapp')">
                    <span>{{ t('customers.form.whatsapp') }}</span>
                    <input
                        :id="fieldId('whatsapp')"
                        v-model="values.whatsapp"
                        type="tel"
                        inputmode="tel"
                        maxlength="32"
                        :disabled="saving"
                        class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        :data-testid="testId('whatsapp')"
                    />
                </label>

                <label class="flex flex-col gap-1.5" :for="fieldId('email')">
                    <span>{{ t('customers.form.email') }}</span>
                    <input
                        :id="fieldId('email')"
                        v-model="values.email"
                        type="email"
                        inputmode="email"
                        maxlength="255"
                        autocomplete="off"
                        :disabled="saving"
                        :aria-invalid="errorFor('email') !== null"
                        class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        :data-testid="testId('email')"
                    />
                    <span v-if="errorFor('email') !== null" class="text-[var(--color-danger)]" data-testid="customer-form-email-error">
                        {{ errorFor('email') }}
                    </span>
                </label>
            </fieldset>

            <!-- `D-16`: notes carry the communication history. -->
            <label class="flex flex-col gap-1.5" :for="fieldId('notes')">
                <span>{{ t('customers.form.notes') }}</span>
                <textarea
                    :id="fieldId('notes')"
                    v-model="values.notes"
                    rows="4"
                    :disabled="saving"
                    class="form-field rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :data-testid="testId('notes')"
                />
            </label>

            <!-- §5.2's unsaved-change warning. Inside the dialog, because a
                 native `confirm()` is neither translatable nor RTL-aware. -->
            <div
                v-if="confirmingDiscard"
                class="form-warning rounded-lg p-3"
                role="alertdialog"
                aria-labelledby="customer-form-unsaved-title"
                data-testid="customer-form-unsaved"
            >
                <p id="customer-form-unsaved-title">{{ t('customers.form.unsavedChanges') }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="modal-cancel min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        data-testid="customer-form-keep-editing"
                        @click="confirmingDiscard = false"
                    >
                        {{ t('customers.form.keepEditing') }}
                    </button>
                    <button
                        type="button"
                        class="modal-discard min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        data-testid="customer-form-discard"
                        @click="discard()"
                    >
                        {{ t('customers.form.discard') }}
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-2">
                <button
                    type="button"
                    class="modal-cancel min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :disabled="saving"
                    data-testid="customer-form-cancel"
                    @click="requestClose()"
                >
                    {{ t('action.cancel') }}
                </button>

                <button
                    type="submit"
                    class="modal-save min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="saving"
                    data-testid="customer-form-save"
                >
                    {{ saving ? t('action.saving') : t('action.save') }}
                </button>
            </div>
        </form>
    </div>
</template>

<style scoped>
.modal-scrim {
    background-color: color-mix(in srgb, var(--color-text) 45%, transparent);
}

.modal-panel {
    background-color: var(--color-surface-raised);
    border: 1px solid var(--color-border);
    box-shadow: var(--shadow-3, var(--shadow-2));
}

.form-field {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.form-alert {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-danger);
    color: var(--color-danger);
}

/* §6.4's Warning row: amber, and never colour alone — the markup carries an
   icon and a label beside it. */
.form-warning {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-warning);
    color: var(--color-text);
}

.modal-cancel {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.modal-discard {
    background-color: var(--color-danger);
    color: var(--color-primary-text);
}

.modal-save {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
}
</style>
