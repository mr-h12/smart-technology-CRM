<script setup lang="ts">
/**
 * Module 7, Point 6.6 — the builder, create (`/quotations/new?deal=`).
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
 * so on a 201 that carries warnings the form stays, read-only, with the red
 * line and a link to the saved draft; a clean 201 goes straight to it. A
 * `GET` never repeats this warning (Point 4.5 reads `supplier_price_changed`
 * only), so navigating away would lose it.
 *
 * ── Idempotency ────────────────────────────────────────────────────────────
 * `OpenAPI §9.1`: one `Idempotency-Key` per form open. A retry after a
 * failure replays the same key; a fresh form mints a fresh one.
 *
 * Stated ceilings, all Module 6's: suppliers, catalog items and supplier
 * quotations are each read as one page of 100.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { ApiError } from '@/api';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import SupplierQuotationFormModal from '@/pages/supplier-quotations/SupplierQuotationFormModal.vue';
import { listCatalogItems, type CatalogItem } from '@/services/catalog';
import { readCustomer, type Customer } from '@/services/customers';
import { readDeal, type Deal } from '@/services/deals';
import { createQuotation, type QuotationCreateDraft, type QuotationWarning } from '@/services/quotations';
import {
    listSupplierQuotations,
    readSupplierQuotation,
    type SupplierQuotation,
    type SupplierQuotationDetail,
} from '@/services/supplier-quotations';
import { listSuppliers, type Supplier } from '@/services/suppliers';
import { useAuth } from '@/stores/auth';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const { hasPermission } = useAuth();

/** §6.2's cap. */
const MAX_SUPPLIERS = 10;

const HEADER_FIELDS = ['currency', 'default_margin', 'discount_percent', 'tax_percent', 'quotation_date', 'valid_until', 'payment_terms', 'warranty', 'delivery_terms'] as const;

type HeaderField = (typeof HEADER_FIELDS)[number];

interface Block {
    supplierQuotationId: string;
    detail: SupplierQuotationDetail | null;
    /** Per line of `detail.items`, what was typed. Empty means "not on this quotation". */
    quantities: string[];
    margins: string[];
}

const dealId = computed(() => String(route.query.deal ?? ''));

const deal = ref<Deal | null>(null);
const customer = ref<Customer | null>(null);
const suppliers = ref<Supplier[]>([]);
const catalog = ref<CatalogItem[]>([]);
const offers = ref<SupplierQuotation[]>([]);

const loading = ref(true);
const failed = ref(false);
const denied = ref(false);
const missing = ref(false);

const header = ref<Record<HeaderField, string>>({
    currency: '', default_margin: '', discount_percent: '0', tax_percent: '',
    quotation_date: '', valid_until: '', payment_terms: '', warranty: '', delivery_terms: '',
});
const showDeliveryTerms = ref(true);
const blocks = ref<Block[]>([]);
const items = ref<Array<{ description: string; amount: string }>>([]);

/** `OpenAPI §9.1`: minted once per form open. */
const idempotencyKey = crypto.randomUUID();

const saving = ref(false);
const formError = ref('');
/** The server's sentence per request field — `discount_percent`, `lines.1.quantity`, `additional_items.0.amount`. */
const fieldErrors = ref<Map<string, string>>(new Map());
/** Which (block, line) each sent `lines[i]` came from, so a server index maps back to an input. */
let sent: Array<[number, number]> = [];
/** The 201 that carried warnings: the draft is saved, the form is closed, the link is on. */
const saved = ref<{ id: string; code: string } | null>(null);
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

function offerLabel(offer: SupplierQuotation): string {
    return `${offer.code} — ${supplierName(offer.supplier_id)}`;
}

/** The request's index of a block's line, or null when it was not sent. */
function sentIndex(block: number, line: number): number | null {
    const index = sent.findIndex(([b, l]) => b === block && l === line);

    return index === -1 ? null : index;
}

function lineError(block: number, line: number): string | null {
    const index = sentIndex(block, line);

    if (index === null) {
        return null;
    }

    return fieldErrors.value.get(`lines.${index}.quantity`)
        ?? fieldErrors.value.get(`lines.${index}.supplier_quotation_item_id`)
        ?? fieldErrors.value.get(`lines.${index}.margin_percent`)
        ?? null;
}

function lineWarning(block: number, line: number): string | null {
    const index = sentIndex(block, line);

    return index === null ? null : (lineWarnings.value.get(String(index)) ?? null);
}

function itemError(index: number): string | null {
    return fieldErrors.value.get(`additional_items.${index}.description`)
        ?? fieldErrors.value.get(`additional_items.${index}.amount`)
        ?? null;
}

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    denied.value = false;
    missing.value = dealId.value === '';

    if (missing.value) {
        loading.value = false;

        return;
    }

    try {
        deal.value = await readDeal(dealId.value);
    } catch (error) {
        const status = error instanceof ApiError ? error.status : 0;

        // `OpenAPI §5.1`: a 404 says the record could not be opened and never
        // which of the two reasons applies.
        missing.value = status === 404;
        denied.value = status === 403;
        failed.value = !missing.value && !denied.value;
        loading.value = false;

        return;
    }

    // Best-effort, exactly as the detail's own name lookups are: a refused
    // lookup leaves an identifier on screen, not an error page over a form.
    await Promise.all([
        readCustomer(deal.value.customer_id).then((found) => { customer.value = found; }, () => {}),
        listSuppliers({ perPage: 100 }).then((page) => { suppliers.value = page.items; }, () => {}),
        listCatalogItems({ perPage: 100, isActive: true }).then((page) => { catalog.value = page.items; }, () => {}),
        loadOffers(),
    ]);

    loading.value = false;
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

function orNull(value: string): string | null {
    return value.trim() === '' ? null : value;
}

function draft(): QuotationCreateDraft {
    const current = deal.value as Deal;
    const lines: QuotationCreateDraft['lines'] = [];

    sent = [];

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
            sent.push([b, l]);
        });
    });

    return {
        deal_id: current.id,
        customer_id: current.customer_id,
        currency: header.value.currency.trim().toUpperCase(),
        default_margin: header.value.default_margin,
        discount_percent: header.value.discount_percent,
        tax_percent: orNull(header.value.tax_percent),
        quotation_date: orNull(header.value.quotation_date),
        valid_until: orNull(header.value.valid_until),
        payment_terms: orNull(header.value.payment_terms),
        warranty: orNull(header.value.warranty),
        delivery_terms: orNull(header.value.delivery_terms),
        show_delivery_terms: showDeliveryTerms.value,
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
        const result = await createQuotation(draft(), idempotencyKey);

        if (result.warnings.length === 0) {
            await router.push({ name: 'quotation-detail', params: { id: result.quotation.id } });

            return;
        }

        onWarnings(result.warnings);
        saved.value = { id: result.quotation.id, code: result.quotation.code };
    } catch (error) {
        applyServerErrors(error);
    } finally {
        saving.value = false;
    }
}

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
                <h1 class="text-page-title">{{ t('quotations.builder.title') }}</h1>
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

            <!-- Q3: the draft is saved; the quotation's own page is the confirmation. -->
            <p v-if="saved !== null" class="form-saved rounded-lg p-3" role="status" data-testid="quotation-builder-saved">
                {{ t('quotations.builder.saved', { code: saved.code }) }}
                <RouterLink :to="{ name: 'quotation-detail', params: { id: saved.id } }" class="row-link" data-testid="quotation-builder-saved-link">
                    {{ t('quotations.builder.open') }}
                </RouterLink>
            </p>

            <fieldset class="contents" :disabled="saving || saved !== null">
                <!-- `Design System §7.2`: margin, discount and tax in their own group; §4.3 grid. -->
                <div class="detail-grid rounded-xl p-4">
                    <label class="flex flex-col gap-1.5" :for="fieldId('currency')">
                        <span>{{ t('quotations.builder.currency') }} *</span>
                        <input
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
                                    <!-- The supplier's own figures — internal cost, never a customer price (§7.2). -->
                                    <span class="text-[var(--color-text-muted)] tabular-nums" :data-testid="fieldId(`line-${b}-${l}-recorded`)">
                                        {{ t('quotations.builder.recorded', { price: item.unit_price, quantity: item.quantity }) }}
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
                                        :aria-invalid="lineError(b, l) !== null"
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
                                        inputmode="decimal"
                                        autocomplete="off"
                                        class="form-field min-h-11 rounded-lg px-3 py-2 tabular-nums focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                        :data-testid="fieldId(`line-${b}-${l}-margin_percent`)"
                                    />
                                </label>
                                <!-- §5.6: a missing price blocks; an excess quantity is red and does not. -->
                                <p v-if="lineError(b, l) !== null" class="line-note text-[var(--color-danger)]" role="alert" :data-testid="fieldId(`line-${b}-${l}-error`)">
                                    {{ lineError(b, l) }}
                                </p>
                                <p v-else-if="lineWarning(b, l) !== null" class="line-note warning-line rounded-lg p-2" :data-testid="fieldId(`line-${b}-${l}-warning`)">
                                    {{ lineWarning(b, l) }}
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

                <!-- Terms: plain textareas until 6.8's SmartTermInput. -->
                <div class="detail-grid rounded-xl p-4">
                    <label v-for="field in (['payment_terms', 'warranty', 'delivery_terms'] as const)" :key="field" class="flex flex-col gap-1.5" :for="fieldId(field)">
                        <span>{{ t(`quotations.builder.${field}`) }}</span>
                        <textarea
                            :id="fieldId(field)"
                            v-model="header[field]"
                            rows="3"
                            class="form-field rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                            :data-testid="fieldId(field)"
                        ></textarea>
                        <span v-if="fieldErrors.has(field)" class="text-[var(--color-danger)]" :data-testid="fieldId(`${field}-error`)">
                            {{ fieldErrors.get(field) }}
                        </span>
                    </label>
                    <label class="flex items-center gap-2 self-end" :for="fieldId('show_delivery_terms')">
                        <input :id="fieldId('show_delivery_terms')" v-model="showDeliveryTerms" type="checkbox" class="size-5" :data-testid="fieldId('show_delivery_terms')" />
                        <span>{{ t('quotations.builder.showDeliveryTerms') }}</span>
                    </label>
                </div>

                <div v-if="saved === null" class="flex flex-wrap gap-2">
                    <button
                        type="submit"
                        class="save-action min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="saving"
                        data-testid="quotation-builder-save"
                    >
                        {{ saving ? t('quotations.builder.saving') : t('quotations.builder.save') }}
                    </button>
                    <RouterLink :to="{ name: 'deal-detail', params: { id: deal.id } }" class="row-action inline-flex min-h-11 items-center rounded-lg px-3">
                        {{ t('quotations.builder.cancel') }}
                    </RouterLink>
                </div>
            </fieldset>
        </form>

        <SupplierQuotationFormModal
            :open="offerFormOpen"
            :editing="null"
            :suppliers="suppliers"
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
