<script setup lang="ts">
/**
 * §7.1's supplier form — create and edit, one component (Point 4.2).
 *
 * ── One permission draws all four verbs ────────────────────────────────────
 *
 * §3.7's write row is a single cell: "create · edit · deactivate · set colour".
 * So there is no second permission to ask about and no action route to call —
 * `is_active` and `color_rating` are ordinary fields on the same PATCH, and
 * `SuppliersView` decides whether this dialog opens at all by `catalog.manage`.
 * The CEO is the documented negative case: §3.7's ✅ is annotated "read-only".
 *
 * ── Seven fields, and deliberately not the eighth ──────────────────────────
 *
 * §7.1 marks `linked_quotations` "Automatic" — Module 6 derives it — and
 * {@see SaveSupplierRequest} answers it with `prohibited` rather than ignoring
 * it. A control for it here would be a control that can never save.
 *
 * ── A form-level refusal is a key; a field refusal is the server's sentence ─
 *
 * `UserFormModal` settled this and this form follows it rather than inventing a
 * third way. A server sentence was localised once, when the request was
 * answered, so a banner holding one stops re-translating when the reader
 * switches AR/EN. A field sentence has no key to fall back on — the server said
 * exactly what was wrong and copying that rule into the screen would make a
 * second copy of it — so it is shown as sent. The banner has a key, so it uses
 * one. (The other forms in this SPA still each map errors their own way; that
 * spread is recorded in `CHECKLIST.md` and is not this point's to close.)
 */
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import { createSupplier, updateSupplier, type Supplier, type SupplierDraft } from '@/services/suppliers';

const props = defineProps<{
    open: boolean;
    /** Null for a create. */
    editing: Supplier | null;
}>();

const emit = defineEmits<{ saved: [Supplier]; cancel: [] }>();

const { t } = useI18n();

/**
 * §7.1's colours and types. Restated rather than fetched because both are a
 * database CHECK and not a managed list — there is no endpoint to ask — and
 * restated *here* rather than imported from `SuppliersView`, which would make
 * the list screen a dependency of the dialog it opens.
 */
const RATINGS = ['green', 'yellow', 'red', 'white'] as const;
const TYPES = ['supplier', 'distributor'] as const;

/** The fields a server refusal can name, which are the inputs this form has. */
const FIELDS = ['name', 'type', 'color_rating', 'phone', 'contact_person', 'has_open_account', 'is_active'] as const;

type Field = (typeof FIELDS)[number];

interface Values {
    name: string;
    /** "" is "no type" — the column is nullable and `SupplierDraft` sends null. */
    type: string;
    color_rating: string;
    phone: string;
    contact_person: string;
    has_open_account: boolean;
    is_active: boolean;
}

/** The table's own defaults (Point 1.1): white is §7.1's "New / not yet rated". */
function blank(): Values {
    return {
        name: '',
        type: '',
        color_rating: 'white',
        phone: '',
        contact_person: '',
        has_open_account: false,
        is_active: true,
    };
}

const values = ref<Values>(blank());

/** What the form was opened with. The dirty check is against this, not against blank. */
const opened = ref<Values>(blank());

const saving = ref(false);
const confirmingDiscard = ref(false);

/** Lang keys for what this screen refuses; server sentences for what the server refuses. */
const errorKeys = ref<Partial<Record<Field | '_form', string>>>({});
const serverErrors = ref<Partial<Record<Field, string>>>({});

const isEdit = computed(() => props.editing !== null);

const dirty = computed(() => FIELDS.some((field) => values.value[field] !== opened.value[field]));

/** The id of every control, so `<label for>` and the error span agree. */
function fieldId(field: Field): string {
    return `supplier-form-${field}`;
}

function testId(field: Field): string {
    return `supplier-form-${field.replace(/_/g, '-')}`;
}

/** The server's sentence when it sent one for this field, otherwise ours. */
function errorFor(field: Field): string | null {
    const sentence = serverErrors.value[field];

    if (sentence !== undefined) {
        return sentence;
    }

    const key = errorKeys.value[field];

    return key === undefined ? null : t(key);
}

watch(() => [props.open, props.editing] as const, ([open]) => {
    if (!open) {
        return;
    }

    const record = props.editing;
    const next = blank();

    if (record !== null) {
        next.name = record.name;
        next.type = record.type ?? '';
        next.color_rating = record.color_rating;
        next.phone = record.phone ?? '';
        next.contact_person = record.contact_person ?? '';
        next.has_open_account = record.has_open_account;
        next.is_active = record.is_active;
    }

    values.value = { ...next };
    opened.value = { ...next };
    errorKeys.value = {};
    serverErrors.value = {};
    confirmingDiscard.value = false;
}, { immediate: true });

/**
 * The one client-side rule, and it is a courtesy rather than the rule.
 *
 * `SaveSupplierRequest` carries `regex:/\S/` beside `required`, because
 * `required` accepts "   " and the table's `CHECK (btrim(name) <> '')` would
 * answer a blank one with a 500. Checking it here saves a round trip; the
 * server refuses regardless (`D-67`).
 */
function validate(): boolean {
    errorKeys.value = values.value.name.trim() === '' ? { name: 'suppliers.form.nameRequired' } : {};

    return errorKeys.value.name === undefined;
}

/** `OpenAPI §5` — `details[]` names the field, so the sentence lands on the input that caused it. */
function applyServerErrors(error: unknown): void {
    if (!(error instanceof ApiError)) {
        errorKeys.value = { _form: 'suppliers.form.unreachable' };
        serverErrors.value = {};

        return;
    }

    const sentences: Partial<Record<Field, string>> = {};

    for (const field of FIELDS) {
        const message = error.messageFor(field);

        if (message !== null) {
            sentences[field] = message;
        }
    }

    errorKeys.value = Object.keys(sentences).length === 0
        ? { _form: error.status === 403 ? 'suppliers.form.forbidden' : 'suppliers.form.rejected' }
        : {};

    serverErrors.value = sentences;
}

/**
 * The payload: trimmed, with an empty box sent as `null` rather than `""`.
 *
 * The columns are nullable and "" is not the same absence — a blank `phone`
 * stored as an empty string is a value no filter or export expects. `name` is
 * the exception: it is required, so it is always a string.
 */
function draft(): SupplierDraft {
    const current = values.value;
    const phone = current.phone.trim();
    const contact = current.contact_person.trim();

    return {
        name: current.name.trim(),
        type: current.type === '' ? null : current.type,
        color_rating: current.color_rating,
        phone: phone === '' ? null : phone,
        contact_person: contact === '' ? null : contact,
        has_open_account: current.has_open_account,
        is_active: current.is_active,
    };
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
            ? await createSupplier(draft())
            : await updateSupplier(record.id, draft());

        opened.value = { ...values.value };
        emit('saved', written);
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
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4" data-testid="supplier-form-modal">
        <div class="modal-scrim absolute inset-0" @click="requestClose()" />

        <form
            class="modal-panel relative flex max-h-[90vh] w-full max-w-xl flex-col gap-4 overflow-y-auto rounded-2xl p-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="supplier-form-title"
            novalidate
            data-testid="supplier-form"
            @submit.prevent="save()"
            @keydown.escape.prevent="requestClose()"
        >
            <h2 id="supplier-form-title" class="text-card-title">
                {{ isEdit ? t('suppliers.form.editTitle') : t('suppliers.form.createTitle') }}
            </h2>

            <p
                v-if="errorKeys._form !== undefined"
                class="form-alert rounded-lg p-3"
                role="alert"
                data-testid="supplier-form-error"
            >
                {{ t(errorKeys._form) }}
            </p>

            <label class="flex flex-col gap-1.5" :for="fieldId('name')">
                <span>
                    {{ t('suppliers.form.name') }}
                    <!-- §6.3's "explicit required marker": a word, not only a glyph. -->
                    <span class="text-[var(--color-danger)]" data-testid="supplier-form-name-required">
                        {{ t('suppliers.form.required') }}
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
                <span v-if="errorFor('name') !== null" class="text-[var(--color-danger)]" data-testid="supplier-form-name-error">
                    {{ errorFor('name') }}
                </span>
            </label>

            <label class="flex flex-col gap-1.5" :for="fieldId('type')">
                <span>{{ t('suppliers.column.type') }}</span>
                <select
                    :id="fieldId('type')"
                    v-model="values.type"
                    :disabled="saving"
                    :aria-invalid="errorFor('type') !== null"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :data-testid="testId('type')"
                >
                    <option value="">{{ t('suppliers.form.typeNone') }}</option>
                    <option v-for="code in TYPES" :key="code" :value="code">{{ t(`suppliers.type.${code}`) }}</option>
                </select>
                <span v-if="errorFor('type') !== null" class="text-[var(--color-danger)]" data-testid="supplier-form-type-error">
                    {{ errorFor('type') }}
                </span>
            </label>

            <!-- §3.7's "set colour", which is this cell and not a second one. -->
            <label class="flex flex-col gap-1.5" :for="fieldId('color_rating')">
                <span>{{ t('suppliers.column.rating') }}</span>
                <select
                    :id="fieldId('color_rating')"
                    v-model="values.color_rating"
                    :disabled="saving"
                    :aria-invalid="errorFor('color_rating') !== null"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :data-testid="testId('color_rating')"
                >
                    <option v-for="code in RATINGS" :key="code" :value="code">{{ t(`suppliers.rating.${code}`) }}</option>
                </select>
                <span v-if="errorFor('color_rating') !== null" class="text-[var(--color-danger)]" data-testid="supplier-form-color-rating-error">
                    {{ errorFor('color_rating') }}
                </span>
            </label>

            <label class="flex flex-col gap-1.5" :for="fieldId('contact_person')">
                <span>{{ t('suppliers.column.contact') }}</span>
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
                <span>{{ t('suppliers.column.phone') }}</span>
                <input
                    :id="fieldId('phone')"
                    v-model="values.phone"
                    type="tel"
                    inputmode="tel"
                    maxlength="32"
                    :disabled="saving"
                    :aria-invalid="errorFor('phone') !== null"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :data-testid="testId('phone')"
                />
                <span v-if="errorFor('phone') !== null" class="text-[var(--color-danger)]" data-testid="supplier-form-phone-error">
                    {{ errorFor('phone') }}
                </span>
            </label>

            <label class="flex items-center gap-2" :for="fieldId('has_open_account')">
                <input
                    :id="fieldId('has_open_account')"
                    v-model="values.has_open_account"
                    type="checkbox"
                    class="size-5"
                    :disabled="saving"
                    :data-testid="testId('has_open_account')"
                />
                <span>{{ t('suppliers.column.account') }}</span>
            </label>

            <!-- §3.7's "deactivate", as a field. §10.4 hides a deactivated
                 supplier from the **selection lists** of Modules 6 and 7, not
                 from this screen — which is why this is a box and not a
                 one-way button. -->
            <label class="flex items-center gap-2" :for="fieldId('is_active')">
                <input
                    :id="fieldId('is_active')"
                    v-model="values.is_active"
                    type="checkbox"
                    class="size-5"
                    :disabled="saving"
                    :data-testid="testId('is_active')"
                />
                <span>{{ t('suppliers.status.active') }}</span>
            </label>

            <!-- §5.2's unsaved-change warning. Inside the dialog, because a
                 native `confirm()` is neither translatable nor RTL-aware. -->
            <div
                v-if="confirmingDiscard"
                class="form-warning rounded-lg p-3"
                role="alertdialog"
                aria-labelledby="supplier-form-unsaved-title"
                data-testid="supplier-form-unsaved"
            >
                <p id="supplier-form-unsaved-title">{{ t('suppliers.form.unsavedChanges') }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="modal-cancel min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        data-testid="supplier-form-keep-editing"
                        @click="confirmingDiscard = false"
                    >
                        {{ t('suppliers.form.keepEditing') }}
                    </button>
                    <button
                        type="button"
                        class="modal-discard min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        data-testid="supplier-form-discard"
                        @click="discard()"
                    >
                        {{ t('suppliers.form.discard') }}
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-2">
                <button
                    type="button"
                    class="modal-cancel min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :disabled="saving"
                    data-testid="supplier-form-cancel"
                    @click="requestClose()"
                >
                    {{ t('action.cancel') }}
                </button>

                <button
                    type="submit"
                    class="modal-save min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="saving"
                    data-testid="supplier-form-save"
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

/* §6.4's Warning row: amber, and never colour alone — the markup carries a
   label beside it. */
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
