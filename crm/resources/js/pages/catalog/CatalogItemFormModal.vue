<script setup lang="ts">
/**
 * §7.3's catalog form — create and edit, one component (Point 4.4).
 *
 * ── Two tabs are two field sets, not one union ─────────────────────────────
 *
 * §7.3 lists "Product code · Product name · Category · Unit · Description"
 * against "Service type · Service description · Providing team/company ·
 * Active · Notes". `SaveCatalogItemRequest` turns that into three `required_if`
 * rules, so drawing one union with half the boxes irrelevant would be a form
 * that asks for what the server will refuse and hides what it demands.
 *
 * ── `kind` is named on every write, and that is the point ──────────────────
 *
 * `required_if:kind,product` fires **only when `kind` is in the payload**. The
 * boundary says so itself: a PATCH of `{"unit": null}` alone blanks a product's
 * unit, because a partial update has no view of the stored row, and closing it
 * there would mean the boundary reading the database. This form's answer is to
 * name the kind on **every** write — create and edit alike — so the conditional
 * rules always have something to fire on. It does not close the server-side
 * gap for other callers; it keeps this screen out of it.
 *
 * ── The kind is not a control ──────────────────────────────────────────────
 *
 * On a create it is the tab the person is standing on; on an edit it is the
 * row's own. The server does allow a PATCH to move a row between tabs — and
 * re-checks the whole row when it does — but no source asks for that, and a
 * selector that silently retypes a catalog item is a bigger claim than §7.3
 * makes. Recorded as a narrowing, not a decision.
 *
 * ── Two closed lists and one open one (Point 6.4) ──────────────────────────
 *
 * `unit`, `service_type` and `company` are all `DB-05` lists, and the form does
 * not draw them the same way, because the boundary does not treat them the
 * same. `SaveCatalogItemRequest` checks the first two "for shape, not for
 * membership" while `SaveCatalogItem::withListedCompany()` **registers** an
 * unknown company rather than refusing it. So the two the server would have to
 * refuse are `<select>`s over the list (owner's ruling ج) and the one it adopts
 * is an `<input list>` over a `<datalist>` — the native control for "suggest,
 * do not restrict", which no library is needed to be.
 *
 * A stored value the list does not carry stays in its own `<select>`: the
 * column was free text before Point 6.3, so such rows exist, and a select that
 * silently reports its first option instead would rewrite a column nobody
 * touched.
 *
 * ── No price, cost or margin, and not because they are hidden ──────────────
 *
 * §7.3 opens "Descriptive data only — no prices" and `D-21` puts all three on
 * the supplier quotation. `SaveCatalogItemRequest` marks each `prohibited`, so
 * a control for one could never save.
 *
 * Server errors follow `UserFormModal`, as `SupplierFormModal` does: a field
 * refusal shows the server's own sentence, a form-level refusal is a lang key
 * so the banner keeps re-translating when the reader switches AR/EN.
 */
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import { entryLabel, type ListEntry } from '@/services/admin';
import { createCatalogItem, updateCatalogItem, type CatalogItem, type CatalogItemDraft } from '@/services/catalog';

type Kind = 'product' | 'service';

const props = defineProps<{
    open: boolean;
    /** Null for a create. */
    editing: CatalogItem | null;
    /** The tab the screen is standing on, which is the kind a create belongs to. */
    kind: Kind;
    /** `DB-05`'s three lists, loaded by the screen (Point 6.4). */
    units: readonly ListEntry[];
    serviceTypes: readonly ListEntry[];
    companies: readonly ListEntry[];
}>();

const emit = defineEmits<{ saved: [CatalogItem]; cancel: [] }>();

const { t, locale } = useI18n();

/** Every box this form can draw. Which of them it *does* draw is `fields` below. */
const TEXT_FIELDS = [
    'name', 'product_code', 'category', 'unit', 'service_type', 'company', 'description', 'notes',
] as const;

type TextField = (typeof TEXT_FIELDS)[number];

/** §7.3's two field lists, in the order it prints them. */
const FIELDS: Record<Kind, readonly TextField[]> = {
    product: ['name', 'product_code', 'category', 'unit', 'company', 'description'],
    // `name` is `required_if:kind,product`, so a service may carry one and is
    // never asked for one. Without the box the Service tab's Name column has
    // nothing to print but a dash (Point 6.4).
    service: ['name', 'service_type', 'description', 'company', 'notes'],
};

/** The server's three `required_if` rules, mirrored so a refusal costs no round trip. */
const REQUIRED: Record<Kind, readonly TextField[]> = {
    product: ['name', 'unit'],
    service: ['service_type'],
};

/** A long field gets a textarea; §7.3's description and notes are prose. */
const PROSE: readonly TextField[] = ['description', 'notes'];

/** The column lengths, from the Point 1.2 migration. `description` and `notes` are TEXT. */
const MAX_LENGTH: Partial<Record<TextField, number>> = {
    name: 255,
    product_code: 64,
    category: 128,
    unit: 64,
    service_type: 64,
    company: 255,
};

function blank(): Record<TextField, string> {
    return {
        name: '', product_code: '', category: '', unit: '',
        service_type: '', company: '', description: '', notes: '',
    };
}

const values = ref<Record<TextField, string>>(blank());
const opened = ref<Record<TextField, string>>(blank());
const isActive = ref(true);
const openedActive = ref(true);

const saving = ref(false);
const confirmingDiscard = ref(false);

/** Lang keys for what this screen refuses; server sentences for what the server refuses. */
const errorKeys = ref<Partial<Record<TextField | '_form', string>>>({});
const serverErrors = ref<Partial<Record<TextField, string>>>({});

const isEdit = computed(() => props.editing !== null);

/** The record's own kind on an edit; the screen's tab on a create. */
const activeKind = computed<Kind>(() => (props.editing?.kind === 'service' ? 'service' : props.editing?.kind === 'product' ? 'product' : props.kind));

const fields = computed<readonly TextField[]>(() => FIELDS[activeKind.value]);

const dirty = computed(
    () => isActive.value !== openedActive.value || fields.value.some((field) => values.value[field] !== opened.value[field]),
);

function fieldId(field: TextField): string {
    return `catalog-form-${field}`;
}

function testId(field: TextField): string {
    return `catalog-form-${field.replace(/_/g, '-')}`;
}

function isRequired(field: TextField): boolean {
    return REQUIRED[activeKind.value].includes(field);
}

function isProse(field: TextField): boolean {
    return PROSE.includes(field);
}

/** The two closed sets. `company` is deliberately not one of them — see above. */
function isSelect(field: TextField): field is 'unit' | 'service_type' {
    return field === 'unit' || field === 'service_type';
}

/**
 * This field's options, plus the stored value when the list has lost it — a
 * `<select>` cannot hold a code it was not given.
 */
function selectOptions(field: 'unit' | 'service_type'): readonly ListEntry[] {
    const listed = field === 'unit' ? props.units : props.serviceTypes;
    const current = values.value[field];

    return current === '' || listed.some((entry) => entry.code === current)
        ? listed
        : [...listed, { code: current, label_en: current, label_ar: current, position: 0 }];
}

/** The server's sentence when it sent one for this field, otherwise ours. */
function errorFor(field: TextField): string | null {
    const sentence = serverErrors.value[field];

    if (sentence !== undefined) {
        return sentence;
    }

    const key = errorKeys.value[field];

    return key === undefined ? null : t(key);
}

watch(() => [props.open, props.editing, props.kind] as const, ([open]) => {
    if (!open) {
        return;
    }

    const record = props.editing;
    const next = blank();

    if (record !== null) {
        for (const field of TEXT_FIELDS) {
            next[field] = record[field] ?? '';
        }
    }

    values.value = { ...next };
    opened.value = { ...next };
    isActive.value = record?.is_active ?? true;
    openedActive.value = isActive.value;
    errorKeys.value = {};
    serverErrors.value = {};
    confirmingDiscard.value = false;
}, { immediate: true });

/**
 * The three `required_if` rules, mirrored. A courtesy rather than the rule:
 * `SaveCatalogItemRequest` refuses regardless (`D-67`), and it carries
 * `regex:/\S/` beside `name` because `required` accepts "   " and the table's
 * `CHECK (name IS NULL OR btrim(name) <> '')` would answer a blank one with a
 * 500.
 */
function validate(): boolean {
    const found: Partial<Record<TextField, string>> = {};

    for (const field of REQUIRED[activeKind.value]) {
        if (values.value[field].trim() === '') {
            found[field] = `catalog.form.required.${field}`;
        }
    }

    errorKeys.value = found;

    return Object.keys(found).length === 0;
}

/** `OpenAPI §5` — `details[]` names the field, so the sentence lands on the input that caused it. */
function applyServerErrors(error: unknown): void {
    if (!(error instanceof ApiError)) {
        errorKeys.value = { _form: 'catalog.form.unreachable' };
        serverErrors.value = {};

        return;
    }

    const sentences: Partial<Record<TextField, string>> = {};

    for (const field of TEXT_FIELDS) {
        const message = error.messageFor(field);

        if (message !== null) {
            sentences[field] = message;
        }
    }

    errorKeys.value = Object.keys(sentences).length === 0
        ? { _form: error.status === 403 ? 'catalog.form.forbidden' : 'catalog.form.rejected' }
        : {};

    serverErrors.value = sentences;
}

/**
 * The payload: this kind's fields only, `kind` always, and an empty box as
 * `null` rather than `""`. The columns are nullable and "" is not the same
 * absence — a blank `category` stored as an empty string is a value no search
 * expects.
 */
function draft(): CatalogItemDraft {
    const payload: CatalogItemDraft = { kind: activeKind.value, is_active: isActive.value };

    for (const field of fields.value) {
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
            ? await createCatalogItem(draft())
            : await updateCatalogItem(record.id, draft());

        opened.value = { ...values.value };
        openedActive.value = isActive.value;
        emit('saved', written);
    } catch (error) {
        applyServerErrors(error);
    } finally {
        saving.value = false;
    }
}

/**
 * Design System §5.2's "unsaved-change warning", and §6.1's "Escape closes
 * dialogs/menus **without discarding silently**" — one rule seen twice, so
 * Cancel and Escape take the same path.
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
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4" data-testid="catalog-form-modal">
        <div class="modal-scrim absolute inset-0" @click="requestClose()" />

        <form
            class="modal-panel relative flex max-h-[90vh] w-full max-w-xl flex-col gap-4 overflow-y-auto rounded-2xl p-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="catalog-form-title"
            novalidate
            data-testid="catalog-form"
            @submit.prevent="save()"
            @keydown.escape.prevent="requestClose()"
        >
            <h2 id="catalog-form-title" class="text-card-title">
                {{ t(`catalog.form.${isEdit ? 'editTitle' : 'createTitle'}.${activeKind}`) }}
            </h2>

            <p
                v-if="errorKeys._form !== undefined"
                class="form-alert rounded-lg p-3"
                role="alert"
                data-testid="catalog-form-error"
            >
                {{ t(errorKeys._form) }}
            </p>

            <!-- §7.3's field list for this tab, and only this tab's. -->
            <label v-for="field in fields" :key="field" class="flex flex-col gap-1.5" :for="fieldId(field)">
                <span>
                    {{ t(`catalog.column.${field}`) }}
                    <!-- §6.3's "explicit required marker": a word, not only a glyph. -->
                    <span
                        v-if="isRequired(field)"
                        class="text-[var(--color-danger)]"
                        :data-testid="`${testId(field)}-required`"
                    >
                        {{ t('catalog.form.requiredMarker') }}
                    </span>
                </span>

                <textarea
                    v-if="isProse(field)"
                    :id="fieldId(field)"
                    v-model="values[field]"
                    rows="3"
                    :disabled="saving"
                    :aria-invalid="errorFor(field) !== null"
                    class="form-field rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :data-testid="testId(field)"
                />
                <select
                    v-else-if="isSelect(field)"
                    :id="fieldId(field)"
                    v-model="values[field]"
                    :disabled="saving"
                    :aria-invalid="errorFor(field) !== null"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :data-testid="testId(field)"
                >
                    <option value="">{{ t('catalog.form.noSelection') }}</option>
                    <option v-for="entry in selectOptions(field)" :key="entry.code" :value="entry.code">
                        {{ entryLabel(entry, locale) }}
                    </option>
                </select>
                <input
                    v-else
                    :id="fieldId(field)"
                    v-model="values[field]"
                    type="text"
                    :maxlength="MAX_LENGTH[field]"
                    :list="field === 'company' ? 'catalog-form-company-options' : undefined"
                    autocomplete="off"
                    :disabled="saving"
                    :aria-invalid="errorFor(field) !== null"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :data-testid="testId(field)"
                />

                <!-- Suggestions, not a restriction: an unlisted company is
                     registered on save, not refused (Point 6.3). -->
                <datalist v-if="field === 'company'" id="catalog-form-company-options">
                    <option v-for="entry in companies" :key="entry.code" :value="entry.code" :label="entryLabel(entry, locale)" />
                </datalist>

                <span
                    v-if="errorFor(field) !== null"
                    class="text-[var(--color-danger)]"
                    :data-testid="`${testId(field)}-error`"
                >
                    {{ errorFor(field) }}
                </span>
            </label>

            <!-- §7.3's "active product" / "Active service", as a field. §10.4's
                 hiding belongs to Modules 6/7's selection lists, not here. -->
            <label class="flex items-center gap-2" for="catalog-form-is_active">
                <input
                    id="catalog-form-is_active"
                    v-model="isActive"
                    type="checkbox"
                    class="size-5"
                    :disabled="saving"
                    data-testid="catalog-form-is-active"
                />
                <span>{{ t('catalog.status.active') }}</span>
            </label>

            <!-- §5.2's unsaved-change warning. Inside the dialog, because a
                 native `confirm()` is neither translatable nor RTL-aware. -->
            <div
                v-if="confirmingDiscard"
                class="form-warning rounded-lg p-3"
                role="alertdialog"
                aria-labelledby="catalog-form-unsaved-title"
                data-testid="catalog-form-unsaved"
            >
                <p id="catalog-form-unsaved-title">{{ t('catalog.form.unsavedChanges') }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="modal-cancel min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        data-testid="catalog-form-keep-editing"
                        @click="confirmingDiscard = false"
                    >
                        {{ t('catalog.form.keepEditing') }}
                    </button>
                    <button
                        type="button"
                        class="modal-discard min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        data-testid="catalog-form-discard"
                        @click="discard()"
                    >
                        {{ t('catalog.form.discard') }}
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-2">
                <button
                    type="button"
                    class="modal-cancel min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :disabled="saving"
                    data-testid="catalog-form-cancel"
                    @click="requestClose()"
                >
                    {{ t('action.cancel') }}
                </button>

                <button
                    type="submit"
                    class="modal-save min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="saving"
                    data-testid="catalog-form-save"
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
