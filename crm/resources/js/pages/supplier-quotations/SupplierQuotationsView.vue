<script setup lang="ts">
/**
 * §8's *Supplier Quotations* screen — Design System §5.2's Table/List
 * (Module 6, Point 6.2).
 *
 * ── Everything is asked of the server, and the tests read the URL ──────────
 *
 * §5.2 requires "server-side filters/sort/search" and §6.5 that "Every list is
 * server-paginated. Do not create a UI that requires loading all records."
 * A client-side filter narrows the 25 rows in hand and silently claims to have
 * narrowed all of them, so every control here changes a parameter and asks
 * again, and the spec asserts on the query string rather than on the rows.
 *
 * The declared surface is `SupplierQuotationListCriteria`'s and it is smaller
 * than the suppliers screen's: **three** filters (`supplier_id`, `deal_id`, `deal_code`), two
 * sorts (`offer_date`, `created_at`), `DEFAULT_SORT = offer_date` **descending**
 * — and **no search at all**. `OpenAPI §6.2` answers anything else with a 400,
 * so a search box here would be a control that breaks the screen.
 *
 * ── §3.6 has no scope, so this screen has no scope story ───────────────────
 *
 * §3.6 grants `Scope::All` in every column it fills, under "a shared screen —
 * not restricted by ownership". Everyone who reaches this screen sees every
 * offer, so an empty list means the table is empty and never "none of them are
 * yours".
 *
 * ── The sidebar follows §3.6, not §8 (owner's ruling, 2026-08-31) ──────────
 *
 * §8 lists this screen for the Manager, Team Leader, Outdoor Sales, Indoor
 * Sales and Procurement — and **not** for the CEO, while §3.6 grants the CEO
 * `supplier_quotation.view` as `All`. This is the same conflict the Suppliers
 * and Catalog screens hit, and it takes the same answer the owner gave on
 * 2026-08-31: the route and the nav item both follow the permission matrix,
 * because keying the menu on §8 would leave a screen a person may open with no
 * way to reach it. Recorded in `CHECKLIST.md`, **awaiting a `D-xx`**. `SEC-09`
 * is unaffected — the API is still the gate.
 *
 * ── The supplier is resolved to a name; the currency cannot be ─────────────
 *
 * The payload carries `supplier_id`, never a name: `CLAUDE.md` forbids Module 6
 * reading Module 4's tables, so the join belongs on this side of the wire
 * (`D-67`). One `listSuppliers` call fills both the name column and the filter.
 *
 * ⚠️ **Two stated ceilings, neither invented here.**
 * 1. That call takes the first 100 suppliers (`SupplierListCriteria::MAX_PER_PAGE`).
 *    A supplier past the hundredth shows as their identifier rather than a name.
 * 2. **The currency is not shown in the list.** The dialog can read it since
 *    D-80 (`GET /currencies` carries `id`, readable by `currency.view`); the
 *    column is a later item if the owner wants it — a bare figure here is
 *    still honest, a wrong currency beside it would not be.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import EmptyState from '@/components/states/EmptyState.vue';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import SupplierQuotationFormModal from '@/pages/supplier-quotations/SupplierQuotationFormModal.vue';
import { listSupplierQuotations, type Pagination, type SupplierQuotation } from '@/services/supplier-quotations';
import { listSuppliers, type Supplier } from '@/services/suppliers';
import { displayDecimals } from '@/domain/displayDecimals';
import { useAuth } from '@/stores/auth';

const { t, locale } = useI18n();
const auth = useAuth();

/**
 * §3.6's write cell is one grant, and the route carries it on both the POST and
 * the PATCH — so one computed draws both controls. §6.2: "one primary action
 * per context, drawn only for the permission that can complete it."
 */
const canWrite = computed(() => auth.hasPermission('supplier_quotation.create'));

const formOpen = ref(false);
const editing = ref<SupplierQuotation | null>(null);

const offers = ref<SupplierQuotation[]>([]);
const suppliers = ref<Supplier[]>([]);
const pagination = ref<Pagination | null>(null);

const loading = ref(true);
const failed = ref(false);
const denied = ref(false);

const page = ref(1);
const supplierFilter = ref('');
const dealFilter = ref('');

const sortField = ref<'offer_date' | 'created_at'>('offer_date');
/** `DEFAULT_SORT_DESCENDING` is true — newest offer first. */
const sortDescending = ref(true);

const total = computed(() => pagination.value?.total ?? 0);
const filtering = computed(() => supplierFilter.value !== '' || dealFilter.value !== '');
const sortParameter = computed(() => `${sortDescending.value ? '-' : ''}${sortField.value}`);

const supplierNames = computed(() => {
    const names = new Map<string, string>();

    for (const supplier of suppliers.value) {
        names.set(supplier.id, supplier.name);
    }

    return names;
});

/** The identifier is the fallback, not a blank: a row that cannot be named is still a row. */
function supplierName(id: string): string {
    return supplierNames.value.get(id) ?? id;
}

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    denied.value = false;

    try {
        const result = await listSupplierQuotations({
            page: page.value,
            sort: sortParameter.value,
            supplierId: supplierFilter.value === '' ? null : supplierFilter.value,
            dealCode: dealFilter.value === '' ? null : dealFilter.value,
        });

        offers.value = result.items;
        pagination.value = result.pagination;
    } catch (error) {
        // A 403 and a 500 are different answers and get different screens: one
        // is a boundary and the other is a fault.
        denied.value = error instanceof ApiError && error.status === 403;
        failed.value = !denied.value;
    } finally {
        loading.value = false;
    }
}

/**
 * The supplier names, best-effort. A failure here must not blank the screen:
 * §3.7 gates suppliers separately, so a caller may legitimately read offers and
 * not suppliers — and then the identifier column is the correct answer rather
 * than an error page over a list that loaded perfectly well.
 */
async function loadSuppliers(): Promise<void> {
    try {
        suppliers.value = (await listSuppliers({ perPage: 100 })).items;
    } catch {
        suppliers.value = [];
    }
}

/** Any change to the question invalidates the page number. */
async function applyFilters(): Promise<void> {
    page.value = 1;
    await load();
}

async function sortBy(field: 'offer_date' | 'created_at'): Promise<void> {
    if (sortField.value === field) {
        sortDescending.value = !sortDescending.value;
    } else {
        sortField.value = field;
        sortDescending.value = true;
    }

    page.value = 1;
    await load();
}

async function goToPage(target: number): Promise<void> {
    page.value = target;
    await load();
}

function ariaSort(field: string): 'ascending' | 'descending' | 'none' {
    if (sortField.value !== field) {
        return 'none';
    }

    return sortDescending.value ? 'descending' : 'ascending';
}

function sortIndicator(field: string): string {
    if (sortField.value !== field) {
        return '';
    }

    return sortDescending.value ? '▼' : '▲';
}

/** `DB-08`: stored UTC, shown in the reader's locale. A date-only field has no zone to shift. */
function onDate(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return new Date(value).toLocaleDateString(locale.value === 'ar' ? 'ar-EG' : 'en-GB');
}

function startCreate(): void {
    editing.value = null;
    formOpen.value = true;
}

function startEdit(offer: SupplierQuotation): void {
    editing.value = offer;
    formOpen.value = true;
}

/**
 * The saved offer may no longer belong on the page in view — a changed
 * `offer_date` moves it under the default sort, a changed supplier drops it out
 * of `filter[supplier_id]` — so the list is asked again rather than patched in
 * place (§5.2, §6.5).
 */
async function onSaved(): Promise<void> {
    formOpen.value = false;

    await load();
}

onMounted(async () => {
    await Promise.all([load(), loadSuppliers()]);
});
</script>

<template>
    <section class="flex flex-col gap-4">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-page-title">{{ t('supplierQuotations.title') }}</h1>

            <p
                v-if="!loading && !failed && !denied"
                data-testid="supplier-quotations-count"
                class="tabular-nums text-[var(--color-text-muted)]"
            >
                {{ t('supplierQuotations.total', { count: total }) }}
            </p>

            <!-- §6.2: one primary action per context, drawn only for the
                 permission that can complete it. -->
            <button
                v-if="canWrite"
                type="button"
                class="create-action min-h-11 rounded-lg px-4 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                data-testid="supplier-quotations-create"
                @click="startCreate()"
            >
                {{ t('supplierQuotations.form.createTitle') }}
            </button>
        </header>

        <form class="flex flex-wrap items-end gap-3" data-testid="supplier-quotations-filters" @submit.prevent="applyFilters">
            <label class="flex flex-col gap-1">
                <span>{{ t('supplierQuotations.filter.supplier') }}</span>
                <select
                    v-model="supplierFilter"
                    class="form-field min-h-11 rounded-lg px-3"
                    data-testid="supplier-quotations-filter-supplier"
                    @change="applyFilters"
                >
                    <option value="">{{ t('supplierQuotations.filter.supplierAll') }}</option>
                    <option v-for="supplier in suppliers" :key="supplier.id" :value="supplier.id">{{ supplier.name }}</option>
                </select>
            </label>

            <!-- `D-88`: a fragment of the deal's code, matched by the server.
                 A deal picker is outside F-13. -->
            <label class="flex flex-col gap-1">
                <span>{{ t('supplierQuotations.filter.deal') }}</span>
                <input
                    v-model="dealFilter"
                    type="text"
                    class="form-field min-h-11 rounded-lg px-3"
                    :placeholder="t('supplierQuotations.filter.dealCodePlaceholder')"
                    data-testid="supplier-quotations-filter-deal"
                />
            </label>

            <button
                type="submit"
                class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                data-testid="supplier-quotations-filter-apply"
            >
                {{ t('supplierQuotations.filter.apply') }}
            </button>
        </form>

        <LoadingState v-if="loading" label-key="supplierQuotations.loading" />
        <PermissionDeniedState v-else-if="denied" />
        <ErrorState v-else-if="failed" @retry="load" />
        <EmptyState
            v-else-if="offers.length === 0"
            :title-key="filtering ? 'supplierQuotations.empty.filtered.title' : 'state.empty.title'"
            :message-key="filtering ? 'supplierQuotations.empty.filtered.message' : 'state.empty.message'"
        />

        <div v-else class="flex flex-col gap-3">
            <div class="table-frame overflow-x-auto rounded-xl">
                <table class="w-full text-table" data-testid="supplier-quotations-table">
                    <thead class="sticky top-0">
                        <tr class="table-head">
                            <th scope="col" class="p-3 text-start">{{ t('supplierQuotations.column.code') }}</th>
                            <th scope="col" class="p-3 text-start">{{ t('supplierQuotations.column.supplier') }}</th>

                            <!-- §6.5: "Right-align monetary values" — `text-end`
                                 rather than `text-right`, so RTL mirrors. -->
                            <th scope="col" class="p-3 text-end">{{ t('supplierQuotations.column.total') }}</th>

                            <th
                                scope="col"
                                class="p-3 text-start"
                                :aria-sort="ariaSort('offer_date')"
                                data-testid="supplier-quotations-column-offer_date"
                            >
                                <button
                                    type="button"
                                    class="sort-action min-h-11 rounded-lg px-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                    data-testid="supplier-quotations-sort-offer_date"
                                    @click="sortBy('offer_date')"
                                >
                                    {{ t('supplierQuotations.column.offerDate') }}
                                    <span aria-hidden="true">{{ sortIndicator('offer_date') }}</span>
                                </button>
                            </th>

                            <!-- §5.2's column priorities: secondary columns fold first. -->
                            <th scope="col" class="hidden p-3 text-start md:table-cell">
                                {{ t('supplierQuotations.column.validUntil') }}
                            </th>
                            <th scope="col" class="hidden p-3 text-start lg:table-cell">
                                {{ t('supplierQuotations.column.deal') }}
                            </th>
                            <th v-if="canWrite" scope="col" class="p-3 text-start">
                                <span class="sr-only">{{ t('action.edit') }}</span>
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-for="offer in offers" :key="offer.id" class="table-row" data-testid="supplier-quotations-row">
                            <td class="p-3 tabular-nums">{{ offer.code }}</td>
                            <td class="p-3" data-testid="supplier-quotations-supplier">{{ supplierName(offer.supplier_id) }}</td>

                            <!-- The string the server sent, cut after the third decimal (`D-82`) —
                                 a string operation: `DB-07` forbids the float a number
                                 conversion would create. The input keeps every digit. -->
                            <td class="p-3 text-end tabular-nums" data-testid="supplier-quotations-total">
                                {{ displayDecimals(offer.total_price) ?? '—' }}
                            </td>

                            <td class="p-3 tabular-nums">{{ onDate(offer.offer_date) }}</td>
                            <td class="hidden p-3 tabular-nums md:table-cell">{{ onDate(offer.valid_until) }}</td>
                            <td class="hidden p-3 lg:table-cell">
                                {{ offer.deal_code ?? t('supplierQuotations.deal.none') }}
                            </td>

                            <td v-if="canWrite" class="p-3">
                                <button
                                    type="button"
                                    class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                    data-testid="supplier-quotations-row-edit"
                                    @click="startEdit(offer)"
                                >
                                    {{ t('action.edit') }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav
                v-if="pagination"
                class="flex items-center justify-between gap-3"
                :aria-label="t('supplierQuotations.pagination.label')"
                data-testid="supplier-quotations-pagination"
            >
                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!pagination.has_previous_page || loading"
                    data-testid="supplier-quotations-previous"
                    @click="goToPage(pagination.page - 1)"
                >
                    {{ t('supplierQuotations.pagination.previous') }}
                </button>

                <p class="tabular-nums text-[var(--color-text-muted)]">
                    {{ t('supplierQuotations.pagination.position', { page: pagination.page, pages: pagination.total_pages, total: pagination.total }) }}
                </p>

                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!pagination.has_next_page || loading"
                    data-testid="supplier-quotations-next"
                    @click="goToPage(pagination.page + 1)"
                >
                    {{ t('supplierQuotations.pagination.next') }}
                </button>
            </nav>
        </div>

        <SupplierQuotationFormModal
            :open="formOpen"
            :editing="editing"
            :suppliers="suppliers"
            @saved="onSaved"
            @cancel="formOpen = false"
        />
    </section>
</template>

<style scoped>
.table-frame {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border);
}

.table-head {
    background-color: var(--color-surface-muted);
    color: var(--color-text-muted);
}

.table-row {
    border-block-start: 1px solid var(--color-border);
}

/* §6.5: "a clear row focus/selection state". */
.table-row:hover,
.table-row:focus-within {
    background-color: var(--color-surface-muted);
}

.form-field {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.sort-action {
    color: inherit;
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
