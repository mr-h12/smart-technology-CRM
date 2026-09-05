<script setup lang="ts">
/**
 * §7.2's supplier-quotation header form — create and edit, one component
 * (Module 6, Point 6.3), on `SupplierFormModal`'s shape (Module 4 Point 4.2).
 *
 * ── One permission draws both verbs ────────────────────────────────────────
 *
 * §3.6's write cell is a single grant, `supplier_quotation.create`, held by the
 * Manager, Team Leader, Outdoor Sales, Indoor Sales and Procurement — and the
 * route carries it on **both** `POST /supplier-quotations` and
 * `PATCH /supplier-quotations/{id}`. So there is no second permission to ask
 * about, and `SupplierQuotationsView` decides whether this dialog opens at all.
 * The CEO is the documented negative case: §3.6 grants them `view` and no more.
 *
 * ── Three of §7.2's rows are absent, each measured ─────────────────────────
 *
 * | row | why it is not here |
 * |---|---|
 * | `code` | §7.2 marks it "Automatic" and `SaveSupplierQuotationRequest` answers a supplied one with `prohibited`. A control for it could only build a request that cannot succeed. |
 * | `total_price` + `currency_id` | **Unreachable from the SPA.** See below. |
 * | `Line items` | Point 6.4's, not this point's. |
 *
 * ── The total and the currency: a stated ceiling, not an oversight ─────────
 *
 * They are one pair, not two fields: Point 1.1 put
 * `CHECK ((total_price IS NULL) = (currency_id IS NULL))` on the table and
 * `SaveSupplierQuotationRequest` mirrors it with `required_with` in both
 * directions, so neither may be sent without the other. And `currency_id` is a
 * UUID **nothing in this SPA can obtain**: `CurrencyController::payload()`
 * publishes `code`, `rounding_unit`, `rounding_enabled` and `is_base` and no
 * `id`, `FxRateController` names its currencies by code too, and
 * `GET /currencies` sits behind `admin.system_settings`, which
 * `PermissionMatrix` grants to the Super Admin **alone** — so every role that
 * may create an offer is refused the lookup as well.
 *
 * Owner's ruling of 2026-09-05: ship the rest of §7.2's header and say so. The
 * dialog says it to the reader too, because a form that silently drops the
 * offer's total teaches the wrong thing about where the total went. Registered
 * in `CHECKLIST.md`; the pair returns once Module 2's payload can answer.
 *
 * The consequence for an **edit** is the reason it is safe:
 * `SupplierQuotationDraft::only()` keeps a key by `array_key_exists`, so a body
 * that never mentions the two columns leaves them exactly as they were. Sending
 * `null` would erase a total this form cannot show, and this form sends neither.
 *
 * ── A form-level refusal is a key; a field refusal is the server's sentence ─
 *
 * `SupplierFormModal` settled this and this form follows it rather than
 * inventing a third way. A server sentence was localised once, when the request
 * was answered, so a banner holding one would stop re-translating when the
 * reader switches AR/EN; a field sentence has no key to fall back on, because
 * the server said exactly what was wrong. (That the SPA's forms each map errors
 * their own way is recorded in `CHECKLIST.md` and is not this point's to close.)
 */
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import { listCatalogItems, type CatalogItem } from '@/services/catalog';
import { downloadFile } from '@/services/files';
import {
    attachSupplierQuotationDocument,
    createSupplierQuotation,
    readSupplierQuotation,
    updateSupplierQuotation,
    type SupplierQuotation,
    type SupplierQuotationDocument,
    type SupplierQuotationDraft,
    type SupplierQuotationLineDraft,
} from '@/services/supplier-quotations';
import type { Supplier } from '@/services/suppliers';
import { useAuth } from '@/stores/auth';

const props = defineProps<{
    open: boolean;
    /** Null for a create. */
    editing: SupplierQuotation | null;
    /**
     * The list screen's own `listSuppliers({ perPage: 100 })` result, handed
     * down rather than fetched again: a second call would be a second request
     * and a second copy of the same stated ceiling.
     */
    suppliers: Supplier[];
}>();

const emit = defineEmits<{ saved: [SupplierQuotation]; cancel: [] }>();

const { t } = useI18n();

/** The fields a server refusal can name, which are the inputs this form has. */
const FIELDS = ['supplier_id', 'deal_id', 'offer_date', 'valid_until', 'notes'] as const;

type Field = (typeof FIELDS)[number];

type Values = Record<Field, string>;

/** "" is "not given" for every one of them; `draft()` turns that into `null`. */
function blank(): Values {
    return { supplier_id: '', deal_id: '', offer_date: '', valid_until: '', notes: '' };
}

const values = ref<Values>(blank());

/** What the form was opened with. The dirty check is against this, not against blank. */
const opened = ref<Values>(blank());

const saving = ref(false);
const confirmingDiscard = ref(false);

const errorKeys = ref<Partial<Record<Field | '_form', string>>>({});
const serverErrors = ref<Partial<Record<Field, string>>>({});

// ── §7.2's `Line items` row (Point 6.4) ────────────────────────────────────

/** The four keys a line refusal can name — `SaveSupplierQuotationRequest`'s `items.*.…`. */
const LINE_FIELDS = ['catalog_item_id', 'product_name', 'unit_price', 'quantity'] as const;

type LineField = (typeof LINE_FIELDS)[number];

/**
 * Both product keys are held, and exactly one is ever sent (`D-22`).
 *
 * `unit_price` and `quantity` are **strings**, kept as typed: `DB-07` forbids
 * float anywhere near a price and the server sends `1500.000000`, so parsing
 * one to a JavaScript number and printing it back would change the value the
 * person is looking at.
 */
interface LineValues extends Record<LineField, string> {}

function blankLine(): LineValues {
    return { catalog_item_id: '', product_name: '', unit_price: '', quantity: '' };
}

const lines = ref<LineValues[]>([]);

/** What the lines were opened with, for the dirty check. */
const openedLines = ref('[]');

/**
 * Three states, because `items` is three-valued on a `PATCH`.
 *
 * `ready` — the set in `lines` is what the offer has, so it may be sent.
 * `loading` — the detail read is in flight.
 * `unavailable` — the read failed. **`items` is then omitted from the body**,
 * because sending this empty editor would erase every line on the offer
 * (`SupplierQuotationDraft::forUpdate()`: absent leaves them alone, `[]` clears
 * them). The dialog says so rather than pretending the offer has no lines.
 */
const linesState = ref<'ready' | 'loading' | 'unavailable'>('ready');

/** §10.4's selection list: active items only. Best-effort — `D-22` still lets a name be typed. */
const catalogItems = ref<CatalogItem[]>([]);

/** Keyed `"<index>.<field>"`, the way the server names it minus the `items.` prefix. */
const lineErrors = ref<Map<string, string>>(new Map());

// ── §7.2's `pdf_file` row (Point 6.5) ──────────────────────────────────────

const auth = useAuth();

/**
 * §3.6's **third** grant, and not `create`.
 *
 * `POST /supplier-quotations/{id}/documents` carries `upload_attachment`, which
 * `PermissionMatrix` gives to the same five roles as `create` today. That is a
 * fact about the current seed, not about the system: RBAC is database-backed
 * and dynamic (`SEC-07`) and §3.11's role screen can revoke one row while the
 * other stands — which is precisely what happened to a live role on
 * 2026-08-31. A control keyed on `create` would outlive its own permission.
 */
const canUpload = computed(() => auth.hasPermission('supplier_quotation.upload_attachment'));

/**
 * What was attached **in this dialog**, and nothing else.
 *
 * ⚠️ There is no list to load. `SupplierQuotationPayload::detail()` returns the
 * header and `items`; the module publishes `uploadDocument` and no read; and
 * the whole API has exactly one `/files` route, the download. So an attachment
 * made before this dialog opened cannot be shown at all. Owner's ruling of
 * 2026-09-05: ship it and say so, in the panel as well as on the register.
 */
const attachments = ref<SupplierQuotationDocument[]>([]);

const uploading = ref(false);
const downloadingId = ref<string | null>(null);

/** A key when this screen has one, the server's own sentence when it sent one. */
const attachmentErrorKey = ref<string | null>(null);
const attachmentError = ref<string | null>(null);

const isEdit = computed(() => props.editing !== null);

const dirty = computed(() => FIELDS.some((field) => values.value[field] !== opened.value[field])
    || JSON.stringify(lines.value) !== openedLines.value);

/** §7.3 keeps a service's label in `service_type` and a product's in `name`; the id is the last resort. */
function catalogLabel(item: CatalogItem): string {
    return item.name ?? item.service_type ?? item.id;
}

function lineTestId(index: number, suffix: string): string {
    return `supplier-quotation-line-${index}-${suffix}`;
}

function lineErrorFor(index: number, field: LineField): string | null {
    return lineErrors.value.get(`${index}.${field}`) ?? null;
}

/** Whichever of the two product keys the server named — they are one control here. */
function lineProductError(index: number): string | null {
    return lineErrorFor(index, 'catalog_item_id') ?? lineErrorFor(index, 'product_name');
}

function addLine(): void {
    lines.value.push(blankLine());
}

function removeLine(index: number): void {
    lines.value.splice(index, 1);
    lineErrors.value = new Map();
}

/** The id of every control, so `<label for>` and the error span agree. */
function fieldId(field: Field): string {
    return `supplier-quotation-form-${field}`;
}

function testId(field: Field): string {
    return `supplier-quotation-form-${field.replace(/_/g, '-')}`;
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
        next.supplier_id = record.supplier_id;
        next.deal_id = record.deal_id ?? '';
        next.offer_date = record.offer_date ?? '';
        next.valid_until = record.valid_until ?? '';
        next.notes = record.notes ?? '';
    }

    values.value = { ...next };
    opened.value = { ...next };
    errorKeys.value = {};
    serverErrors.value = {};
    lineErrors.value = new Map();
    confirmingDiscard.value = false;

    // A dialog reopened on another offer must not show the first one's files.
    attachments.value = [];
    attachmentErrorKey.value = null;
    attachmentError.value = null;
    uploading.value = false;
    downloadingId.value = null;

    void loadCatalog();
    void loadLines(record);
}, { immediate: true });

/**
 * §10.4's rule read from the source: a deactivated product is "**Hidden** from
 * selection lists" for a new quotation while it stays functional on an open
 * one. So the picker asks for the active items and nothing else.
 *
 * Best-effort, like the list screen's supplier call: a caller may hold
 * `supplier_quotation.create` and be refused the catalog, and `D-22` still
 * lets them type the product's name. An empty picker is worse than a name box;
 * an error page over a working form is worse than both.
 *
 * ⚠️ Stated ceiling: `CatalogItemListCriteria::MAX_PER_PAGE` is 100, so an item
 * past the hundredth is not in the list. Typing its **name** still resolves to
 * it rather than duplicating it — `ProvisionCatalogProduct::productIdFor()`
 * looks the name up before creating — so the ceiling costs convenience, not
 * correctness.
 */
async function loadCatalog(): Promise<void> {
    try {
        catalogItems.value = (await listCatalogItems({ perPage: 100, isActive: true })).items;
    } catch {
        catalogItems.value = [];
    }
}

/**
 * An edit's lines come from `GET /supplier-quotations/{id}`: the list summary
 * has none, because `SupplierQuotationPayload::many()` calls `of()` and only
 * `detail()` carries `items`.
 *
 * A failure here is **not** "no lines" — see `linesState`.
 */
async function loadLines(record: SupplierQuotation | null): Promise<void> {
    if (record === null) {
        lines.value = [];
        openedLines.value = JSON.stringify([]);
        linesState.value = 'ready';

        return;
    }

    lines.value = [];
    openedLines.value = JSON.stringify([]);
    linesState.value = 'loading';

    try {
        const detail = await readSupplierQuotation(record.id);

        lines.value = detail.items.map((line) => ({
            ...blankLine(),
            catalog_item_id: line.catalog_item_id,
            unit_price: line.unit_price,
            quantity: line.quantity,
        }));
        openedLines.value = JSON.stringify(lines.value);
        linesState.value = 'ready';
    } catch {
        lines.value = [];
        openedLines.value = JSON.stringify([]);
        linesState.value = 'unavailable';
    }
}

/**
 * The one client-side rule, and it is a courtesy rather than the rule.
 *
 * `SaveSupplierQuotationRequest` carries `required` on `supplier_id` for a
 * `POST` — §4.1 draws exactly one supplier into this entity and Point 1.1 made
 * the column `NOT NULL`. Checking it here saves a round trip; the server
 * refuses regardless (`D-67`).
 */
function validate(): boolean {
    errorKeys.value = values.value.supplier_id === '' ? { supplier_id: 'supplierQuotations.form.supplierRequired' } : {};

    return errorKeys.value.supplier_id === undefined;
}

/** `OpenAPI §5` — `details[]` names the field, so the sentence lands on the input that caused it. */
function applyServerErrors(error: unknown): void {
    if (!(error instanceof ApiError)) {
        errorKeys.value = { _form: 'supplierQuotations.form.unreachable' };
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

    // Laravel keys a nested failure `items.0.unit_price` and
    // `ApiExceptionRenderer::validation()` passes that key through untouched,
    // so the index in the name is the index in this editor.
    const lineSentences = new Map<string, string>();

    lines.value.forEach((_line, index) => {
        for (const field of LINE_FIELDS) {
            const message = error.messageFor(`items.${index}.${field}`);

            if (message !== null) {
                lineSentences.set(`${index}.${field}`, message);
            }
        }
    });

    lineErrors.value = lineSentences;

    errorKeys.value = Object.keys(sentences).length === 0 && lineSentences.size === 0
        ? { _form: error.status === 403 ? 'supplierQuotations.form.forbidden' : 'supplierQuotations.form.rejected' }
        : {};

    serverErrors.value = sentences;
}

/**
 * `D-22` made structural rather than validated: the control offers a catalog
 * item **or** "type a name instead", so only one of the two keys can exist.
 * `SaveSupplierQuotationRequest` answers a line carrying both with a 422, and
 * this editor cannot build one.
 */
function items(): SupplierQuotationLineDraft[] {
    return lines.value.map((line) => (line.catalog_item_id === ''
        ? { product_name: line.product_name.trim(), unit_price: line.unit_price, quantity: line.quantity }
        : { catalog_item_id: line.catalog_item_id, unit_price: line.unit_price, quantity: line.quantity }));
}

/**
 * The payload: an empty box sent as `null` rather than `""`.
 *
 * Every column below is nullable and "" is not the same absence — a blank
 * `deal_id` stored as an empty string is not a UUID any filter will match, and
 * `SaveSupplierQuotationRequest` would answer it with a 422 anyway.
 * `supplier_id` is the exception: it is required, so it is always a string.
 *
 * `total_price` and `currency_id` are **absent keys**, never `null` ones — see
 * the header. On a `PATCH` that is what leaves the offer's total alone.
 */
function draft(): SupplierQuotationDraft {
    const current = values.value;
    const notes = current.notes.trim();
    const deal = current.deal_id.trim();

    const header: SupplierQuotationDraft = {
        supplier_id: current.supplier_id,
        deal_id: deal === '' ? null : deal,
        offer_date: current.offer_date === '' ? null : current.offer_date,
        valid_until: current.valid_until === '' ? null : current.valid_until,
        notes: notes === '' ? null : notes,
    };

    // The whole reason `linesState` exists. `items: []` from an editor that
    // never received the offer's lines would clear them; an absent key leaves
    // them exactly as they are.
    return linesState.value === 'ready' ? { ...header, items: items() } : header;
}

async function save(): Promise<void> {
    if (!validate()) {
        return;
    }

    saving.value = true;
    serverErrors.value = {};
    lineErrors.value = new Map();

    try {
        const record = props.editing;
        const written = record === null
            ? await createSupplierQuotation(draft())
            : await updateSupplierQuotation(record.id, draft());

        opened.value = { ...values.value };
        openedLines.value = JSON.stringify(lines.value);
        emit('saved', written);
    } catch (error) {
        applyServerErrors(error);
    } finally {
        saving.value = false;
    }
}

/**
 * §17's upload. No client-side type or size check, deliberately:
 * `UploadSupplierQuotationDocumentRequest` carries neither, because §17 reads
 * the type from the **bytes** and `D-71`'s ceiling is configuration (`AP-08`).
 * A guess here would be a second rule, one deployment away from disagreeing
 * with the only one that reads the file.
 *
 * The input is cleared either way, so the same file can be retried after a
 * refusal — a `change` event does not fire twice for an unchanged value.
 */
async function upload(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    const record = props.editing;

    if (file === undefined || record === null) {
        return;
    }

    uploading.value = true;
    attachmentErrorKey.value = null;
    attachmentError.value = null;

    try {
        attachments.value.push(await attachSupplierQuotationDocument(record.id, file));
    } catch (error) {
        if (error instanceof ApiError) {
            // `OpenAPI §5` — `details[]` names `document` when the file itself
            // was refused, which is the control the person used.
            attachmentError.value = error.messageFor('document');
            attachmentErrorKey.value = attachmentError.value !== null
                ? null
                : (error.status === 403 ? 'supplierQuotations.form.attachmentForbidden' : 'supplierQuotations.form.attachmentRejected');
        } else {
            attachmentErrorKey.value = 'supplierQuotations.form.attachmentUnreachable';
        }
    } finally {
        uploading.value = false;
        input.value = '';
    }
}

/**
 * §17's download, through Storage's own route (`D-38`: the permission is the
 * parent's). Only ever offered for a `clean` file — `DownloadFile::forActor()`
 * refuses anything else with a 404 before it considers permission (`SEC-15`),
 * so a control on a `pending` or `infected` row could only ever fail.
 */
async function download(document_: SupplierQuotationDocument): Promise<void> {
    downloadingId.value = document_.id;
    attachmentErrorKey.value = null;
    attachmentError.value = null;

    try {
        await downloadFile(document_.id, document_.original_name);
    } catch (error) {
        attachmentErrorKey.value = error instanceof ApiError && error.status === 403
            ? 'supplierQuotations.form.attachmentForbidden'
            : 'supplierQuotations.form.attachmentRejected';
    } finally {
        downloadingId.value = null;
    }
}

/**
 * Design System §5.2's "unsaved-change warning" and §6.1's "Escape closes
 * dialogs/menus **without discarding silently**" are one rule seen twice, so
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
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4" data-testid="supplier-quotation-form-modal">
        <div class="modal-scrim absolute inset-0" @click="requestClose()" />

        <form
            class="modal-panel relative flex max-h-[90vh] w-full max-w-xl flex-col gap-4 overflow-y-auto rounded-2xl p-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="supplier-quotation-form-title"
            novalidate
            data-testid="supplier-quotation-form"
            @submit.prevent="save()"
            @keydown.escape.prevent="requestClose()"
        >
            <h2 id="supplier-quotation-form-title" class="text-card-title">
                {{ isEdit ? t('supplierQuotations.form.editTitle') : t('supplierQuotations.form.createTitle') }}
            </h2>

            <p
                v-if="errorKeys._form !== undefined"
                class="form-alert rounded-lg p-3"
                role="alert"
                data-testid="supplier-quotation-form-error"
            >
                {{ t(errorKeys._form) }}
            </p>

            <label class="flex flex-col gap-1.5" :for="fieldId('supplier_id')">
                <span>
                    {{ t('supplierQuotations.column.supplier') }}
                    <!-- §6.3's "explicit required marker": a word, not only a glyph. -->
                    <span class="text-[var(--color-danger)]">{{ t('supplierQuotations.form.required') }}</span>
                </span>
                <select
                    :id="fieldId('supplier_id')"
                    v-model="values.supplier_id"
                    :disabled="saving"
                    :aria-invalid="errorFor('supplier_id') !== null"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :data-testid="testId('supplier_id')"
                >
                    <option value="">{{ t('supplierQuotations.form.supplierNone') }}</option>
                    <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option>
                </select>
                <span
                    v-if="errorFor('supplier_id') !== null"
                    class="text-[var(--color-danger)]"
                    data-testid="supplier-quotation-form-supplier-id-error"
                >
                    {{ errorFor('supplier_id') }}
                </span>
            </label>

            <!-- ⚠️ A raw identifier, and the same stated ceiling the filter
                 carries: there is no deals screen in this application yet, so
                 there is no list to choose from. Module 5 replaces this with a
                 picker. `D-51` makes the link optional either way. -->
            <label class="flex flex-col gap-1.5" :for="fieldId('deal_id')">
                <span>{{ t('supplierQuotations.column.deal') }}</span>
                <input
                    :id="fieldId('deal_id')"
                    v-model="values.deal_id"
                    type="text"
                    autocomplete="off"
                    :disabled="saving"
                    :aria-invalid="errorFor('deal_id') !== null"
                    :placeholder="t('supplierQuotations.filter.dealPlaceholder')"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :data-testid="testId('deal_id')"
                />
                <span
                    v-if="errorFor('deal_id') !== null"
                    class="text-[var(--color-danger)]"
                    data-testid="supplier-quotation-form-deal-id-error"
                >
                    {{ errorFor('deal_id') }}
                </span>
            </label>

            <label class="flex flex-col gap-1.5" :for="fieldId('offer_date')">
                <span>{{ t('supplierQuotations.column.offerDate') }}</span>
                <input
                    :id="fieldId('offer_date')"
                    v-model="values.offer_date"
                    type="date"
                    :disabled="saving"
                    :aria-invalid="errorFor('offer_date') !== null"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :data-testid="testId('offer_date')"
                />
                <span
                    v-if="errorFor('offer_date') !== null"
                    class="text-[var(--color-danger)]"
                    data-testid="supplier-quotation-form-offer-date-error"
                >
                    {{ errorFor('offer_date') }}
                </span>
            </label>

            <label class="flex flex-col gap-1.5" :for="fieldId('valid_until')">
                <span>{{ t('supplierQuotations.column.validUntil') }}</span>
                <input
                    :id="fieldId('valid_until')"
                    v-model="values.valid_until"
                    type="date"
                    :disabled="saving"
                    :aria-invalid="errorFor('valid_until') !== null"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :data-testid="testId('valid_until')"
                />
                <span
                    v-if="errorFor('valid_until') !== null"
                    class="text-[var(--color-danger)]"
                    data-testid="supplier-quotation-form-valid-until-error"
                >
                    {{ errorFor('valid_until') }}
                </span>
            </label>

            <label class="flex flex-col gap-1.5" :for="fieldId('notes')">
                <span>{{ t('supplierQuotations.form.notes') }}</span>
                <textarea
                    :id="fieldId('notes')"
                    v-model="values.notes"
                    rows="3"
                    :disabled="saving"
                    class="form-field rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :data-testid="testId('notes')"
                />
            </label>

            <!-- §7.2's `Line items` row — "Product · **price** · quantity
                 (+ to add more)" (Point 6.4). -->
            <fieldset v-if="linesState === 'ready'" class="flex flex-col gap-3">
                <legend class="text-card-title">{{ t('supplierQuotations.form.lines') }}</legend>

                <p v-if="lines.length === 0" class="text-[var(--color-text-muted)]" data-testid="supplier-quotation-form-lines-none">
                    {{ t('supplierQuotations.form.linesNone') }}
                </p>

                <div
                    v-for="(line, index) in lines"
                    :key="index"
                    class="line-row flex flex-wrap items-end gap-2 rounded-lg p-3"
                    :data-testid="`supplier-quotation-line-${index}`"
                >
                    <!-- `D-22` as a control rather than a rule: an item, or a
                         name. There is no third state, so a line carrying both
                         cannot be built here. -->
                    <label class="flex min-w-40 flex-1 flex-col gap-1.5" :for="lineTestId(index, 'product')">
                        <span>{{ t('supplierQuotations.form.lineProduct') }}</span>
                        <select
                            :id="lineTestId(index, 'product')"
                            v-model="line.catalog_item_id"
                            :disabled="saving"
                            :aria-invalid="lineProductError(index) !== null"
                            class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                            :data-testid="lineTestId(index, 'product')"
                        >
                            <option value="">{{ t('supplierQuotations.form.lineProductByName') }}</option>
                            <option v-for="item in catalogItems" :key="item.id" :value="item.id">{{ catalogLabel(item) }}</option>
                        </select>
                    </label>

                    <label
                        v-if="line.catalog_item_id === ''"
                        class="flex min-w-40 flex-1 flex-col gap-1.5"
                        :for="lineTestId(index, 'product-name')"
                    >
                        <span>{{ t('supplierQuotations.form.lineProductName') }}</span>
                        <input
                            :id="lineTestId(index, 'product-name')"
                            v-model="line.product_name"
                            type="text"
                            maxlength="255"
                            autocomplete="off"
                            :disabled="saving"
                            class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                            :data-testid="lineTestId(index, 'product-name')"
                        />
                    </label>

                    <!-- `inputmode` and not `type="number"`: the value is a
                         decimal string the server sent at `D-68`'s scale, and a
                         number input would re-format it. `DB-07`. -->
                    <label class="flex w-32 flex-col gap-1.5" :for="lineTestId(index, 'unit-price')">
                        <span>{{ t('supplierQuotations.form.linePrice') }}</span>
                        <input
                            :id="lineTestId(index, 'unit-price')"
                            v-model="line.unit_price"
                            type="text"
                            inputmode="decimal"
                            :disabled="saving"
                            :aria-invalid="lineErrorFor(index, 'unit_price') !== null"
                            class="form-field min-h-11 rounded-lg px-3 py-2 text-end tabular-nums focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                            :data-testid="lineTestId(index, 'unit-price')"
                        />
                    </label>

                    <label class="flex w-28 flex-col gap-1.5" :for="lineTestId(index, 'quantity')">
                        <span>{{ t('supplierQuotations.form.lineQuantity') }}</span>
                        <input
                            :id="lineTestId(index, 'quantity')"
                            v-model="line.quantity"
                            type="text"
                            inputmode="decimal"
                            :disabled="saving"
                            :aria-invalid="lineErrorFor(index, 'quantity') !== null"
                            class="form-field min-h-11 rounded-lg px-3 py-2 text-end tabular-nums focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                            :data-testid="lineTestId(index, 'quantity')"
                        />
                    </label>

                    <button
                        type="button"
                        class="modal-cancel min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        :disabled="saving"
                        :data-testid="lineTestId(index, 'remove')"
                        @click="removeLine(index)"
                    >
                        {{ t('supplierQuotations.form.lineRemove') }}
                    </button>

                    <!-- The server's own sentences, on the controls it named.
                         `basis-full` so a refusal never squeezes the row. -->
                    <p
                        v-if="lineProductError(index) !== null"
                        class="basis-full text-[var(--color-danger)]"
                        :data-testid="lineTestId(index, 'product-error')"
                    >
                        {{ lineProductError(index) }}
                    </p>
                    <p
                        v-if="lineErrorFor(index, 'unit_price') !== null"
                        class="basis-full text-[var(--color-danger)]"
                        :data-testid="lineTestId(index, 'unit-price-error')"
                    >
                        {{ lineErrorFor(index, 'unit_price') }}
                    </p>
                    <p
                        v-if="lineErrorFor(index, 'quantity') !== null"
                        class="basis-full text-[var(--color-danger)]"
                        :data-testid="lineTestId(index, 'quantity-error')"
                    >
                        {{ lineErrorFor(index, 'quantity') }}
                    </p>
                </div>

                <!-- §7.2's "(+ to add more)". -->
                <button
                    type="button"
                    class="modal-cancel min-h-11 self-start rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :disabled="saving"
                    data-testid="supplier-quotation-form-add-line"
                    @click="addLine()"
                >
                    {{ t('supplierQuotations.form.lineAdd') }}
                </button>
            </fieldset>

            <p
                v-else-if="linesState === 'loading'"
                class="text-[var(--color-text-muted)]"
                data-testid="supplier-quotation-form-lines-loading"
            >
                {{ t('supplierQuotations.form.linesLoading') }}
            </p>

            <!-- §6.4's Warning row. The lines could not be read, so the editor
                 is not drawn at all and `items` is left out of the body — an
                 empty editor sent as `[]` would clear them. -->
            <p v-else class="form-warning rounded-lg p-3" role="alert" data-testid="supplier-quotation-form-lines-unavailable">
                {{ t('supplierQuotations.form.linesUnavailable') }}
            </p>

            <!-- §6.4's Warning row. §7.2 lists a total and a currency and this
                 form has neither, so it says why rather than letting the reader
                 conclude the offer has no total. See the header. -->
            <p class="form-warning rounded-lg p-3" data-testid="supplier-quotation-form-total-unavailable">
                {{ t('supplierQuotations.form.totalUnavailable') }}
            </p>

            <!-- §7.2's `pdf_file` row — "Scan or PDF of the offer" (Point 6.5). -->
            <fieldset class="flex flex-col gap-2">
                <legend class="text-card-title">{{ t('supplierQuotations.form.attachments') }}</legend>

                <!-- The route is `/supplier-quotations/{id}/documents`, so there
                     has to be an offer before there can be a file on it. -->
                <p
                    v-if="!isEdit"
                    class="text-[var(--color-text-muted)]"
                    data-testid="supplier-quotation-form-attachments-save-first"
                >
                    {{ t('supplierQuotations.form.attachmentsSaveFirst') }}
                </p>

                <template v-else>
                    <p class="text-[var(--color-text-muted)]" data-testid="supplier-quotation-form-attachments-ceiling">
                        {{ t('supplierQuotations.form.attachmentsCeiling') }}
                    </p>

                    <ul v-if="attachments.length > 0" class="flex flex-col gap-2">
                        <li
                            v-for="(document_, index) in attachments"
                            :key="document_.id"
                            class="line-row flex flex-wrap items-center gap-3 rounded-lg p-3"
                        >
                            <span class="flex-1" :data-testid="`supplier-quotation-attachment-${index}-name`">
                                {{ document_.original_name }}
                            </span>

                            <!-- `SEC-15`: only a clean file is servable, so only
                                 a clean file gets a control. -->
                            <button
                                v-if="document_.scan_status === 'clean'"
                                type="button"
                                class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="downloadingId !== null"
                                :data-testid="`supplier-quotation-attachment-${index}-download`"
                                @click="download(document_)"
                            >
                                {{ t('supplierQuotations.form.attachmentDownload') }}
                            </button>

                            <span
                                v-else-if="document_.scan_status === 'infected'"
                                class="scan-infected rounded-lg px-2 py-1"
                                :data-testid="`supplier-quotation-attachment-${index}-infected`"
                            >
                                {{ t('supplierQuotations.form.scanInfected') }}
                            </span>

                            <span
                                v-else
                                class="scan-pending rounded-lg px-2 py-1"
                                :data-testid="`supplier-quotation-attachment-${index}-pending`"
                            >
                                {{ t('supplierQuotations.form.scanPending') }}
                            </span>
                        </li>
                    </ul>

                    <p
                        v-if="attachmentErrorKey !== null || attachmentError !== null"
                        class="form-alert rounded-lg p-3"
                        role="alert"
                        data-testid="supplier-quotation-form-attachment-error"
                    >
                        {{ attachmentError ?? t(attachmentErrorKey ?? '') }}
                    </p>

                    <!-- §3.6's third grant. Hidden here **and** enforced at the
                         API — `SEC-09` requires the second, not instead of the
                         first. -->
                    <label v-if="canUpload" class="flex flex-col gap-1.5" for="supplier-quotation-form-attachment">
                        <span>{{ uploading ? t('supplierQuotations.form.attachmentUploading') : t('supplierQuotations.form.attachmentChoose') }}</span>
                        <input
                            id="supplier-quotation-form-attachment"
                            type="file"
                            :disabled="uploading || saving"
                            class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                            data-testid="supplier-quotation-form-attachment-file"
                            @change="upload($event)"
                        />
                    </label>
                </template>
            </fieldset>

            <!-- §5.2's unsaved-change warning. Inside the dialog, because a
                 native `confirm()` is neither translatable nor RTL-aware. -->
            <div
                v-if="confirmingDiscard"
                class="form-warning rounded-lg p-3"
                role="alertdialog"
                aria-labelledby="supplier-quotation-form-unsaved-title"
                data-testid="supplier-quotation-form-unsaved"
            >
                <p id="supplier-quotation-form-unsaved-title">{{ t('supplierQuotations.form.unsavedChanges') }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="modal-cancel min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        data-testid="supplier-quotation-form-keep-editing"
                        @click="confirmingDiscard = false"
                    >
                        {{ t('supplierQuotations.form.keepEditing') }}
                    </button>
                    <button
                        type="button"
                        class="modal-discard min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        data-testid="supplier-quotation-form-discard"
                        @click="discard()"
                    >
                        {{ t('supplierQuotations.form.discard') }}
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-2">
                <button
                    type="button"
                    class="modal-cancel min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :disabled="saving"
                    data-testid="supplier-quotation-form-cancel"
                    @click="requestClose()"
                >
                    {{ t('action.cancel') }}
                </button>

                <button
                    type="submit"
                    class="modal-save min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="saving"
                    data-testid="supplier-quotation-form-save"
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
   sentence beside it. */
.form-warning {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-warning);
    color: var(--color-text);
}

.line-row {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border);
}

/* §6.4: never colour alone — each of these renders a word beside it. */
.scan-pending {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-warning);
    color: var(--color-text);
}

.scan-infected {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-danger);
    color: var(--color-danger);
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
