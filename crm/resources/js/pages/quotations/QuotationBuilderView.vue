<script setup lang="ts">
/**
 * Module 7, Points 6.6 and 6.7 — the builder: create (`/quotations/new?deal=`)
 * and edit (`/quotations/:id/edit`, a Draft the caller may edit).
 *
 * The form holds what `SaveQuotationRequest` accepts and nothing it computes:
 * no price, no total, no tax amount is shown here, because the server owns
 * every figure (`D-67`, `§5.6`) and the quotation's own page (Point 6.5) is
 * the confirmation once it exists (Step 6 Q3). Money travels as the strings
 * the person typed (`DB-07`), never through `Number`.
 *
 * ── Suppliers ──────────────────────────────────────────────────────────────
 * §6.2: "1 → 10 suppliers via (+)". A block is one supplier quotation; its
 * lines are what `GET /supplier-quotations/{id}` lists, and a line is sent
 * only when a quantity was typed for it, as `supplier_quotation_item_id`
 * (Point 3.3). The picker lists the deal's own offers first, then every other
 * (`listSupplierQuotations({dealId})`, then any) — an offer may be standalone
 * (`D-51`). The button beside the picker opens Module 6's own form and adds
 * what it saved as a block (owner's ruling 2026-09-11, the debt row Point 3.3
 * parked).
 *
 * ── Refusals and warnings ───────────────────────────────────────────────────
 * `ApiExceptionRenderer::quotationNotPriceable()` names the line for both of
 * `QuotationNotPriceable`'s codes. `supplier_price_missing` lands on that
 * line — the save is blocked (`§5.6`). `fx_rate_missing` is a currency
 * problem, not a line's, so it is shown at the form. A 422 `validation_failed`
 * lands on the field it names, Module 6's `applyServerErrors` pattern.
 *
 * `quantity_exceeds_recorded` rides the **201**: the draft exists. `§5.6`
 * wants it red on the line and `Design System §7.2` says "without blocking",
 * so on a 201 that carries warnings the form stays, with the red line and a
 * link to the saved draft — and **stays editable** (F-03, owner 2026-09-16:
 * the earlier read-only freeze was itself a block). The next save is a
 * `PATCH` on the draft just created, with its token; a second `POST` under
 * the same key would be a 409. A clean 201 goes straight to the detail. A
 * `GET` never repeats this warning (Point 4.5 reads `supplier_price_changed`
 * only), so navigating away would lose it. Edit-and-approve keeps the freeze:
 * the quotation is `approved` and nothing on it may change any more.
 *
 * ── Idempotency ────────────────────────────────────────────────────────────
 * `OpenAPI §9.1`: one `Idempotency-Key` per form open. A retry after a
 * failure replays the same key; a fresh form mints a fresh one.
 *
 * ── Edit (6.7) ─────────────────────────────────────────────────────────────
 * The form is loaded from `GET /quotations/{id}`; `PATCH` carries the
 * detail's `etag` as `If-Match` (`API-12`) and both lists always — an edit
 * replaces every editable field (Point 3.6). A 409 `concurrency_conflict` is
 * a banner naming the newer version's total with a reload, never a retry; a
 * 422 `quotation_not_draft` sends the person back to the quotation's page.
 * Leaving with unsaved changes asks first (`Design System §5.2`).
 *
 * A quotation line names its `supplier_quotation_item_id` and nothing about
 * the offer it came from (`quotation_items` has no `supplier_quotation_id`),
 * so existing lines are edited as the flat list Point 6.5 shows — line
 * number, product name (F-16 · 1.1), cost, quantity, margin, remove — and new
 * lines still come through a supplier block.
 *
 * ── Terms (D-103) ──────────────────────────────────────────────────────────
 * A list built like the additional items, up to 15, sent as `terms` in row
 * order. A new quotation opens on three ready terms keyed `payment_terms`,
 * `warranty` and `delivery_terms`; the key, not the row, carries the default
 * name, the chips and the combobox, so they follow a renamed or moved term. An
 * added term has no key. An empty name or text is sent as null, and an empty
 * ready term is kept (the owner, 2026-10-08).
 *
 * ── Delivery terms (F-32) ──────────────────────────────────────────────────
 * The delivery-keyed term's text, with suggestions like the other ready terms
 * (`§6.2`, `Design System §6.3`). When the `delivery_terms` managed list has entries it is an
 * editable combobox over them: focus opens the list, typing narrows it, a pick
 * fills the box, and text that matches nothing is kept and saved as typed. The
 * stored value is the text either way, so the API and the PDF never learn there
 * is a list. With no entries, or the list refused, it is the text area it was.
 *
 * Stated ceilings, all Module 6's: suppliers, catalog items and supplier
 * quotations are each read as one page of 100. The delivery terms are one page
 * of 25, as the catalog's lists are.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { onBeforeRouteLeave, RouterLink, useRoute, useRouter } from 'vue-router';
import { ApiError, collection, type Page } from '@/api';
import { entryLabel, listCurrencies, listEntries, type Currency, type ListEntry } from '@/services/admin';
import SearchCombobox from '@/components/SearchCombobox.vue';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import SupplierQuotationFormModal from '@/pages/supplier-quotations/SupplierQuotationFormModal.vue';
import { listCatalogItems, type CatalogItem } from '@/services/catalog';
import { readCustomer, type Customer } from '@/services/customers';
import { readDeal, type Deal } from '@/services/deals';
import {
    createQuotation,
    editAndApproveQuotation,
    listTermSuggestions,
    readQuotation,
    updateQuotation,
    type QuotationDetail,
    type QuotationDraft,
    type QuotationRead,
    type QuotationWarning,
    type TermField,
} from '@/services/quotations';
import {
    listSupplierQuotations,
    readSupplierQuotation,
    type SupplierQuotation,
    type SupplierQuotationDetail,
} from '@/services/supplier-quotations';
import { listSuppliers, type Supplier } from '@/services/suppliers';
import { displayDecimals } from '@/domain/displayDecimals';
import { useAuth } from '@/stores/auth';

const route = useRoute();
const router = useRouter();
const { t, locale } = useI18n();
const { hasPermission } = useAuth();

/** §6.2's cap. */
const MAX_SUPPLIERS = 10;

const HEADER_FIELDS = ['currency', 'default_margin', 'discount_percent', 'tax_percent', 'quotation_date', 'valid_until'] as const;

type HeaderField = (typeof HEADER_FIELDS)[number];

interface Block {
    supplierQuotationId: string;
    detail: SupplierQuotationDetail | null;
    /** Per line of `detail.items`, what was typed. Empty means "not on this quotation". */
    quantities: string[];
    margins: string[];
}

/** An existing line (edit only): its supplier item, what was typed, and the cost the detail carried (Q7). */
interface ExistingLine {
    supplier_quotation_item_id: string;
    line_no: number;
    product_name: string | null;
    quantity: string;
    margin: string;
    unit_cost: string | null;
}

/** Module 8 · 3.2: the approver's route — the same form, saved through 1.3. */
const approving = computed(() => route.name === 'quotation-edit-and-approve');
const editing = computed(() => route.name === 'quotation-edit' || approving.value);
const quotationId = computed(() => String(route.params.id ?? ''));
/** From `?deal=` on a create; from the detail on an edit. */
const dealId = ref(String(route.query.deal ?? ''));

const deal = ref<Deal | null>(null);
const customer = ref<Customer | null>(null);
const suppliers = ref<Supplier[]>([]);
const catalog = ref<CatalogItem[]>([]);

/** D-80's list; empty when it could not be read, and the code is typed instead. */
const currencies = ref<Currency[]>([]);
const offers = ref<SupplierQuotation[]>([]);

const loading = ref(true);
const failed = ref(false);
const denied = ref(false);
const missing = ref(false);

const header = ref<Record<HeaderField, string>>({
    currency: '', default_margin: '', discount_percent: '0', tax_percent: '',
    quotation_date: '', valid_until: '',
});
/** The three ready terms' keys; Point 6.8's recent terms are read per key, and a chip copies one into that term's text. */
const TERM_FIELDS: TermField[] = ['payment_terms', 'warranty', 'delivery_terms'];
/** `D-103`'s cap. */
const MAX_TERMS = 15;
/** `D-103`: the terms as typed, in their printed order; a new quotation opens on the three ready ones. */
const terms = ref<Array<{ key: TermField | null; title: string; body: string }>>(TERM_FIELDS.map((key) => ({ key, title: '', body: '' })));
const suggestions = ref<Record<TermField, string[]>>({ payment_terms: [], warranty: [], delivery_terms: [] });

/** F-32 — the registered delivery terms (`DB-05`); empty when none exist or the list could not be read. */
const termEntries = ref<ListEntry[]>([]);

interface TermOption {
    id: string;
    name: string;
}

const termOptions = computed<TermOption[]>(() => termEntries.value.map((entry) => ({ id: entry.code, name: entryLabel(entry, locale.value) })));

/**
 * The list is already here, so the combobox's "search" is a filter over it: a
 * substring of the label, case ignored.
 *
 * ponytail: no Arabic letter-variant folding — `SearchService` folds on the
 * server only, so «اداره» will not find «إدارة». Fold here when a list outgrows
 * its first page and the search moves to the server.
 */
function searchTerms({ q }: { q: string | null }): Promise<Page<TermOption>> {
    const needle = (q ?? '').toLocaleLowerCase();
    const found = termOptions.value.filter((term) => term.name.toLocaleLowerCase().includes(needle));

    return Promise.resolve(collection<TermOption>({ data: found, requestId: null, meta: {} }));
}

/** Only the delivery term has a list, and only while the list has something in it. */
function offersList(key: TermField | null): boolean {
    return key === 'delivery_terms' && termOptions.value.length > 0;
}

/**
 * A one-line box strips line breaks and glues the words ("Ex works⏎before noon"
 * → "Ex worksbefore noon"), so an old term typed on several lines shows each break
 * as a space — the PDF template sets no line-break style, so it prints one too.
 * Only what the box shows: the stored text is untouched until somebody types.
 */
function oneLine(text: string): string {
    return text.replace(/\s*[\r\n]+\s*/g, ' ');
}

/** The list is local, so only "no match" can ever show: its sentence stands in for the states a server-searched picker adds. */
const TERM_NO_MATCH = 'quotations.builder.deliveryTermNoMatch';
const TERM_KEYS = { more: TERM_NO_MATCH, forbidden: TERM_NO_MATCH, failed: TERM_NO_MATCH, empty: TERM_NO_MATCH, noMatch: TERM_NO_MATCH, retry: 'state.retry' };
const existing = ref<ExistingLine[]>([]);
const blocks = ref<Block[]>([]);
const items = ref<Array<{ description: string; amount: string }>>([]);

/** `OpenAPI §9.2`'s token, as the detail carried it; what the next `PATCH` sends. */
const etag = ref('');
const quotationCode = ref('');
/** The newer version a 409 revealed — the banner names its total until the person reloads. */
const conflict = ref<QuotationDetail | null>(null);
/** What the form was opened with. The dirty check is against this, not against blank. */
const opened = ref('');

/**
 * `OpenAPI §9.1`: one key per command. A network failure or a 5xx keeps it — the
 * server released it and a resend is the same command. A 4xx is the key's
 * final answer on the server, so the next save is a new command with a new key;
 * reusing it with a corrected body is `409 idempotency_conflict` for the life
 * of the page (seen 2026-09-15: one 422 on `currency`, then eight 409s).
 */
let idempotencyKey = crypto.randomUUID();

const saving = ref(false);
const formError = ref('');
/** The server's sentence per request field — `discount_percent`, `lines.1.quantity`, `additional_items.0.amount`. */
const fieldErrors = ref<Map<string, string>>(new Map());
/** Which input each sent `lines[i]` came from — `existing-3` or `line-1-2` — so a server index maps back. */
let sent: string[] = [];
/** The save that carried warnings: the draft is saved, the link is on, the form stays (F-03). */
const saved = ref<{ id: string; code: string } | null>(null);
/** A create that answered with warnings: from here on the form saves by `PATCH` on this id. */
const createdId = ref<string | null>(null);
/** After a warned save nothing may change on an approved quotation; a draft goes on being edited. */
const frozen = computed(() => saved.value !== null && approving.value);
const lineWarnings = ref<Map<string, string>>(new Map());

const offerFormOpen = ref(false);

const canAddSupplier = computed(() => blocks.value.length < MAX_SUPPLIERS);
const canCreateOffer = computed(() => hasPermission('supplier_quotation.create'));

function supplierName(id: string): string {
    return suppliers.value.find((supplier) => supplier.id === id)?.name ?? id;
}

/** §7.3 keeps a service's label in `service_type` and a product's in `name`; the id is the last resort. */
function catalogLabel(id: string): string {
    const item = catalog.value.find((candidate) => candidate.id === id);

    return item?.name ?? item?.service_type ?? id;
}

/** `DB-08`: a moment in the reader's locale — 6.5's helper. */
function onMoment(value: string): string {
    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-GB');
}

function offerLabel(offer: SupplierQuotation): string {
    return `${offer.code} — ${supplierName(offer.supplier_id)}`;
}

/** The request's index of an input's line, or null when it was not sent. */
function sentIndex(key: string): number | null {
    const index = sent.indexOf(key);

    return index === -1 ? null : index;
}

function lineError(key: string): string | null {
    const index = sentIndex(key);

    if (index === null) {
        return null;
    }

    return fieldErrors.value.get(`lines.${index}.quantity`)
        ?? fieldErrors.value.get(`lines.${index}.supplier_quotation_item_id`)
        ?? fieldErrors.value.get(`lines.${index}.margin_percent`)
        ?? null;
}

function lineWarning(key: string): string | null {
    const index = sentIndex(key);

    return index === null ? null : (lineWarnings.value.get(String(index)) ?? null);
}

/** The form as typed — the dirty check compares this against `opened`. */
function snapshot(): string {
    return JSON.stringify({
        header: header.value,
        terms: terms.value,
        existing: existing.value,
        blocks: blocks.value.map((block) => [block.supplierQuotationId, block.quantities, block.margins]),
        items: items.value,
    });
}

/** Nothing to lose before the form opened (a refused load, a redirect) or since it last saved. */
const dirty = computed(() => opened.value !== '' && snapshot() !== opened.value);

/** Fill the form from the detail (edit): header, lines, items, and the token the next write needs. */
function applyDetail(quotation: QuotationDetail): void {
    header.value = {
        currency: quotation.currency,
        default_margin: quotation.default_margin ?? '',
        discount_percent: quotation.discount_percent,
        tax_percent: quotation.tax_percent ?? '',
        quotation_date: quotation.quotation_date ?? '',
        valid_until: quotation.valid_until ?? '',
    };
    terms.value = quotation.terms.map((term) => ({ key: term.key, title: term.title ?? '', body: term.body ?? '' }));
    existing.value = quotation.items.map((line) => ({
        supplier_quotation_item_id: line.supplier_quotation_item_id,
        line_no: line.line_no,
        product_name: line.product_name,
        quantity: line.quantity,
        margin: line.margin_percent ?? '',
        unit_cost: line.unit_cost ?? null,
    }));
    blocks.value = [];
    items.value = quotation.additional_items.map((item) => ({ description: item.description, amount: item.amount }));
    etag.value = quotation.etag;
    quotationCode.value = quotation.code;
    dealId.value = quotation.deal_id;
}

function itemError(index: number): string | null {
    return fieldErrors.value.get(`additional_items.${index}.description`)
        ?? fieldErrors.value.get(`additional_items.${index}.amount`)
        ?? null;
}

/** `OpenAPI §5.1`: a 404 says the record could not be opened and never which of the two reasons applies. */
function refused(error: unknown): void {
    const status = error instanceof ApiError ? error.status : 0;

    missing.value = status === 404;
    denied.value = status === 403;
    failed.value = !missing.value && !denied.value;
    loading.value = false;
}

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    denied.value = false;

    if (editing.value) {
        try {
            const { quotation } = await readQuotation(quotationId.value);

            // §3.5's `edit` is "(Draft)", and edit-and-approve is 1.3's `pending`
            // edge: anything else is read on its own page.
            if (quotation.status !== (approving.value ? 'pending' : 'draft')) {
                await router.replace({ name: 'quotation-detail', params: { id: quotation.id } });

                return;
            }

            applyDetail(quotation);
        } catch (error) {
            refused(error);

            return;
        }
    }

    missing.value = dealId.value === '';

    if (missing.value) {
        loading.value = false;

        return;
    }

    try {
        deal.value = await readDeal(dealId.value);
    } catch (error) {
        refused(error);

        return;
    }

    // Best-effort, exactly as the detail's own name lookups are: a refused
    // lookup leaves an identifier on screen, not an error page over a form.
    await Promise.all([
        readCustomer(deal.value.customer_id).then((found) => { customer.value = found; }, () => {}),
        listSuppliers({ perPage: 100 }).then((page) => { suppliers.value = page.items; }, () => {}),
        // D-80: the builder's roles hold `currency.view`. Refused or failed,
        // the list stays empty and the code is typed, as before F-02.
        listCurrencies().then((list) => { currencies.value = list; }, () => {}),
        listCatalogItems({ perPage: 100, isActive: true }).then((page) => { catalog.value = page.items; }, () => {}),
        loadOffers(),
        ...TERM_FIELDS.map((field) => listTermSuggestions(field).then((terms) => { suggestions.value[field] = terms; }, () => {})),
        // F-32: best-effort too — without the list the delivery field is the text area it was.
        listEntries('delivery_terms', 1).then((page) => { termEntries.value = page.items; }, () => {}),
    ]);

    opened.value = snapshot();
    loading.value = false;
}

/** After a 409: the newer version replaces what was typed; the token moves with it. */
async function reload(): Promise<void> {
    const { quotation } = await readQuotation(quotationId.value);

    applyDetail(quotation);
    conflict.value = null;
    opened.value = snapshot();
}

/** The deal's own offers first, then any other — one list, no offer twice. */
async function loadOffers(): Promise<void> {
    try {
        const [own, all] = await Promise.all([
            listSupplierQuotations({ dealId: dealId.value, perPage: 100 }),
            listSupplierQuotations({ perPage: 100 }),
        ]);
        const seen = new Set(own.items.map((offer) => offer.id));

        offers.value = [...own.items, ...all.items.filter((offer) => !seen.has(offer.id))];
    } catch {
        offers.value = [];
    }
}

function addBlock(supplierQuotationId = ''): void {
    if (!canAddSupplier.value) {
        return;
    }

    blocks.value.push({ supplierQuotationId, detail: null, quantities: [], margins: [] });

    if (supplierQuotationId !== '') {
        void pickOffer(blocks.value.length - 1);
    }
}

function removeBlock(index: number): void {
    blocks.value.splice(index, 1);
}

async function pickOffer(index: number): Promise<void> {
    const block = blocks.value[index];

    if (block === undefined) {
        return;
    }

    block.detail = null;
    block.quantities = [];
    block.margins = [];

    if (block.supplierQuotationId === '') {
        return;
    }

    try {
        const detail = await readSupplierQuotation(block.supplierQuotationId);

        block.detail = detail;
        block.quantities = detail.items.map(() => '');
        block.margins = detail.items.map(() => '');
    } catch (error) {
        formError.value = error instanceof ApiError ? error.message : t('quotations.builder.unreachable');
    }
}

/** Module 6's form saved an offer: it becomes the next block, already picked. */
function onOfferSaved(offer: SupplierQuotation): void {
    offerFormOpen.value = false;
    offers.value = [offer, ...offers.value.filter((candidate) => candidate.id !== offer.id)];
    addBlock(offer.id);
}

function addItem(): void {
    items.value.push({ description: '', amount: '' });
}

function removeItem(index: number): void {
    items.value.splice(index, 1);
}

function termError(index: number): string | null {
    return fieldErrors.value.get(`terms.${index}.title`)
        ?? fieldErrors.value.get(`terms.${index}.body`)
        ?? fieldErrors.value.get(`terms.${index}.key`)
        ?? null;
}

function orNull(value: string): string | null {
    return value.trim() === '' ? null : value;
}

function removeExisting(index: number): void {
    existing.value.splice(index, 1);
}

function draft(): QuotationDraft {
    const lines: QuotationDraft['lines'] = [];

    sent = [];

    existing.value.forEach((line, i) => {
        lines.push({
            supplier_quotation_item_id: line.supplier_quotation_item_id,
            quantity: line.quantity,
            ...(line.margin.trim() === '' ? {} : { margin_percent: line.margin }),
        });
        sent.push(`existing-${i}`);
    });

    blocks.value.forEach((block, b) => {
        (block.detail?.items ?? []).forEach((item, l) => {
            const quantity = block.quantities[l] ?? '';

            if (quantity.trim() === '') {
                return;
            }

            const margin = block.margins[l] ?? '';

            lines.push({
                supplier_quotation_item_id: item.id,
                quantity,
                ...(margin.trim() === '' ? {} : { margin_percent: margin }),
            });
            sent.push(`line-${b}-${l}`);
        });
    });

    return {
        currency: header.value.currency.trim().toUpperCase(),
        default_margin: header.value.default_margin,
        discount_percent: header.value.discount_percent,
        tax_percent: orNull(header.value.tax_percent),
        quotation_date: orNull(header.value.quotation_date),
        valid_until: orNull(header.value.valid_until),
        // The owner, 2026-10-08: an empty ready term is kept, its body null.
        terms: terms.value.map((term) => ({ key: term.key, title: orNull(term.title), body: orNull(term.body) })),
        lines,
        additional_items: items.value.map((item) => ({ description: item.description, amount: item.amount })),
    };
}

/** `OpenAPI §5` — `details[]` names the field; `fx_rate_missing` is the form's, whichever line it cites. */
function applyServerErrors(error: unknown): void {
    fieldErrors.value = new Map();

    if (!(error instanceof ApiError)) {
        formError.value = t('quotations.builder.unreachable');

        return;
    }

    for (const detail of error.details) {
        if (detail.code === 'fx_rate_missing' && typeof detail.message === 'string') {
            formError.value = detail.message;

            continue;
        }

        if (typeof detail.field === 'string' && typeof detail.message === 'string') {
            fieldErrors.value.set(detail.field, detail.message);
        }
    }

    if (formError.value === '' && fieldErrors.value.size === 0) {
        formError.value = error.message;
    }
}

function onWarnings(warnings: QuotationWarning[]): void {
    lineWarnings.value = new Map();

    for (const warning of warnings) {
        const match = /^lines\.(\d+)\./.exec(warning.field);

        if (match !== null) {
            lineWarnings.value.set(match[1] as string, warning.message);
        }
    }
}

async function save(): Promise<void> {
    if (saving.value || deal.value === null) {
        return;
    }

    saving.value = true;
    formError.value = '';
    fieldErrors.value = new Map();

    try {
        const current = deal.value;
        const result = approving.value
            ? await editAndApproveQuotation(quotationId.value, etag.value, draft())
            : editing.value || createdId.value !== null
                ? await updateQuotation(createdId.value ?? quotationId.value, etag.value, draft())
                : await createQuotation({ ...draft(), deal_id: current.id, customer_id: current.customer_id }, idempotencyKey);

        saved.value = { id: result.quotation.id, code: result.quotation.code };
        opened.value = snapshot();

        if (result.warnings.length === 0) {
            await router.push({ name: 'quotation-detail', params: { id: result.quotation.id } });

            return;
        }

        onWarnings(result.warnings);

        if (editing.value) {
            etag.value = (result as QuotationRead).quotation.etag;
        } else {
            // A 201 carries no etag (`QuotationPayload::of()`); the next save is
            // a PATCH on the draft, so its token is read once, here.
            createdId.value = result.quotation.id;
            etag.value = (await readQuotation(result.quotation.id)).quotation.etag;
        }
    } catch (error) {
        if (error instanceof ApiError && error.status < 500) {
            idempotencyKey = crypto.randomUUID();
        }

        if (error instanceof ApiError && error.status === 409 && error.code === 'concurrency_conflict') {
            // `API-12`: the stored token moved. Show whose, never overwrite.
            conflict.value = (await readQuotation(quotationId.value)).quotation;
        } else if (error instanceof ApiError && error.is('quotation_not_draft')) {
            await router.push({ name: 'quotation-detail', params: { id: quotationId.value } });
        } else {
            applyServerErrors(error);
        }
    } finally {
        saving.value = false;
    }
}

/** `Design System §5.2`: leaving a builder with unsaved changes asks first. */
onBeforeRouteLeave(() => !dirty.value || window.confirm(t('quotations.builder.unsaved')));

function onBeforeUnload(event: BeforeUnloadEvent): void {
    if (dirty.value) {
        event.preventDefault();
    }
}

window.addEventListener('beforeunload', onBeforeUnload);
onBeforeUnmount(() => window.removeEventListener('beforeunload', onBeforeUnload));

function fieldId(field: string): string {
    return `quotation-builder-${field}`;
}

onMounted(load);
</script>

<template>
    <section class="flex flex-col gap-4">
        <LoadingState v-if="loading" label-key="quotations.builder.loading" />
        <PermissionDeniedState v-else-if="denied" />
        <ErrorState v-else-if="failed" @retry="load" />

        <!-- One state, one sentence: never which of the two reasons applies. -->
        <p v-else-if="missing" class="form-alert rounded-lg p-3" role="alert" data-testid="quotation-builder-missing">
            {{ t('quotations.builder.missing') }}
        </p>

        <form v-else-if="deal !== null" class="flex flex-col gap-4" novalidate data-testid="quotation-builder-form" @submit.prevent="save">
            <header class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-page-title">{{ editing ? t('quotations.builder.editTitle', { code: quotationCode }) : t('quotations.builder.title') }}</h1>
                <p class="flex flex-wrap items-center gap-2">
                    <RouterLink :to="{ name: 'deal-detail', params: { id: deal.id } }" class="row-link" data-testid="quotation-builder-deal">
                        {{ deal.code }}
                    </RouterLink>
                    <span data-testid="quotation-builder-customer">{{ customer?.name ?? deal.customer_id }}</span>
                </p>
            </header>

            <p v-if="formError !== ''" class="form-alert rounded-lg p-3" role="alert" data-testid="quotation-builder-form-error">
                {{ formError }}
            </p>

            <!-- `API-12`: the newer version's figures and a reload; nothing is merged or retried. -->
            <div v-if="conflict !== null" class="form-alert flex flex-wrap items-center justify-between gap-3 rounded-lg p-3" role="alert" data-testid="quotation-builder-conflict">
                <span>{{ t('quotations.builder.conflict', { total: displayDecimals(conflict.final_total), currency: conflict.currency, at: onMoment(conflict.updated_at) }) }}</span>
                <button type="button" class="row-action min-h-11 rounded-lg px-3" data-testid="quotation-builder-conflict-reload" @click="reload">
                    {{ t('quotations.detail.reload') }}
                </button>
            </div>

            <!-- Q3: the draft is saved; the quotation's own page is the confirmation. -->
            <p v-if="saved !== null" class="form-saved rounded-lg p-3" role="status" data-testid="quotation-builder-saved">
                {{ t('quotations.builder.saved', { code: saved.code }) }}
                <RouterLink :to="{ name: 'quotation-detail', params: { id: saved.id } }" class="row-link" data-testid="quotation-builder-saved-link">
                    {{ t('quotations.builder.open') }}
                </RouterLink>
            </p>

            <fieldset class="contents" :disabled="saving || frozen">
                <!-- `Design System §7.2`: margin, discount and tax in their own group; §4.3 grid. -->
                <div class="detail-grid rounded-xl p-4">
                    <label class="flex flex-col gap-1.5" :for="fieldId('currency')">
                        <span>{{ t('quotations.builder.currency') }} *</span>
                        <!-- The value is the ISO code either way: `QuotationDraft.currency`
                             takes a code, and the edit hydrates one. -->
                        <select
                            v-if="currencies.length > 0"
                            :id="fieldId('currency')"
                            v-model="header.currency"
                            required
                            :aria-invalid="fieldErrors.has('currency')"
                            class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                            :data-testid="fieldId('currency')"
                        >
                            <option value="">{{ t('quotations.builder.currencyNone') }}</option>
                            <option v-for="currency in currencies" :key="currency.code" :value="currency.code">{{ currency.code }}</option>
                        </select>
                        <input
                            v-else
                            :id="fieldId('currency')"
                            v-model="header.currency"
                            type="text"
                            required
                            maxlength="3"
                            autocomplete="off"
                            :placeholder="t('quotations.filter.currencyPlaceholder')"
                            :aria-invalid="fieldErrors.has('currency')"
                            class="form-field min-h-11 rounded-lg px-3 py-2 uppercase focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                            :data-testid="fieldId('currency')"
                        />
                        <span v-if="fieldErrors.has('currency')" class="text-[var(--color-danger)]" :data-testid="fieldId('currency-error')">
                            {{ fieldErrors.get('currency') }}
                        </span>
                    </label>

                    <label v-for="field in (['default_margin', 'discount_percent', 'tax_percent'] as const)" :key="field" class="flex flex-col gap-1.5" :for="fieldId(field)">
                        <span>{{ t(`quotations.builder.${field}`) }}<template v-if="field !== 'tax_percent'"> *</template></span>
                        <input
                            :id="fieldId(field)"
                            v-model="header[field]"
                            type="text"
                            inputmode="decimal"
                            autocomplete="off"
                            :required="field !== 'tax_percent'"
                            :placeholder="field === 'tax_percent' ? t('quotations.builder.taxExempt') : ''"
                            :aria-invalid="fieldErrors.has(field)"
                            class="form-field min-h-11 rounded-lg px-3 py-2 tabular-nums focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                            :data-testid="fieldId(field)"
                        />
                        <span v-if="fieldErrors.has(field)" class="text-[var(--color-danger)]" :data-testid="fieldId(`${field}-error`)">
                            {{ fieldErrors.get(field) }}
                        </span>
                    </label>

                    <label v-for="field in (['quotation_date', 'valid_until'] as const)" :key="field" class="flex flex-col gap-1.5" :for="fieldId(field)">
                        <span>{{ t(`quotations.builder.${field}`) }}</span>
                        <input
                            :id="fieldId(field)"
                            v-model="header[field]"
                            type="date"
                            :aria-invalid="fieldErrors.has(field)"
                            class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                            :data-testid="fieldId(field)"
                        />
                        <span v-if="fieldErrors.has(field)" class="text-[var(--color-danger)]" :data-testid="fieldId(`${field}-error`)">
                            {{ fieldErrors.get(field) }}
                        </span>
                    </label>
                </div>

                <!-- Edit: the lines the draft already has, as 6.5 lists them — no offer is named on a line. -->
                <section v-if="editing" class="flex flex-col gap-3">
                    <h2 class="text-card-title">{{ t('quotations.detail.lines') }}</h2>
                    <p v-if="existing.length === 0" class="text-[var(--color-text-muted)]">{{ t('quotations.builder.noExisting') }}</p>
                    <div v-for="(line, i) in existing" :key="line.supplier_quotation_item_id + i" class="line-grid line-row rounded-lg p-3">
                        <p class="flex flex-col gap-1">
                            <span>{{ t('quotations.builder.lineNo', { no: line.line_no }) }}</span>
                            <span v-if="line.product_name" class="font-medium" :data-testid="fieldId(`existing-${i}-product`)">{{ line.product_name }}</span>
                            <span v-if="line.unit_cost !== null" class="text-[var(--color-text-muted)] tabular-nums" :data-testid="fieldId(`existing-${i}-cost`)">
                                {{ t('quotations.detail.unitCost') }} {{ displayDecimals(line.unit_cost) }}
                            </span>
                        </p>
                        <label class="flex flex-col gap-1.5" :for="fieldId(`existing-${i}-quantity`)">
                            <span>{{ t('quotations.detail.quantity') }}</span>
                            <input
                                :id="fieldId(`existing-${i}-quantity`)"
                                v-model="line.quantity"
                                type="text"
                                inputmode="decimal"
                                autocomplete="off"
                                :aria-invalid="lineError(`existing-${i}`) !== null"
                                class="form-field min-h-11 rounded-lg px-3 py-2 tabular-nums focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                :data-testid="fieldId(`existing-${i}-quantity`)"
                            />
                        </label>
                        <label class="flex flex-col gap-1.5" :for="fieldId(`existing-${i}-margin_percent`)">
                            <span>{{ t('quotations.builder.lineMargin') }}</span>
                            <input
                                :id="fieldId(`existing-${i}-margin_percent`)"
                                v-model="line.margin"
                                type="text"
                                dir="ltr"
                                autocomplete="off"
                                class="form-field min-h-11 rounded-lg px-3 py-2 tabular-nums focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                :data-testid="fieldId(`existing-${i}-margin_percent`)"
                            />
                        </label>
                        <button type="button" class="row-action min-h-11 self-end rounded-lg px-3 disabled:cursor-not-allowed disabled:opacity-60" :data-testid="fieldId(`existing-${i}-remove`)" @click="removeExisting(i)">
                            {{ t('quotations.builder.removeItem') }}
                        </button>
                        <p v-if="lineError(`existing-${i}`) !== null" class="line-note text-[var(--color-danger)]" role="alert" :data-testid="fieldId(`existing-${i}-error`)">
                            {{ lineError(`existing-${i}`) }}
                        </p>
                        <p v-else-if="lineWarning(`existing-${i}`) !== null" class="line-note warning-line rounded-lg p-2" :data-testid="fieldId(`existing-${i}-warning`)">
                            {{ lineWarning(`existing-${i}`) }}
                        </p>
                    </div>
                </section>

                <!-- Suppliers: one block per supplier quotation, up to ten (§6.2). -->
                <section class="flex flex-col gap-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-card-title">{{ t('quotations.builder.suppliers') }}</h2>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-if="canCreateOffer"
                                type="button"
                                class="row-action min-h-11 rounded-lg px-3 disabled:cursor-not-allowed disabled:opacity-60"
                                data-testid="quotation-builder-new-supplier-quotation"
                                @click="offerFormOpen = true"
                            >
                                {{ t('quotations.builder.newSupplierQuotation') }}
                            </button>
                            <button
                                type="button"
                                class="row-action min-h-11 rounded-lg px-3 disabled:cursor-not-allowed disabled:opacity-60"
                                :disabled="!canAddSupplier"
                                :title="canAddSupplier ? undefined : t('quotations.builder.supplierCap', { max: MAX_SUPPLIERS })"
                                data-testid="quotation-builder-add-supplier"
                                @click="addBlock()"
                            >
                                + {{ t('quotations.builder.addSupplier') }}
                            </button>
                        </div>
                    </div>

                    <p v-if="blocks.length === 0" class="text-[var(--color-text-muted)]">{{ t('quotations.builder.noSuppliers') }}</p>

                    <div v-for="(block, b) in blocks" :key="b" class="line-row flex flex-col gap-3 rounded-xl p-4" :data-testid="`quotation-builder-supplier-${b}`">
                        <div class="flex flex-wrap items-end gap-2">
                            <label class="flex min-w-[16rem] flex-1 flex-col gap-1.5" :for="fieldId(`supplier-${b}-pick`)">
                                <span>{{ t('quotations.builder.supplierQuotation') }}</span>
                                <select
                                    :id="fieldId(`supplier-${b}-pick`)"
                                    v-model="block.supplierQuotationId"
                                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                    :data-testid="fieldId(`supplier-${b}-pick`)"
                                    @change="pickOffer(b)"
                                >
                                    <option value=""></option>
                                    <option v-for="offer in offers" :key="offer.id" :value="offer.id">{{ offerLabel(offer) }}</option>
                                </select>
                            </label>
                            <button type="button" class="row-action min-h-11 rounded-lg px-3 disabled:cursor-not-allowed disabled:opacity-60" :data-testid="fieldId(`supplier-${b}-remove`)" @click="removeBlock(b)">
                                {{ t('quotations.builder.removeSupplier') }}
                            </button>
                        </div>

                        <template v-if="block.detail !== null">
                            <p v-if="block.detail.items.length === 0" class="text-[var(--color-text-muted)]">{{ t('quotations.builder.noLines') }}</p>

                            <div v-for="(item, l) in block.detail.items" :key="item.id" class="line-grid rounded-lg p-3" :data-testid="fieldId(`line-${b}-${l}`)">
                                <p class="flex flex-col gap-1">
                                    <span :data-testid="fieldId(`line-${b}-${l}-label`)">{{ catalogLabel(item.catalog_item_id) }}</span>
                                    <!-- The supplier's own figures — internal cost, never a customer price (§7.2).
                                         The quantity is what is left of the offer, not what it recorded (D-81). -->
                                    <span class="text-[var(--color-text-muted)] tabular-nums" :data-testid="fieldId(`line-${b}-${l}-recorded`)">
                                        {{ t('quotations.builder.recorded', { price: displayDecimals(item.unit_price), quantity: displayDecimals(item.available_quantity) }) }}
                                    </span>
                                </p>
                                <label class="flex flex-col gap-1.5" :for="fieldId(`line-${b}-${l}-quantity`)">
                                    <span>{{ t('quotations.detail.quantity') }}</span>
                                    <input
                                        :id="fieldId(`line-${b}-${l}-quantity`)"
                                        v-model="block.quantities[l]"
                                        type="text"
                                        inputmode="decimal"
                                        autocomplete="off"
                                        :placeholder="displayDecimals(item.available_quantity)"
                                        :aria-invalid="lineError(`line-${b}-${l}`) !== null"
                                        class="form-field min-h-11 rounded-lg px-3 py-2 tabular-nums focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                        :data-testid="fieldId(`line-${b}-${l}-quantity`)"
                                    />
                                </label>
                                <label class="flex flex-col gap-1.5" :for="fieldId(`line-${b}-${l}-margin_percent`)">
                                    <span>{{ t('quotations.builder.lineMargin') }}</span>
                                    <input
                                        :id="fieldId(`line-${b}-${l}-margin_percent`)"
                                        v-model="block.margins[l]"
                                        type="text"
                                        dir="ltr"
                                        autocomplete="off"
                                        class="form-field min-h-11 rounded-lg px-3 py-2 tabular-nums focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                        :data-testid="fieldId(`line-${b}-${l}-margin_percent`)"
                                    />
                                </label>
                                <!-- §5.6: a missing price blocks; an excess quantity is red and does not. -->
                                <p v-if="lineError(`line-${b}-${l}`) !== null" class="line-note text-[var(--color-danger)]" role="alert" :data-testid="fieldId(`line-${b}-${l}-error`)">
                                    {{ lineError(`line-${b}-${l}`) }}
                                </p>
                                <p v-else-if="lineWarning(`line-${b}-${l}`) !== null" class="line-note warning-line rounded-lg p-2" :data-testid="fieldId(`line-${b}-${l}-warning`)">
                                    {{ lineWarning(`line-${b}-${l}`) }}
                                </p>
                            </div>
                        </template>
                    </div>
                </section>

                <!-- Additional items: never taxed (`D-62`) — the server knows, this form only lists them. -->
                <section class="flex flex-col gap-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-card-title">{{ t('quotations.detail.additionalItems') }}</h2>
                        <button type="button" class="row-action min-h-11 rounded-lg px-3 disabled:cursor-not-allowed disabled:opacity-60" data-testid="quotation-builder-add-item" @click="addItem">
                            + {{ t('quotations.builder.addItem') }}
                        </button>
                    </div>

                    <div v-for="(item, i) in items" :key="i" class="line-grid line-row rounded-lg p-3">
                        <label class="flex flex-col gap-1.5" :for="fieldId(`item-${i}-description`)">
                            <span>{{ t('quotations.detail.description') }}</span>
                            <input
                                :id="fieldId(`item-${i}-description`)"
                                v-model="item.description"
                                type="text"
                                maxlength="255"
                                autocomplete="off"
                                :aria-invalid="fieldErrors.has(`additional_items.${i}.description`)"
                                class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                :data-testid="fieldId(`item-${i}-description`)"
                            />
                        </label>
                        <label class="flex flex-col gap-1.5" :for="fieldId(`item-${i}-amount`)">
                            <span>{{ t('quotations.detail.amount') }}</span>
                            <input
                                :id="fieldId(`item-${i}-amount`)"
                                v-model="item.amount"
                                type="text"
                                inputmode="decimal"
                                autocomplete="off"
                                :aria-invalid="fieldErrors.has(`additional_items.${i}.amount`)"
                                class="form-field min-h-11 rounded-lg px-3 py-2 tabular-nums focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                :data-testid="fieldId(`item-${i}-amount`)"
                            />
                        </label>
                        <button type="button" class="row-action min-h-11 self-end rounded-lg px-3 disabled:cursor-not-allowed disabled:opacity-60" :data-testid="fieldId(`item-${i}-remove`)" @click="removeItem(i)">
                            {{ t('quotations.builder.removeItem') }}
                        </button>
                        <p v-if="itemError(i) !== null" class="line-note text-[var(--color-danger)]" role="alert" :data-testid="fieldId(`item-${i}-error`)">
                            {{ itemError(i) }}
                        </p>
                    </div>
                </section>

                <!-- Terms (D-103): a list built like the additional items, up to 15, printed
                     numbered. A ready term (keyed) keeps its default name as the placeholder and,
                     under its text, the caller's recent terms as chips (6.8, Design System §6.3). -->
                <section class="flex flex-col gap-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-card-title">{{ t('quotations.terms.heading') }}</h2>
                        <button
                            type="button"
                            class="row-action min-h-11 rounded-lg px-3 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="terms.length >= MAX_TERMS"
                            :data-testid="fieldId('add-term')"
                            @click="terms.push({ key: null, title: '', body: '' })"
                        >
                            + {{ t('quotations.terms.add') }}
                        </button>
                    </div>

                    <div v-for="(term, i) in terms" :key="i" class="line-row flex flex-col gap-3 rounded-lg p-3">
                        <div class="flex flex-wrap items-end gap-3">
                            <label class="flex min-w-40 flex-1 flex-col gap-1.5" :for="fieldId(`term-${i}-title`)">
                                <span>{{ t('quotations.terms.name') }}</span>
                                <input
                                    :id="fieldId(`term-${i}-title`)"
                                    v-model="term.title"
                                    type="text"
                                    maxlength="255"
                                    autocomplete="off"
                                    :placeholder="term.key === null ? '' : t(`quotations.terms.labels.${term.key}`)"
                                    :aria-invalid="fieldErrors.has(`terms.${i}.title`)"
                                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                    :data-testid="fieldId(`term-${i}-title`)"
                                />
                            </label>
                            <button type="button" class="row-action min-h-11 rounded-lg px-3" :data-testid="fieldId(`term-${i}-remove`)" @click="terms.splice(i, 1)">
                                {{ t('quotations.builder.removeItem') }}
                            </button>
                        </div>
                        <label class="flex flex-col gap-1.5" :for="fieldId(`term-${i}-body`)">
                            <span>{{ t('quotations.terms.body') }}</span>
                            <!-- F-32: the delivery term is a combobox over the managed list. -->
                            <SearchCombobox
                                v-if="offersList(term.key)"
                                :id="fieldId(`term-${i}-body`)"
                                :test-id="fieldId(`term-${i}-body`)"
                                :search="searchTerms"
                                :keys="TERM_KEYS"
                                :selected="(option: TermOption) => option.name === term.body"
                                :display="oneLine(term.body)"
                                :placeholder="t('quotations.builder.deliveryTermPlaceholder')"
                                floating
                                @typed="term.body = $event"
                                @pick="term.body = $event?.name ?? ''"
                                @keydown.enter.prevent
                            >
                                <template #option="{ item }">{{ item.name }}</template>
                            </SearchCombobox>
                            <textarea
                                v-else
                                :id="fieldId(`term-${i}-body`)"
                                v-model="term.body"
                                rows="3"
                                :aria-invalid="fieldErrors.has(`terms.${i}.body`)"
                                class="form-field rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                :data-testid="fieldId(`term-${i}-body`)"
                            ></textarea>
                        </label>
                        <!-- F-32: the combobox is one line, so a long term is cut there at
                             375 px; its whole wraps here (F-24 · 1.5's option C). The field
                             already gives it to a screen reader. -->
                        <span
                            v-if="offersList(term.key) && term.body !== ''"
                            aria-hidden="true"
                            class="text-sm break-words text-[var(--color-text-muted)]"
                            :data-testid="fieldId(`term-${i}-full`)"
                        >{{ term.body }}</span>
                        <p v-if="termError(i) !== null" class="text-[var(--color-danger)]" role="alert" :data-testid="fieldId(`term-${i}-error`)">
                            {{ termError(i) }}
                        </p>
                        <span v-if="term.key !== null && suggestions[term.key].length > 0" class="flex flex-wrap gap-1.5" :data-testid="fieldId(`term-${i}-suggestions`)">
                            <span class="sr-only">{{ t('quotations.builder.recentTerms') }}</span>
                            <button
                                v-for="recent in suggestions[term.key]"
                                :key="recent"
                                type="button"
                                class="row-action min-h-11 rounded-full px-3 text-sm"
                                :data-testid="fieldId(`term-${i}-suggestion`)"
                                @click="term.body = recent"
                            >{{ recent }}</button>
                        </span>
                    </div>
                </section>

                <div v-if="!frozen" class="flex flex-wrap gap-2">
                    <button
                        type="submit"
                        class="save-action min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="saving"
                        data-testid="quotation-builder-save"
                    >
                        {{ saving ? t('quotations.builder.saving') : t(approving ? 'quotations.builder.editAndApprove' : editing ? 'quotations.builder.update' : 'quotations.builder.save') }}
                    </button>
                    <RouterLink
                        :to="editing ? { name: 'quotation-detail', params: { id: quotationId } } : { name: 'deal-detail', params: { id: deal.id } }"
                        class="row-action inline-flex min-h-11 items-center rounded-lg px-3"
                    >
                        {{ editing ? t('quotations.builder.cancelEdit') : t('quotations.builder.cancel') }}
                    </RouterLink>
                </div>
            </fieldset>
        </form>

        <SupplierQuotationFormModal
            :open="offerFormOpen"
            :editing="null"
            @saved="onOfferSaved"
            @cancel="offerFormOpen = false"
        />
    </section>
</template>

<style scoped>
.detail-grid {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
    background-color: var(--color-surface);
    border: 1px solid var(--color-border);
}

/* One line: label, quantity, margin; its note spans the row. `§4.3`: one column under 640px. */
.line-grid {
    display: grid;
    gap: 0.75rem;
    grid-template-columns: minmax(10rem, 2fr) minmax(7rem, 1fr) minmax(7rem, 1fr);
    align-items: end;
}

.line-note {
    grid-column: 1 / -1;
}

@media (max-width: 639px) {
    .line-grid {
        grid-template-columns: 1fr;
    }
}

.line-row {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border);
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

.form-saved {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-border-strong);
}

/* `Design System §6.4`: red, and the words beside it carry the meaning. */
.warning-line {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-danger);
    color: var(--color-danger);
}

.row-link {
    color: var(--color-primary);
    text-decoration: underline;
}

.row-action {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.save-action {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
}
</style>
