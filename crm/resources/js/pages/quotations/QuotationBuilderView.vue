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
 * ── Lines (QS.2a, D-155) ───────────────────────────────────────────────────
 * A line is typed in one entry row — an existing supplier, product and unit,
 * the entered price, tax, margin and quantity — and Enter (or Add) moves it
 * into the table. Supplier, tax and margin stay for the next product; a row
 * can be removed or sent back to the entry fields. Typing a new supplier,
 * product or unit is slice 3. The «Prices include tax» toggle is the preparer's
 * (`D-157`): off hides the tax field and the column and sends no line tax.
 * The table shows what was typed; the server prices it (`D-67`, QS.2b previews).
 *
 * ── Refusals ───────────────────────────────────────────────────────────────
 * A 422 `validation_failed` lands on the field it names, Module 6's
 * `applyServerErrors` pattern: `lines.N.<field>` on the table's row N, `lines`
 * (the cap) on the table. No warning rides a save any more (`D-161`), so a
 * clean save goes straight to the detail.
 *
 * ── Idempotency ────────────────────────────────────────────────────────────
 * `OpenAPI §9.1`: one `Idempotency-Key` per form open. A retry after a
 * failure replays the same key; a fresh form mints a fresh one.
 *
 * ── Edit (6.7) ─────────────────────────────────────────────────────────────
 * The form is loaded from `GET /quotations/{id}`'s `entry`; `PATCH` carries the
 * detail's `etag` as `If-Match` (`API-12`) and both lists always — an edit
 * replaces every editable field (Point 3.6). A caller without the cost grant
 * gets no `entry`: the builder refuses to open rather than save `lines: []`.
 * A 409 `concurrency_conflict` is a banner naming the newer version's total
 * with a reload, never a retry; a 422 `quotation_not_draft` sends the person
 * back to the quotation's page. Leaving with unsaved changes asks first
 * (`Design System §5.2`).
 *
 * ── Terms (D-103) ──────────────────────────────────────────────────────────
 * A list built like the additional items, up to 15, sent as `terms` in row
 * order. `D-169`: a new quotation opens on one empty term with no key. Keyed
 * terms (`payment_terms`, `warranty`, `delivery_terms`) come only from a stored
 * quotation; there the key, not the row, carries the default name, the chips
 * and the combobox, so they follow a renamed or moved term. An added term has
 * no key. An empty name or text is sent as null, and an empty term is kept
 * (the owner, 2026-10-08).
 *
 * ── Delivery terms (F-32) ──────────────────────────────────────────────────
 * The delivery-keyed term's text, with suggestions like the other ready terms
 * (`§6.2`, `Design System §6.3`). When the `delivery_terms` managed list has entries it is an
 * editable combobox over them: focus opens the list, typing narrows it, a pick
 * fills the box, and text that matches nothing is kept and saved as typed. The
 * stored value is the text either way, so the API and the PDF never learn there
 * is a list. With no entries, or the list refused, it is the text area it was.
 *
 * Stated ceilings: the units and the delivery terms are each one page of 25,
 * as the catalog's lists are.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { onBeforeRouteLeave, onBeforeRouteUpdate, RouterLink, useRoute, useRouter, type RouteLocationNormalized } from 'vue-router';
import { ApiError, collection, type Page } from '@/api';
import { entryLabel, listCurrencies, listEntries, type Currency, type ListEntry } from '@/services/admin';
import SearchCombobox from '@/components/SearchCombobox.vue';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import { SUPPLIER_KEYS, supplierDetail } from '@/components/suppliers/supplierOptions';
import { listCatalogItems, type CatalogItem } from '@/services/catalog';
import { readCustomer, type Customer } from '@/services/customers';
import { readDeal, type Deal } from '@/services/deals';
import {
    createQuotation,
    editAndApproveQuotation,
    listTermSuggestions,
    readQuotation,
    updateQuotation,
    type PricingMode,
    type QuotationDetail,
    type QuotationDraft,
    type TermField,
} from '@/services/quotations';
import { listSuppliers, type Supplier } from '@/services/suppliers';
import { displayDecimals, displayPercent, displayQuantity } from '@/domain/displayDecimals';
import { focusFirstInvalid } from '@/components/focusFirstInvalid';
import { displayTimestamp } from '@/domain/displayDate';

const route = useRoute();
const router = useRouter();
const { t, locale } = useI18n();

const HEADER_FIELDS = ['currency', 'discount_percent', 'quotation_date', 'valid_until'] as const;

type HeaderField = (typeof HEADER_FIELDS)[number];

/** A picked supplier or product: the id the payload needs and the name the table shows. */
interface Picked {
    id: string;
    name: string;
}

/** One table row — what was typed (`D-155`), plus the two names its cells show. */
interface Line {
    supplier: Picked;
    product: Picked;
    unit: string;
    price: string;
    tax: string;
    margin: string;
    quantity: string;
}

type LineField = 'supplier_id' | 'catalog_item_id' | 'unit_code' | 'entered_unit_price' | 'tax_percent' | 'margin_percent' | 'quantity';
const LINE_FIELDS: LineField[] = ['supplier_id', 'catalog_item_id', 'unit_code', 'entered_unit_price', 'tax_percent', 'margin_percent', 'quantity'];

/** Module 8 · 3.2: the approver's route — the same form, saved through 1.3. */
const approving = computed(() => route.name === 'quotation-edit-and-approve');
const editing = computed(() => route.name === 'quotation-edit' || approving.value);
const quotationId = computed(() => String(route.params.id ?? ''));
/** From `?deal=` on a create; from the detail on an edit. */
const dealId = ref(String(route.query.deal ?? ''));

const deal = ref<Deal | null>(null);
const customer = ref<Customer | null>(null);

/** D-80's list; empty when it could not be read, and the code is typed instead. */
const currencies = ref<Currency[]>([]);

const loading = ref(true);
const failed = ref(false);
const denied = ref(false);
const missing = ref(false);

function blankHeader(): Record<HeaderField, string> {
    return { currency: '', discount_percent: '0', quotation_date: '', valid_until: '' };
}

const header = ref<Record<HeaderField, string>>(blankHeader());
/** `D-157`: the preparer's toggle; on by default (owner, 2026-10-22). */
const taxIncluded = ref(true);
/** The three ready terms' keys; Point 6.8's recent terms are read per key, and a chip copies one into that term's text. */
const TERM_FIELDS: TermField[] = ['payment_terms', 'warranty', 'delivery_terms'];
/** `D-103`'s cap. */
const MAX_TERMS = 15;
/** `D-103`: the terms as typed, in their printed order; `D-169`: a new quotation opens on one empty keyless term. */
function readyTerms(): Array<{ key: TermField | null; title: string; body: string }> {
    return [{ key: null, title: '', body: '' }];
}

const terms = ref(readyTerms());
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
const lines = ref<Line[]>([]);
/** `D-155`: the entry row. Supplier, tax and margin stay after Enter; the rest clears. */
const entry = ref(blankEntry());
/** The `units` managed list — an entry's unit is picked from it by code. */
const units = ref<ListEntry[]>([]);
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
const form = ref<HTMLFormElement | null>(null);

function blankEntry() {
    return { supplier: null as Picked | null, product: null as Picked | null, unit: '', price: '', tax: '', margin: '', quantity: '' };
}

/** `D-155`: a unit is a code; the list names it, the code stands in until (or unless) the list is read. */
function unitLabel(code: string): string {
    const found = units.value.find((unit) => unit.code === code);

    return found === undefined ? code : entryLabel(found, locale.value);
}

/** A table row's refusal: the first of its seven fields the server named. `lines.N` is the table's row N. */
function lineError(index: number): string | null {
    for (const field of LINE_FIELDS) {
        const message = fieldErrors.value.get(`lines.${index}.${field}`);

        if (message !== undefined) {
            return message;
        }
    }

    return null;
}

/** A row moved, so the indexes the server named no longer point at the same rows. */
function dropLineErrors(): void {
    for (const key of [...fieldErrors.value.keys()]) {
        if (key === 'lines' || key.startsWith('lines.')) {
            fieldErrors.value.delete(key);
        }
    }
}

/** The form as typed — the dirty check compares this against `opened`. */
function snapshot(): string {
    return JSON.stringify({
        header: header.value,
        terms: terms.value,
        taxIncluded: taxIncluded.value,
        lines: lines.value,
        items: items.value,
    });
}

/** Nothing to lose before the form opened (a refused load, a redirect) or since it last saved. */
const dirty = computed(() => opened.value !== '' && snapshot() !== opened.value);

/** The server sends `entry` to every cost viewer, a legacy Draft included (contract §8); without it, saving would send `lines: []` over real lines. */
function lacksEntry(quotation: QuotationDetail): boolean {
    return quotation.entry === undefined;
}

/** Fill the form from the detail (edit): header, lines, items, and the token the next write needs. */
function applyDetail(quotation: QuotationDetail): void {
    header.value = {
        currency: quotation.currency,
        // D-112: a percentage is offered without its trailing zeros — the same number.
        discount_percent: displayPercent(quotation.discount_percent),
        quotation_date: quotation.quotation_date ?? '',
        valid_until: quotation.valid_until ?? '',
    };
    // D-165: a legacy Draft's `entry` is the server's conversion, so the toggle follows `entry`, not the header.
    taxIncluded.value = (quotation.entry?.pricing_mode ?? quotation.pricing_mode) !== 'tax_exempt';
    terms.value = quotation.terms.map((term) => ({ key: term.key, title: term.title ?? '', body: term.body ?? '' }));
    // U-5: a stored figure is offered without its trailing zeros — trimmed, never rounded.
    lines.value = (quotation.entry?.lines ?? []).map((line) => ({
        supplier: { id: line.supplier_id, name: line.supplier_name ?? '' },
        product: { id: line.catalog_item_id, name: line.product_name ?? '' },
        // A legacy line backfilled from a catalog item without a unit has none; the unit is left to choose (D-155).
        unit: line.unit_code ?? '',
        price: displayQuantity(line.entered_unit_price),
        tax: displayPercent(line.tax_percent) ?? '',
        margin: displayPercent(line.margin_percent),
        quantity: displayQuantity(line.quantity),
    }));
    entry.value = blankEntry();
    items.value = quotation.additional_items.map((item) => ({ description: item.description, amount: displayQuantity(item.amount) }));
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

            // A-03's neighbour: no `entry` means no cost grant, and the form could not keep the lines.
            if (lacksEntry(quotation)) {
                denied.value = true;
                loading.value = false;

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
        // D-80: the builder's roles hold `currency.view`. Refused or failed,
        // the list stays empty and the code is typed, as before F-02.
        listCurrencies().then((list) => { currencies.value = list; }, () => {}),
        // QS.2a: the entry row's unit is picked from this list; the table names a stored code from it.
        listEntries('units', 1).then((page) => { units.value = page.items; }, () => {}),
        // D-169: only a stored quotation can hold a keyed term, so only an edit reads what one shows.
        ...(editing.value ? [
            ...TERM_FIELDS.map((field) => listTermSuggestions(field).then((terms) => { suggestions.value[field] = terms; }, () => {})),
            // F-32: best-effort too — without the list the delivery field is the text area it was.
            listEntries('delivery_terms', 1).then((page) => { termEntries.value = page.items; }, () => {}),
        ] : []),
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

/** A-17: focus the first of these fields that exists, once the DOM has caught up. */
async function focusField(...keys: string[]): Promise<void> {
    await nextTick();

    for (const key of keys) {
        const field = form.value?.querySelector<HTMLElement>(`#${fieldId(key)}`);

        if (field) {
            field.focus();

            return;
        }
    }
}

/** A-35: how long a removal can be undone. */
const UNDO_MS = 6000;

/** A-35: the last removal; a newer one makes the older final. `next` and `fallback` are A-17's focus rule. */
const undo = ref<{ message: string; restore: () => void; next: string; fallback: string } | null>(null);
let undoTimer: ReturnType<typeof setTimeout> | undefined;

function removed(message: string, restore: () => void, next: string, fallback: string): void {
    clearTimeout(undoTimer);
    undo.value = { message, restore, next, fallback };
    undoTimer = setTimeout(expireUndo, UNDO_MS);
    void focusField('undo');
}

function expireUndo(): void {
    const pending = undo.value;
    // A-17 only while focus is still on the bar: a person who moved on keeps their place.
    const onBar = document.activeElement?.closest('[data-testid="quotation-builder-undo-bar"]') != null;

    undo.value = null;

    if (pending !== null && onBar) {
        void focusField(pending.next, pending.fallback);
    }
}

function restoreRemoved(): void {
    const pending = undo.value;

    clearTimeout(undoTimer);
    undo.value = null;

    if (pending !== null) {
        pending.restore();
        void focusField(pending.next);
    }
}

/** `SearchCombobox` over the suppliers (`SupplierPicker`'s search and states, one pick). */
function pickSupplier(supplier: Supplier | null): void {
    entry.value.supplier = supplier === null ? null : { id: supplier.id, name: supplier.name };
}

type ProductOption = CatalogItem & { name: string };

// ponytail: PRODUCT_KEYS and searchProducts copy CatalogItemPicker.vue:38-53 minus `supplierFirst` (D-93) and the
// «Type a name instead» option (D-22); slice 3 (D-160, typing a new product) replaces this search.
const PRODUCT_KEYS = {
    more: 'catalog.picker.more',
    forbidden: 'catalog.picker.forbidden',
    failed: 'catalog.picker.failed',
    empty: 'catalog.picker.empty',
    noMatch: 'catalog.picker.noMatch',
    retry: 'state.retry',
};

/** §7.3 keeps a service's label in `service_type` and a product's in `name`; the id is the last resort. */
async function searchProducts(query: { perPage: number; q: string | null }): Promise<Page<ProductOption>> {
    const page = await listCatalogItems({ ...query, isActive: true });

    return { ...page, items: page.items.map((item) => ({ ...item, name: item.name ?? item.service_type ?? item.id })) };
}

function pickProduct(product: ProductOption | null): void {
    entry.value.product = product === null ? null : { id: product.id, name: product.name };
}

/**
 * Enter (or Add) moves the entry row into the table (`D-155`). Supplier, tax
 * and margin stay for the next product; product, price, quantity and unit
 * clear. A row without the five things `D-155` blocks saving on is not moved —
 * focus goes to the first one missing, and the server still refuses the rest.
 */
function addLine(): void {
    const e = entry.value;
    const absent: Array<[string, boolean]> = [
        ['supplier', e.supplier === null],
        ['product', e.product === null],
        ['unit', e.unit === ''],
        ['price', e.price.trim() === ''],
        ['quantity', e.quantity.trim() === ''],
    ];
    const missing = absent.find(([, isMissing]) => isMissing);

    if (missing !== undefined || e.supplier === null || e.product === null) {
        void focusField(`entry-${missing?.[0] ?? 'supplier'}`);

        return;
    }

    dropLineErrors();
    lines.value.push({ supplier: e.supplier, product: e.product, unit: e.unit, price: e.price.trim(), tax: e.tax.trim(), margin: e.margin.trim(), quantity: e.quantity.trim() });
    Object.assign(e, { product: null, unit: '', price: '', quantity: '' });
    void focusField('entry-product');
}

/** A-37: Enter in the entry row moves it into the table — a combobox that took the key to pick has already handled it. */
function entryEnter(event: KeyboardEvent): void {
    if (event.defaultPrevented || !(event.target instanceof HTMLInputElement)) {
        return;
    }

    event.preventDefault();
    addLine();
}

function removeLine(index: number): void {
    const [line] = lines.value.splice(index, 1);

    dropLineErrors();
    removed('quotations.builder.removed', () => { lines.value.splice(index, 0, line as Line); }, 'entry-product', 'entry-product');
}

/** The row goes back to the entry fields, its own values winning over the sticky ones. */
function sendBack(index: number): void {
    const [line] = lines.value.splice(index, 1);

    if (line === undefined) {
        return;
    }

    dropLineErrors();
    entry.value = { supplier: line.supplier, product: line.product, unit: line.unit, price: line.price, tax: line.tax, margin: line.margin, quantity: line.quantity };
    void focusField('entry-price');
}

function addItem(): void {
    items.value.push({ description: '', amount: '' });
    void focusField(`item-${items.value.length - 1}-description`);
}

function removeItem(index: number): void {
    const [item] = items.value.splice(index, 1);

    removed('quotations.builder.removed', () => { items.value.splice(index, 0, item as { description: string; amount: string }); }, `item-${index}-description`, 'add-item');
}

function addTerm(): void {
    terms.value.push({ key: null, title: '', body: '' });
    void focusField(`term-${terms.value.length - 1}-title`);
}

function removeTerm(index: number): void {
    const [term] = terms.value.splice(index, 1);

    removed('quotations.builder.removed', () => { terms.value.splice(index, 0, term as (typeof terms.value)[number]); }, `term-${index}-title`, 'add-term');
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

/** A-37: Enter in an additional item's field steps to the next field; only Save submits. The entry row has its own Enter. */
function nextField(event: KeyboardEvent): void {
    const target = event.target;

    if (!(target instanceof HTMLInputElement) || target.closest(`[data-testid="${fieldId('items')}"]`) === null) {
        return;
    }

    event.preventDefault();

    const fields = [...(form.value?.querySelectorAll<HTMLElement>('input, select, textarea, button:not([disabled])') ?? [])];

    fields[fields.indexOf(target) + 1]?.focus();
}

function draft(): QuotationDraft {
    const mode: PricingMode = taxIncluded.value ? 'tax_included' : 'tax_exempt';

    return {
        currency: header.value.currency.trim().toUpperCase(),
        pricing_mode: mode,
        discount_percent: header.value.discount_percent,
        quotation_date: orNull(header.value.quotation_date),
        valid_until: orNull(header.value.valid_until),
        // The owner, 2026-10-08: an empty ready term is kept, its body null.
        terms: terms.value.map((term) => ({ key: term.key, title: orNull(term.title), body: orNull(term.body) })),
        // D-157: off is no tax at all, so a line names no rate.
        lines: lines.value.map((line) => ({
            supplier_id: line.supplier.id,
            catalog_item_id: line.product.id,
            unit_code: line.unit,
            entered_unit_price: line.price,
            ...(taxIncluded.value ? { tax_percent: line.tax } : {}),
            margin_percent: line.margin,
            quantity: line.quantity,
        })),
        additional_items: items.value.map((item) => ({ description: item.description, amount: item.amount })),
    };
}

/** `OpenAPI §5` — `details[]` names the field. */
function applyServerErrors(error: unknown): void {
    fieldErrors.value = new Map();

    if (!(error instanceof ApiError)) {
        formError.value = t('quotations.builder.unreachable');

        return;
    }

    for (const detail of error.details) {
        if (typeof detail.field === 'string' && typeof detail.message === 'string') {
            fieldErrors.value.set(detail.field, detail.message);
        }
    }

    if (formError.value === '' && fieldErrors.value.size === 0) {
        formError.value = error.message;
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
            : editing.value
                ? await updateQuotation(quotationId.value, etag.value, draft())
                : await createQuotation({ ...draft(), deal_id: current.id, customer_id: current.customer_id }, idempotencyKey);

        opened.value = snapshot();
        await router.push({ name: 'quotation-detail', params: { id: result.quotation.id } });
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

    // A-11: after `saving` clears, as a disabled field cannot take focus.
    if (fieldErrors.value.size > 0) {
        await focusFirstInvalid(form.value);
    }
}

/**
 * `Design System §5.2`: leaving a builder with unsaved changes asks first —
 * A-61: in the page, as the supplier dialog asks, because `window.confirm` is
 * neither translated nor RTL. The route waits in `leaving` until the answer.
 */
const leaving = ref<string | null>(null);
const keepEditingButton = ref<HTMLButtonElement | null>(null);

function guardLeave(to: RouteLocationNormalized): boolean {
    if (!dirty.value) {
        return true;
    }

    leaving.value = to.fullPath;
    void nextTick(() => keepEditingButton.value?.focus());

    return false;
}

onBeforeRouteLeave(guardLeave);
onBeforeRouteUpdate(guardLeave);

async function discardChanges(): Promise<void> {
    const to = leaving.value;

    leaving.value = null;
    opened.value = '';

    if (to !== null) {
        await router.push(to);
    }
}

/**
 * A-01: one instance serves all three builder routes (the router reuses it), so
 * a move between them starts from blank, as a fresh page would.
 */
const BUILDER_ROUTES = ['quotation-new', 'quotation-edit', 'quotation-edit-and-approve'];

function reset(): void {
    dealId.value = String(route.query.deal ?? '');
    deal.value = null;
    customer.value = null;
    header.value = blankHeader();
    terms.value = readyTerms();
    taxIncluded.value = true;
    lines.value = [];
    entry.value = blankEntry();
    items.value = [];
    etag.value = '';
    quotationCode.value = '';
    conflict.value = null;
    opened.value = '';
    formError.value = '';
    fieldErrors.value = new Map();
    idempotencyKey = crypto.randomUUID();
    clearTimeout(undoTimer);
    undo.value = null;
}

watch(() => route.fullPath, () => {
    if (BUILDER_ROUTES.includes(String(route.name))) {
        reset();
        void load();
    }
});

function onBeforeUnload(event: BeforeUnloadEvent): void {
    if (dirty.value) {
        event.preventDefault();
    }
}

window.addEventListener('beforeunload', onBeforeUnload);
onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', onBeforeUnload);
    clearTimeout(undoTimer);
});

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
            {{ t(editing ? 'quotations.builder.missingEdit' : 'quotations.builder.missing') }}
        </p>

        <form v-else-if="deal !== null" ref="form" class="flex flex-col gap-4" novalidate data-testid="quotation-builder-form" @submit.prevent="save" @keydown.enter="nextField">
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

            <p v-if="fieldErrors.size > 0" class="form-alert rounded-lg p-3" role="alert" data-testid="quotation-builder-error-count">
                {{ t('form.fixCount', fieldErrors.size) }}
            </p>

            <!-- `API-12`: the newer version's figures and a reload; nothing is merged or retried. -->
            <div v-if="conflict !== null" class="form-alert flex flex-wrap items-center justify-between gap-3 rounded-lg p-3" role="alert" data-testid="quotation-builder-conflict">
                <span>{{ t('quotations.builder.conflict', { total: displayDecimals(conflict.final_total), currency: conflict.currency, at: displayTimestamp(conflict.updated_at, locale) }) }}</span>
                <button type="button" class="row-action min-h-11 rounded-lg px-3" data-testid="quotation-builder-conflict-reload" @click="reload">
                    {{ t('quotations.detail.reload') }}
                </button>
            </div>

            <fieldset class="contents" :disabled="saving">
                <!-- `Design System §7.2`: currency, discount and the tax toggle in their own group; §4.3 grid. -->
                <div class="detail-grid rounded-xl p-4">
                    <label class="flex flex-col gap-1.5" :for="fieldId('currency')">
                        <span>{{ t('quotations.builder.currency') }} <span class="text-[var(--color-danger)]">{{ t('form.required') }}</span></span>
                        <!-- The value is the ISO code either way: `QuotationDraft.currency`
                             takes a code, and the edit hydrates one. -->
                        <select
                            v-if="currencies.length > 0"
                            :id="fieldId('currency')"
                            v-model="header.currency"
                            required
                            :aria-invalid="fieldErrors.has('currency')"
                            :aria-describedby="fieldErrors.has('currency') ? fieldId('currency-error') : undefined"
                            class="form-field min-h-11 rounded-lg px-3 py-2"
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
                            class="form-field min-h-11 rounded-lg px-3 py-2 uppercase"
                            :data-testid="fieldId('currency')"
                        />
                        <span v-if="fieldErrors.has('currency')" :id="fieldId('currency-error')" class="text-[var(--color-danger)]" :data-testid="fieldId('currency-error')">
                            {{ fieldErrors.get('currency') }}
                        </span>
                    </label>

                    <label class="flex flex-col gap-1.5" :for="fieldId('discount_percent')">
                        <span>{{ t('quotations.builder.discount_percent') }} <span class="text-[var(--color-danger)]">{{ t('form.required') }}</span></span>
                        <input
                            :id="fieldId('discount_percent')"
                            v-model="header.discount_percent"
                            type="text"
                            inputmode="decimal"
                            autocomplete="off"
                            required
                            :aria-invalid="fieldErrors.has('discount_percent')"
                            :aria-describedby="fieldErrors.has('discount_percent') ? fieldId('discount_percent-error') : undefined"
                            class="form-field min-h-11 rounded-lg px-3 py-2 tabular-nums"
                            :data-testid="fieldId('discount_percent')"
                        />
                        <span v-if="fieldErrors.has('discount_percent')" :id="fieldId('discount_percent-error')" class="text-[var(--color-danger)]" :data-testid="fieldId('discount_percent-error')">
                            {{ fieldErrors.get('discount_percent') }}
                        </span>
                    </label>

                    <label v-for="field in (['quotation_date', 'valid_until'] as const)" :key="field" class="flex flex-col gap-1.5" :for="fieldId(field)">
                        <span>{{ t(`quotations.builder.${field}`) }}</span>
                        <input
                            :id="fieldId(field)"
                            v-model="header[field]"
                            type="date"
                            :aria-invalid="fieldErrors.has(field)"
                            :aria-describedby="fieldErrors.has(field) ? fieldId(`${field}-error`) : undefined"
                            class="form-field min-h-11 rounded-lg px-3 py-2"
                            :data-testid="fieldId(field)"
                        />
                        <span v-if="fieldErrors.has(field)" :id="fieldId(`${field}-error`)" class="text-[var(--color-danger)]" :data-testid="fieldId(`${field}-error`)">
                            {{ fieldErrors.get(field) }}
                        </span>
                    </label>

                    <!-- D-157: the preparer's decision, outside the fields; off means no tax at all. -->
                    <label class="flex min-h-11 items-center gap-2 self-end">
                        <input v-model="taxIncluded" type="checkbox" :data-testid="fieldId('tax-included')" />
                        <span>{{ t('quotations.builder.taxIncluded') }}</span>
                    </label>
                </div>

                <!-- Lines (QS.2a, D-155): the entry row, then the table it fills. -->
                <section class="flex flex-col gap-3">
                    <h2 class="text-card-title">{{ t('quotations.detail.lines') }}</h2>

                    <div class="entry-grid line-row rounded-xl p-3" @keydown.enter="entryEnter">
                        <div class="entry-what">
                            <div class="flex flex-col gap-1.5">
                                <label :for="fieldId('entry-supplier')">{{ t('quotations.detail.supplier') }}</label>
                                <SearchCombobox
                                    :id="fieldId('entry-supplier')"
                                    :test-id="fieldId('entry-supplier')"
                                    :search="listSuppliers"
                                    :keys="SUPPLIER_KEYS"
                                    :selected="(supplier: Supplier) => supplier.id === entry.supplier?.id"
                                    :display="entry.supplier?.name ?? ''"
                                    :placeholder="t('suppliers.picker.placeholder')"
                                    floating
                                    @pick="pickSupplier"
                                >
                                    <template #option="{ item }">
                                        <span>
                                            <span class="block">{{ item.name }}</span>
                                            <span v-if="supplierDetail(item, t) !== ''" class="block text-sm text-[var(--color-text-muted)]">{{ supplierDetail(item, t) }}</span>
                                        </span>
                                    </template>
                                </SearchCombobox>
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label :for="fieldId('entry-product')">{{ t('quotations.detail.product') }}</label>
                                <SearchCombobox
                                    :id="fieldId('entry-product')"
                                    :test-id="fieldId('entry-product')"
                                    :search="searchProducts"
                                    :keys="PRODUCT_KEYS"
                                    :selected="(product: ProductOption) => product.id === entry.product?.id"
                                    :display="entry.product?.name ?? ''"
                                    :placeholder="t('catalog.picker.placeholder')"
                                    floating
                                    @pick="pickProduct"
                                >
                                    <template #option="{ item }">{{ item.name }}</template>
                                </SearchCombobox>
                            </div>

                            <label class="flex flex-col gap-1.5" :for="fieldId('entry-unit')">
                                <span>{{ t('quotations.detail.unit') }}</span>
                                <select :id="fieldId('entry-unit')" v-model="entry.unit" class="form-field min-h-11 rounded-lg px-3 py-2" :data-testid="fieldId('entry-unit')">
                                    <option value="">{{ t('quotations.builder.unitNone') }}</option>
                                    <option v-for="unit in units" :key="unit.code" :value="unit.code">{{ entryLabel(unit, locale) }}</option>
                                </select>
                            </label>
                        </div>

                        <div class="entry-figures">
                            <label class="flex flex-col gap-1.5" :for="fieldId('entry-price')">
                                <span>{{ t('quotations.detail.enteredPrice') }}</span>
                                <input :id="fieldId('entry-price')" v-model="entry.price" type="text" inputmode="decimal" dir="ltr" autocomplete="off" class="form-field min-h-11 rounded-lg px-3 py-2 tabular-nums" :data-testid="fieldId('entry-price')" />
                            </label>

                            <label v-if="taxIncluded" class="flex flex-col gap-1.5" :for="fieldId('entry-tax')">
                                <span>{{ t('quotations.builder.tax_percent') }}</span>
                                <input :id="fieldId('entry-tax')" v-model="entry.tax" type="text" inputmode="decimal" dir="ltr" autocomplete="off" class="form-field min-h-11 rounded-lg px-3 py-2 tabular-nums" :data-testid="fieldId('entry-tax')" />
                            </label>

                            <label class="flex flex-col gap-1.5" :for="fieldId('entry-margin')">
                                <span>{{ t('quotations.builder.margin_percent') }}</span>
                                <!-- A-62: text with no inputmode — a margin may be negative (D-106), and a decimal keypad has no minus on iOS. -->
                                <input :id="fieldId('entry-margin')" v-model="entry.margin" type="text" dir="ltr" autocomplete="off" class="form-field min-h-11 rounded-lg px-3 py-2 tabular-nums" :data-testid="fieldId('entry-margin')" />
                            </label>

                            <label class="flex flex-col gap-1.5" :for="fieldId('entry-quantity')">
                                <span>{{ t('quotations.detail.quantity') }}</span>
                                <input :id="fieldId('entry-quantity')" v-model="entry.quantity" type="text" inputmode="decimal" dir="ltr" autocomplete="off" class="form-field min-h-11 rounded-lg px-3 py-2 tabular-nums" :data-testid="fieldId('entry-quantity')" />
                            </label>

                            <button type="button" class="row-action min-h-11 rounded-lg px-4" :data-testid="fieldId('entry-add')" @click="addLine">
                                + {{ t('quotations.builder.addLine') }}
                            </button>
                        </div>
                    </div>

                    <p v-if="fieldErrors.has('lines')" class="text-[var(--color-danger)]" role="alert" :data-testid="fieldId('lines-error')">{{ fieldErrors.get('lines') }}</p>
                    <div class="table-frame overflow-x-auto rounded-xl">
                        <table class="card-table w-full text-table" :data-testid="fieldId('lines')">
                            <thead>
                                <tr class="table-head">
                                    <th scope="col" class="p-3 text-start">{{ t('quotations.detail.supplier') }}</th>
                                    <th scope="col" class="p-3 text-start">{{ t('quotations.detail.product') }}</th>
                                    <th scope="col" class="p-3 text-start">{{ t('quotations.detail.unit') }}</th>
                                    <th scope="col" class="p-3 text-end">{{ t('quotations.detail.enteredPrice') }}</th>
                                    <th v-if="taxIncluded" scope="col" class="p-3 text-end">{{ t('quotations.builder.tax_percent') }}</th>
                                    <th scope="col" class="p-3 text-end">{{ t('quotations.builder.margin_percent') }}</th>
                                    <th scope="col" class="p-3 text-end">{{ t('quotations.detail.quantity') }}</th>
                                    <th scope="col" class="p-3 text-end"><span class="sr-only">{{ t('quotations.builder.lineActions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- QS.2c: the headings show from the start; the empty row spans every live heading (7, or 8 with the tax column). -->
                                <tr v-if="lines.length === 0" :data-testid="fieldId('lines-empty')">
                                    <td :colspan="taxIncluded ? 8 : 7" class="p-3 text-center text-[var(--color-text-muted)]">{{ t('quotations.builder.noLines') }}</td>
                                </tr>
                                <tr v-for="(line, i) in lines" :key="i" class="table-row">
                                    <td :data-label="t('quotations.detail.supplier')" class="p-3" :data-testid="fieldId(`line-${i}-supplier`)">
                                        {{ line.supplier.name }}
                                        <!-- OpenAPI §5: `lines.N.<field>` names the table's row N. -->
                                        <span v-if="lineError(i) !== null" class="mt-1 block text-[var(--color-danger)]" role="alert" :data-testid="fieldId(`line-${i}-error`)">{{ lineError(i) }}</span>
                                    </td>
                                    <td :data-label="t('quotations.detail.product')" class="p-3" :data-testid="fieldId(`line-${i}-product`)">{{ line.product.name }}</td>
                                    <td :data-label="t('quotations.detail.unit')" class="p-3" :data-testid="fieldId(`line-${i}-unit`)">{{ unitLabel(line.unit) }}</td>
                                    <td :data-label="t('quotations.detail.enteredPrice')" class="p-3 text-end tabular-nums" :data-testid="fieldId(`line-${i}-price`)"><bdi dir="ltr">{{ line.price }}</bdi></td>
                                    <td v-if="taxIncluded" :data-label="t('quotations.builder.tax_percent')" class="p-3 text-end tabular-nums" :data-testid="fieldId(`line-${i}-tax`)"><bdi dir="ltr">{{ line.tax === '' ? '—' : `${line.tax}%` }}</bdi></td>
                                    <td :data-label="t('quotations.builder.margin_percent')" class="p-3 text-end tabular-nums" :data-testid="fieldId(`line-${i}-margin`)"><bdi dir="ltr">{{ line.margin === '' ? '—' : `${line.margin}%` }}</bdi></td>
                                    <td :data-label="t('quotations.detail.quantity')" class="p-3 text-end tabular-nums" :data-testid="fieldId(`line-${i}-quantity`)"><bdi dir="ltr">{{ line.quantity }}</bdi></td>
                                    <td :data-label="t('quotations.builder.lineActions')" class="p-3 text-end">
                                        <span class="inline-flex flex-wrap justify-end gap-2">
                                            <button type="button" class="row-action min-h-11 rounded-lg px-3" :aria-label="t('quotations.builder.sendBackLine', { no: i + 1 })" :data-testid="fieldId(`line-${i}-send-back`)" @click="sendBack(i)">
                                                {{ t('quotations.detail.edit') }}
                                            </button>
                                            <button type="button" class="row-action min-h-11 rounded-lg px-3" :aria-label="t('quotations.builder.removeLine', { no: i + 1 })" :data-testid="fieldId(`line-${i}-remove`)" @click="removeLine(i)">
                                                {{ t('quotations.builder.removeItem') }}
                                            </button>
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Additional items (QS.2c: under the lines): never taxed (`D-62`) — the server knows, this form only lists them. -->
                <section class="flex flex-col gap-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-card-title">{{ t('quotations.detail.additionalItems') }}</h2>
                        <button :id="fieldId('add-item')" type="button" class="row-action min-h-11 rounded-lg px-3 disabled:cursor-not-allowed disabled:opacity-60" data-testid="quotation-builder-add-item" @click="addItem">
                            + {{ t('quotations.builder.addItem') }}
                        </button>
                    </div>
                    <!-- D-159 -->
                    <p class="text-[var(--color-text-muted)]" :data-testid="fieldId('items-note')">{{ t('quotations.builder.itemsNote') }}</p>

                    <div class="table-frame overflow-x-auto rounded-xl">
                        <table class="card-table w-full text-table" :data-testid="fieldId('items')">
                            <thead>
                                <tr class="table-head">
                                    <th scope="col" class="p-3 text-start">{{ t('quotations.detail.description') }}</th>
                                    <th scope="col" class="p-3 text-end">{{ t('quotations.detail.amount') }}</th>
                                    <th scope="col" class="p-3 text-end"><span class="sr-only">{{ t('quotations.builder.lineActions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="items.length === 0" :data-testid="fieldId('items-empty')">
                                    <td colspan="3" class="p-3 text-center text-[var(--color-text-muted)]">{{ t('quotations.builder.noItems') }}</td>
                                </tr>
                                <tr v-for="(item, i) in items" :key="i" class="table-row">
                                    <td :data-label="t('quotations.detail.description')" class="p-3">
                                        <input
                                            :id="fieldId(`item-${i}-description`)"
                                            v-model="item.description"
                                            type="text"
                                            maxlength="255"
                                            autocomplete="off"
                                            :aria-label="t('quotations.detail.description')"
                                            :aria-invalid="fieldErrors.has(`additional_items.${i}.description`)"
                                            :aria-describedby="fieldErrors.has(`additional_items.${i}.description`) ? fieldId(`item-${i}-error`) : undefined"
                                            class="form-field min-h-11 w-full rounded-lg px-3 py-2"
                                            :data-testid="fieldId(`item-${i}-description`)"
                                        />
                                        <p v-if="itemError(i) !== null" class="mt-1 text-[var(--color-danger)]" role="alert" :id="fieldId(`item-${i}-error`)" :data-testid="fieldId(`item-${i}-error`)">
                                            {{ itemError(i) }}
                                        </p>
                                    </td>
                                    <td :data-label="t('quotations.detail.amount')" class="p-3 text-end">
                                        <input
                                            :id="fieldId(`item-${i}-amount`)"
                                            v-model="item.amount"
                                            type="text"
                                            inputmode="decimal"
                                            autocomplete="off"
                                            :aria-label="t('quotations.detail.amount')"
                                            :aria-invalid="fieldErrors.has(`additional_items.${i}.amount`)"
                                            :aria-describedby="fieldErrors.has(`additional_items.${i}.amount`) ? fieldId(`item-${i}-error`) : undefined"
                                            class="form-field min-h-11 w-full rounded-lg px-3 py-2 text-end tabular-nums"
                                            :data-testid="fieldId(`item-${i}-amount`)"
                                        />
                                    </td>
                                    <td :data-label="t('quotations.builder.lineActions')" class="p-3 text-end">
                                        <button type="button" class="row-action min-h-11 rounded-lg px-3 disabled:cursor-not-allowed disabled:opacity-60" :aria-label="t('quotations.builder.removeItemNo', { no: i + 1 })" :data-testid="fieldId(`item-${i}-remove`)" @click="removeItem(i)">
                                            {{ t('quotations.builder.removeItem') }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Terms (D-103): a list built like the additional items, up to 15, printed
                     numbered. A ready term (keyed) keeps its default name as the placeholder and,
                     under its text, the caller's recent terms as chips (6.8, Design System §6.3).
                     QS.2c: a divider and space set them apart from the tables above. -->
                <section class="terms-section mt-4 flex flex-col gap-3 pt-6">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-card-title">{{ t('quotations.terms.heading') }}</h2>
                        <button
                            type="button"
                            class="row-action min-h-11 rounded-lg px-3 disabled:cursor-not-allowed disabled:opacity-60"
                            :disabled="terms.length >= MAX_TERMS"
                            :id="fieldId('add-term')"
                            :data-testid="fieldId('add-term')"
                            @click="addTerm"
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
                                    :aria-describedby="fieldErrors.has(`terms.${i}.title`) ? fieldId(`term-${i}-error`) : undefined"
                                    class="form-field min-h-11 rounded-lg px-3 py-2"
                                    :data-testid="fieldId(`term-${i}-title`)"
                                />
                            </label>
                            <button type="button" class="row-action min-h-11 rounded-lg px-3" :aria-label="t('quotations.builder.removeTermNo', { no: i + 1 })" :data-testid="fieldId(`term-${i}-remove`)" @click="removeTerm(i)">
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
                                :aria-describedby="fieldErrors.has(`terms.${i}.body`) ? fieldId(`term-${i}-error`) : undefined"
                                class="form-field rounded-lg px-3 py-2"
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
                        <p v-if="termError(i) !== null" class="text-[var(--color-danger)]" role="alert" :id="fieldId(`term-${i}-error`)" :data-testid="fieldId(`term-${i}-error`)">
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

                <!-- A-20: Save stays in view on a long draft. -->
                <div class="action-bar flex flex-col gap-2 py-3" data-testid="quotation-builder-actions">
                    <!-- A-35: always present, so the removal is announced when its words arrive. -->
                    <div role="status" class="empty:hidden" data-testid="quotation-builder-undo-bar">
                        <p v-if="undo !== null" class="flex flex-wrap items-center gap-3">
                            <span>{{ t(undo.message) }}</span>
                            <button :id="fieldId('undo')" type="button" class="row-action min-h-11 rounded-lg px-3" data-testid="quotation-builder-undo" @click="restoreRemoved">
                                {{ t('quotations.builder.undo') }}
                            </button>
                        </p>
                    </div>

                    <!-- A-61: the unsaved-change question, in the page's language and direction. -->
                    <div v-if="leaving !== null" class="form-alert rounded-lg p-3" role="alertdialog" aria-labelledby="quotation-builder-unsaved-title" data-testid="quotation-builder-unsaved">
                        <p id="quotation-builder-unsaved-title">{{ t('quotations.builder.unsaved') }}</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <button ref="keepEditingButton" type="button" class="row-action min-h-11 rounded-lg px-3" data-testid="quotation-builder-keep-editing" @click="leaving = null">
                                {{ t('quotations.builder.keepEditing') }}
                            </button>
                            <button type="button" class="row-action min-h-11 rounded-lg px-3" data-testid="quotation-builder-discard" @click="discardChanges">
                                {{ t('quotations.builder.discard') }}
                            </button>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <button
                            type="submit"
                            class="save-action min-h-11 rounded-lg px-4 py-2 disabled:cursor-not-allowed disabled:opacity-60"
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
                    <!-- A-15: the server prices the draft (D-67); nothing here computes a figure. -->
                    <p class="text-[var(--color-text-muted)]" data-testid="quotation-builder-total-note">{{ t('quotations.builder.totalAfterSave') }}</p>
                </div>
            </fieldset>
        </form>
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

.terms-section {
    border-block-start: 1px solid var(--color-border);
}

/*
 * The entry row is two rows, `§7.2`'s distinct groups: what is sold (supplier, product, unit),
 * then its figures (price, tax when on, margin, quantity) with Add at its own width on the last track.
 * A phone gets one column (`§4.3`).
 */
.entry-grid {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.entry-what {
    display: grid;
    gap: 0.75rem;
    grid-template-columns: 2fr 2fr 1fr;
    align-items: end;
}

.entry-figures {
    display: grid;
    gap: 0.75rem;
    grid-template-columns: repeat(3, minmax(7rem, 1fr)) auto;
    align-items: end;
}

/* The tax label makes a fourth when the toggle is on. */
.entry-figures:has(> label:nth-of-type(4)) {
    grid-template-columns: repeat(4, minmax(7rem, 1fr)) auto;
}

@media (max-width: 639px) {
    .entry-what,
    .entry-figures,
    .entry-figures:has(> label:nth-of-type(4)) {
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

.row-link {
    color: var(--color-primary);
    text-decoration: underline;
}

/* A-20: sticky at the viewport's foot; the page's scroll padding keeps a focused field above it. */
.action-bar {
    position: sticky;
    inset-block-end: 0;
    z-index: 10;
    background-color: var(--color-canvas);
    border-block-start: 1px solid var(--color-border);
}

:global(html:has([data-testid='quotation-builder-actions'])) {
    scroll-padding-block-end: 10rem;
}

.save-action {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
}
</style>
