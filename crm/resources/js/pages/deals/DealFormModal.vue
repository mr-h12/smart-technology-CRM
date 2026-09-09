<script setup lang="ts">
/**
 * §4.3's deal, created and edited in one dialog — Design System §5.2's
 * Form/Builder (Module 5, Point 6.3), on `SupplierQuotationFormModal`'s shape.
 *
 * ── Five fields, and every absence is the server's rule ────────────────────
 *
 * `code`, `status`, `approval_status`, `rejection_reason` and
 * `last_activity_at` are `prohibited` in {@see SaveDealRequest} — a 422, not a
 * silent drop — so a form that posted them would simply never save. They are
 * absent from `DealDraft` for the same reason, so this dialog could not send
 * one by accident.
 *
 * ── `customer_id` and `owner_id` are create-only, because PATCH says so ────
 *
 * Both are `prohibited` on an update. The customer is the one thing about a
 * deal that cannot change — a different customer is a different deal — and the
 * owner moves through `PATCH /deals/{id}/assign`, which §3.4 gives its own
 * permission row (`assign_owner`, granted to two roles where `edit` reaches
 * five). So on an edit both controls are **not drawn at all**, rather than
 * drawn disabled: a disabled control invites the question "why", and the answer
 * is a different screen.
 *
 * ── No owner picker, on Module 3's precedent ───────────────────────────────
 *
 * `owner_id` is writable on create, and filling a picker needs a user list that
 * `deal.create` does not carry — `CustomerFormModal` reached the same wall for
 * `sales_owner_id` and recorded it as a narrowing rather than a decision. The
 * owner is therefore set through the assign action (Point 6.7), and the field
 * here is a **plain identifier box**: the same ugly, stated ceiling Module 6
 * accepted for its `deal_id` filter, and honest about what it is.
 *
 * ── One client-side check, and it is a courtesy ────────────────────────────
 *
 * `customer_id` is required on create. The server enforces it (`required|uuid`)
 * and this only saves a round trip; `D-67` keeps every rule that matters on the
 * server, and the endpoint tests prove them there.
 */
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import { createDeal, updateDeal, type Deal } from '@/services/deals';
import { DEAL_SERVICE_TYPES, DEAL_SOURCES } from '@/services/deals';
import type { Customer } from '@/services/customers';

const props = defineProps<{
    open: boolean;
    /** Null is a create; a deal is an edit. */
    editing: Deal | null;
    customers: Customer[];
}>();

const emit = defineEmits<{ saved: []; cancel: [] }>();

const { t } = useI18n();

const FIELDS = ['customer_id', 'owner_id', 'title', 'source', 'service_type'] as const;

type Field = (typeof FIELDS)[number];

type Values = Record<Field, string>;

/** "" is "not given" for every one of them; `draft()` turns that into `null`. */
function blank(): Values {
    return { customer_id: '', owner_id: '', title: '', source: '', service_type: '' };
}

const values = ref<Values>(blank());
/** What the form was opened with. The dirty check is against this, not against blank. */
const opened = ref<Values>(blank());

const saving = ref(false);
const confirmingDiscard = ref(false);
const errorKeys = ref<Partial<Record<Field | '_form', string>>>({});
const serverErrors = ref<Partial<Record<Field, string>>>({});

const sources = DEAL_SOURCES;
const serviceTypes = DEAL_SERVICE_TYPES;

const isEdit = computed(() => props.editing !== null);
const dirty = computed(() => FIELDS.some((field) => values.value[field] !== opened.value[field]));

function fieldId(field: Field): string {
    return `deal-form-${field}`;
}

function testId(field: Field): string {
    return `deal-form-${field.replace(/_/g, '-')}`;
}

function errorFor(field: Field): string | null {
    const sentence = serverErrors.value[field];

    if (sentence !== undefined) {
        return sentence;
    }

    const key = errorKeys.value[field];

    return key === undefined ? null : t(key);
}

/**
 * The dialog is filled from the row it was opened on, every time it opens — a
 * dialog reopened on another deal must not show the first one's values.
 */
watch(
    () => [props.open, props.editing] as const,
    () => {
        if (!props.open) {
            return;
        }

        const deal = props.editing;

        const next: Values = deal === null
            ? blank()
            : {
                customer_id: deal.customer_id,
                owner_id: deal.owner_id ?? '',
                title: deal.title ?? '',
                source: deal.source ?? '',
                service_type: deal.service_type ?? '',
            };

        values.value = { ...next };
        opened.value = { ...next };
        errorKeys.value = {};
        serverErrors.value = {};
        confirmingDiscard.value = false;
    },
    { immediate: true },
);

/** "" means "not given", which on the wire is `null` and never an empty string. */
function orNull(value: string): string | null {
    return value === '' ? null : value;
}

function applyServerErrors(error: unknown): void {
    if (!(error instanceof ApiError)) {
        errorKeys.value = { _form: 'deals.form.unreachable' };
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

    // A form-level refusal is a key translated here; a field refusal is the
    // server's own sentence, already localised, rendered verbatim.
    errorKeys.value = Object.keys(sentences).length === 0
        ? { _form: error.status === 403 ? 'deals.form.forbidden' : 'deals.form.rejected' }
        : {};

    serverErrors.value = sentences;
}

async function save(): Promise<void> {
    errorKeys.value = {};
    serverErrors.value = {};

    // The one courtesy check. The server enforces it too.
    if (!isEdit.value && values.value.customer_id === '') {
        errorKeys.value = { customer_id: 'deals.form.customerRequired' };

        return;
    }

    saving.value = true;

    try {
        if (props.editing === null) {
            await createDeal({
                customer_id: values.value.customer_id,
                owner_id: orNull(values.value.owner_id),
                title: orNull(values.value.title),
                source: orNull(values.value.source),
                service_type: orNull(values.value.service_type),
            });
        } else {
            // No `customer_id`, no `owner_id`: both are `prohibited` on a
            // PATCH, so sending the unchanged value would 422 the save.
            await updateDeal(props.editing.id, {
                title: orNull(values.value.title),
                source: orNull(values.value.source),
                service_type: orNull(values.value.service_type),
            });
        }

        emit('saved');
    } catch (error) {
        applyServerErrors(error);
    } finally {
        saving.value = false;
    }
}

/**
 * Design System §5.2's "unsaved-change warning" and §6.1's "Escape closes
 * dialogs/menus **without discarding silently**" are one rule seen twice, so
 * Cancel and Escape take the same path. A native `confirm()` would be neither
 * translatable nor mirrored.
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
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4" data-testid="deal-form-modal">
        <div class="modal-scrim absolute inset-0" @click="requestClose()" />

        <form
            class="modal-panel relative flex max-h-[90vh] w-full max-w-xl flex-col gap-4 overflow-y-auto rounded-2xl p-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="deal-form-title"
            novalidate
            data-testid="deal-form"
            @submit.prevent="save()"
            @keydown.escape.prevent="requestClose()"
        >
            <h2 id="deal-form-title" class="text-card-title">
                {{ isEdit ? t('deals.form.editTitle') : t('deals.form.createTitle') }}
            </h2>

            <p
                v-if="errorKeys._form !== undefined"
                class="form-alert rounded-lg p-3"
                role="alert"
                data-testid="deal-form-error"
            >
                {{ t(errorKeys._form) }}
            </p>

            <!-- Create only: `customer_id` is `prohibited` on a PATCH, a
                 different customer being a different deal. -->
            <label v-if="!isEdit" class="flex flex-col gap-1.5" :for="fieldId('customer_id')">
                <span>
                    {{ t('deals.column.customer') }}
                    <!-- §6.3's "explicit required marker": a word, not only a glyph. -->
                    <span class="text-[var(--color-danger)]">{{ t('deals.form.required') }}</span>
                </span>
                <select
                    :id="fieldId('customer_id')"
                    v-model="values.customer_id"
                    :disabled="saving"
                    :aria-invalid="errorFor('customer_id') !== null"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :data-testid="testId('customer_id')"
                >
                    <option value="">{{ t('deals.form.customerNone') }}</option>
                    <option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}</option>
                </select>
                <span
                    v-if="errorFor('customer_id') !== null"
                    class="text-[var(--color-danger)]"
                    data-testid="deal-form-customer-id-error"
                >
                    {{ errorFor('customer_id') }}
                </span>
            </label>

            <!-- ⚠️ Create only, and a raw identifier: filling a picker needs a
                 user list `deal.create` does not carry, and changing the owner
                 afterwards is `assign_owner`'s own route. -->
            <label v-if="!isEdit" class="flex flex-col gap-1.5" :for="fieldId('owner_id')">
                <span>{{ t('deals.column.owner') }}</span>
                <input
                    :id="fieldId('owner_id')"
                    v-model="values.owner_id"
                    type="text"
                    :disabled="saving"
                    :placeholder="t('deals.form.ownerPlaceholder')"
                    :aria-invalid="errorFor('owner_id') !== null"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :data-testid="testId('owner_id')"
                />
                <span class="text-[var(--color-text-muted)]">{{ t('deals.form.ownerCeiling') }}</span>
                <span
                    v-if="errorFor('owner_id') !== null"
                    class="text-[var(--color-danger)]"
                    data-testid="deal-form-owner-id-error"
                >
                    {{ errorFor('owner_id') }}
                </span>
            </label>

            <label class="flex flex-col gap-1.5" :for="fieldId('title')">
                <span>{{ t('deals.column.title') }}</span>
                <input
                    :id="fieldId('title')"
                    v-model="values.title"
                    type="text"
                    :disabled="saving"
                    :aria-invalid="errorFor('title') !== null"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :data-testid="testId('title')"
                />
                <span v-if="errorFor('title') !== null" class="text-[var(--color-danger)]" data-testid="deal-form-title-error">
                    {{ errorFor('title') }}
                </span>
            </label>

            <label class="flex flex-col gap-1.5" :for="fieldId('service_type')">
                <span>{{ t('deals.column.serviceType') }}</span>
                <select
                    :id="fieldId('service_type')"
                    v-model="values.service_type"
                    :disabled="saving"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :data-testid="testId('service_type')"
                >
                    <option value="">{{ t('deals.form.notGiven') }}</option>
                    <option v-for="code in serviceTypes" :key="code" :value="code">{{ t(`deals.serviceType.${code}`) }}</option>
                </select>
            </label>

            <label class="flex flex-col gap-1.5" :for="fieldId('source')">
                <span>{{ t('deals.filter.source') }}</span>
                <select
                    :id="fieldId('source')"
                    v-model="values.source"
                    :disabled="saving"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :data-testid="testId('source')"
                >
                    <option value="">{{ t('deals.form.notGiven') }}</option>
                    <option v-for="code in sources" :key="code" :value="code">{{ t(`deals.source.${code}`) }}</option>
                </select>
            </label>

            <!-- ⚠️ An in-dialog panel, never a native confirm(): that dialog is
                 neither translatable nor mirrored for RTL. -->
            <div
                v-if="confirmingDiscard"
                class="form-alert flex flex-wrap items-center justify-between gap-3 rounded-lg p-3"
                role="alertdialog"
                data-testid="deal-form-discard"
            >
                <span>{{ t('deals.form.discardPrompt') }}</span>
                <span class="flex gap-2">
                    <button
                        type="button"
                        class="row-action min-h-11 rounded-lg px-3"
                        data-testid="deal-form-discard-keep"
                        @click="confirmingDiscard = false"
                    >
                        {{ t('deals.form.discardKeep') }}
                    </button>
                    <button
                        type="button"
                        class="row-action min-h-11 rounded-lg px-3"
                        data-testid="deal-form-discard-confirm"
                        @click="discard()"
                    >
                        {{ t('deals.form.discardConfirm') }}
                    </button>
                </span>
            </div>

            <footer class="flex flex-wrap justify-end gap-2">
                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-4 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :disabled="saving"
                    data-testid="deal-form-cancel"
                    @click="requestClose()"
                >
                    {{ t('action.cancel') }}
                </button>
                <button
                    type="submit"
                    class="create-action min-h-11 rounded-lg px-4 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="saving"
                    data-testid="deal-form-submit"
                >
                    {{ saving ? t('deals.form.saving') : t('action.save') }}
                </button>
            </footer>
        </form>
    </div>
</template>

<style scoped>
.modal-scrim {
    /* There is no `--color-scrim` token — one was invented here and
       `LogicalPropertiesTest`'s token check caught it. §3.2's set is what a
       component may consume, so the scrim is mixed from `--color-text` exactly
       as `SupplierQuotationFormModal` does. */
    background-color: color-mix(in srgb, var(--color-text) 45%, transparent);
}

.modal-panel {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border);
    color: var(--color-text);
}

.form-field {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.form-alert {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-border-strong);
}

.row-action {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.create-action {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
}
</style>
