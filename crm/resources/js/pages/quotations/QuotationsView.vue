<script setup lang="ts">
/**
 * §8's *Quotations* screen, flat — `Design System §5.2`'s Table/List
 * (Module 7, Point 6.3). §6.6's toggle and split are Point 6.4.
 *
 * ── Everything is asked of the server, and the tests read the URL ──────────
 *
 * §5.2 requires "server-side filters/sort" and §6.5 that "Every list is
 * server-paginated", so every control here changes a parameter and asks again.
 * The declared surface is `QuotationListCriteria`'s: §6.6's filters (`status`,
 * period as `from`/`to` on `quotation_date`, `customer_id`, `currency`,
 * `amount_min`/`amount_max`), five sorts, `-updated_at` by default.
 *
 * ── Two of §6.6's controls are not here, and the reason is stated ──────────
 *
 * 1. **The amount pair is disabled until a currency is set**, and the total
 *    column sorts only then: a quotation carries one currency and no base
 *    total, so a range or an order across currencies ranks EGP against USD.
 *    The server refuses both without `filter[currency]` (Step 5 Q3), and a
 *    control that can only produce a 400 is not a control.
 * 2. **There is no employee filter yet.** `filter[employee]` takes a user id,
 *    and nothing this role may call turns a name into one — `GET /users` is
 *    `admin.create_user`'s. Step 6 Q2 puts the employee's name on the server's
 *    `group_by=employee` label, so 6.4's grouped view is where a person picks
 *    an employee; a text box for a UUID would be a filter nobody can use.
 *
 * ── Names come from the wire, or not at all ────────────────────────────────
 *
 * The row carries `customer_id` and no name — `CLAUDE.md` forbids Quotations
 * reading Customers' tables, so the join is on this side (`D-67`), from one
 * `listCustomers({ perPage: 100 })` — the same measured ceiling Deals and
 * Module 6 record; a customer past the hundredth shows as an identifier. The
 * currency is different: the row names its code, because `GET /currencies` is
 * an admin's and the SPA has nothing to join against (owner's ruling A,
 * 2026-09-13).
 *
 * ── The route is the permission's, not §8's ────────────────────────────────
 *
 * §3.5 grants `quotation.view` to every role but the Outdoor Supervisor; the
 * route and the nav item key on it (the owner's ruling of 2026-08-31, as every
 * list before this one). `SEC-09` stands: the API is the gate, and a Team
 * Leader whose `Team` scope resolves to no rows is answered with an empty page
 * that says "none are visible to you", not "there are none".
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError, type Pagination } from '@/api';
import EmptyState from '@/components/states/EmptyState.vue';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import { listCustomers, type Customer } from '@/services/customers';
import {
    QUOTATION_DEFAULT_SORT,
    QUOTATION_STATUSES,
    listQuotations,
    type QuotationSummary,
} from '@/services/quotations';
import QuotationStatusChip from '@/pages/quotations/QuotationStatusChip.vue';

const { t, locale } = useI18n();

type SortField = 'code' | 'quotation_date' | 'updated_at' | 'final_total';

const quotations = ref<QuotationSummary[]>([]);
const customers = ref<Customer[]>([]);
const pagination = ref<Pagination | null>(null);

const loading = ref(true);
const failed = ref(false);
const denied = ref(false);

const page = ref(1);
const statusFilter = ref('');
const customerFilter = ref('');
const fromFilter = ref('');
const toFilter = ref('');
const currencyFilter = ref('');
const amountMinFilter = ref('');
const amountMaxFilter = ref('');

const sortField = ref<SortField>(QUOTATION_DEFAULT_SORT);
/** The server's own default is `-updated_at`: the last touched first. */
const sortDescending = ref(true);

const statuses = QUOTATION_STATUSES;

const total = computed(() => pagination.value?.total ?? 0);
/** `CurrencyCode` is upper-case ISO; the box accepts what a person types. */
const currencyCode = computed(() => currencyFilter.value.trim().toUpperCase());
const hasCurrency = computed(() => /^[A-Z]{3}$/.test(currencyCode.value));
const sortParameter = computed(() => `${sortDescending.value ? '-' : ''}${sortField.value}`);
const filtering = computed(
    () =>
        statusFilter.value !== '' ||
        customerFilter.value !== '' ||
        fromFilter.value !== '' ||
        toFilter.value !== '' ||
        hasCurrency.value,
);

const customerNames = computed(() => {
    const names = new Map<string, string>();

    for (const customer of customers.value) {
        names.set(customer.id, customer.name);
    }

    return names;
});

/** The identifier is the fallback, not a blank: a row that cannot be named is still a row. */
function customerName(id: string): string {
    return customerNames.value.get(id) ?? id;
}

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    denied.value = false;

    try {
        const result = await listQuotations({
            page: page.value,
            sort: sortParameter.value,
            status: statusFilter.value === '' ? null : statusFilter.value,
            customerId: customerFilter.value === '' ? null : customerFilter.value,
            from: fromFilter.value === '' ? null : fromFilter.value,
            to: toFilter.value === '' ? null : toFilter.value,
            currency: hasCurrency.value ? currencyCode.value : null,
            // Step 5 Q3: the pair travels only with its currency.
            amountMin: hasCurrency.value && amountMinFilter.value !== '' ? amountMinFilter.value : null,
            amountMax: hasCurrency.value && amountMaxFilter.value !== '' ? amountMaxFilter.value : null,
        });

        quotations.value = result.items;
        pagination.value = result.pagination;
    } catch (error) {
        // A 403 and a 500 are different answers and get different screens
        // (`SEC-09`): a refusal drawn as an empty list would read as "there
        // are no quotations", which is a lie.
        denied.value = error instanceof ApiError && error.status === 403;
        failed.value = !denied.value;
    } finally {
        loading.value = false;
    }
}

/**
 * Best-effort: §3.3 gates customers separately, so a caller may read
 * quotations and not customers — then the identifier column is the answer,
 * not an error page over a list that loaded.
 */
async function loadCustomers(): Promise<void> {
    try {
        customers.value = (await listCustomers({ perPage: 100 })).items;
    } catch {
        customers.value = [];
    }
}

/** Any change to the question invalidates the page number. */
async function applyFilters(): Promise<void> {
    page.value = 1;

    // A total sort without a currency is the 400 Step 5 Q3 describes; fall
    // back to the default rather than ask a question the server refuses.
    if (sortField.value === 'final_total' && !hasCurrency.value) {
        sortField.value = QUOTATION_DEFAULT_SORT;
        sortDescending.value = true;
    }

    await load();
}

async function sortBy(field: SortField): Promise<void> {
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

function ariaSort(field: SortField): 'ascending' | 'descending' | 'none' {
    if (sortField.value !== field) {
        return 'none';
    }

    return sortDescending.value ? 'descending' : 'ascending';
}

function sortIndicator(field: SortField): string {
    if (sortField.value !== field) {
        return '';
    }

    return sortDescending.value ? '▼' : '▲';
}

/** `DB-08`: stored UTC, shown in the reader's locale. A date-only field is shown as it was stored. */
function onDate(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return new Date(value).toLocaleDateString(locale.value === 'ar' ? 'ar-EG' : 'en-GB');
}

onMounted(async () => {
    await Promise.all([load(), loadCustomers()]);
});
</script>

<template>
    <section class="flex flex-col gap-4">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-page-title">{{ t('quotations.title') }}</h1>

            <p
                v-if="!loading && !failed && !denied"
                data-testid="quotations-count"
                class="tabular-nums text-[var(--color-text-muted)]"
            >
                {{ t('quotations.total', { count: total }) }}
            </p>
        </header>

        <form class="flex flex-wrap items-end gap-3" data-testid="quotations-filters" @submit.prevent="applyFilters">
            <label class="flex flex-col gap-1">
                <span>{{ t('quotations.filter.status') }}</span>
                <select
                    v-model="statusFilter"
                    class="form-field min-h-11 rounded-lg px-3"
                    data-testid="quotations-filter-status"
                    @change="applyFilters"
                >
                    <option value="">{{ t('quotations.filter.statusAll') }}</option>
                    <option v-for="code in statuses" :key="code" :value="code">{{ t(`quotations.status.${code}`) }}</option>
                </select>
            </label>

            <label class="flex flex-col gap-1">
                <span>{{ t('quotations.filter.customer') }}</span>
                <select
                    v-model="customerFilter"
                    class="form-field min-h-11 rounded-lg px-3"
                    data-testid="quotations-filter-customer"
                    @change="applyFilters"
                >
                    <option value="">{{ t('quotations.filter.customerAll') }}</option>
                    <option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}</option>
                </select>
            </label>

            <!-- §6.6's "period" — `quotation_date`, inclusive (Step 5 Q4). -->
            <label class="flex flex-col gap-1">
                <span>{{ t('quotations.filter.from') }}</span>
                <input
                    v-model="fromFilter"
                    type="date"
                    class="form-field min-h-11 rounded-lg px-3"
                    data-testid="quotations-filter-from"
                    @change="applyFilters"
                />
            </label>

            <label class="flex flex-col gap-1">
                <span>{{ t('quotations.filter.to') }}</span>
                <input
                    v-model="toFilter"
                    type="date"
                    class="form-field min-h-11 rounded-lg px-3"
                    data-testid="quotations-filter-to"
                    @change="applyFilters"
                />
            </label>

            <!-- A code, typed: `GET /currencies` is an admin's, so there is no
                 list to choose from. Three letters, sent upper-case. -->
            <label class="flex flex-col gap-1">
                <span>{{ t('quotations.filter.currency') }}</span>
                <input
                    v-model="currencyFilter"
                    type="text"
                    maxlength="3"
                    autocapitalize="characters"
                    class="form-field min-h-11 w-24 rounded-lg px-3 uppercase"
                    :placeholder="t('quotations.filter.currencyPlaceholder')"
                    data-testid="quotations-filter-currency"
                />
            </label>

            <label class="flex flex-col gap-1">
                <span>{{ t('quotations.filter.amountMin') }}</span>
                <input
                    v-model="amountMinFilter"
                    type="text"
                    inputmode="decimal"
                    class="form-field min-h-11 w-32 rounded-lg px-3 tabular-nums disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!hasCurrency"
                    data-testid="quotations-filter-amount-min"
                />
            </label>

            <label class="flex flex-col gap-1">
                <span>{{ t('quotations.filter.amountMax') }}</span>
                <input
                    v-model="amountMaxFilter"
                    type="text"
                    inputmode="decimal"
                    class="form-field min-h-11 w-32 rounded-lg px-3 tabular-nums disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!hasCurrency"
                    data-testid="quotations-filter-amount-max"
                />
            </label>

            <button
                type="submit"
                class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                data-testid="quotations-filter-apply"
            >
                {{ t('quotations.filter.apply') }}
            </button>

            <!-- Why the pair and the total sort are off: said once, in words,
                 rather than hidden in a tooltip a keyboard never reaches. -->
            <p v-if="!hasCurrency" class="basis-full text-[var(--color-text-muted)]" data-testid="quotations-amount-hint">
                {{ t('quotations.filter.amountNeedsCurrency') }}
            </p>
        </form>

        <LoadingState v-if="loading" label-key="quotations.loading" />
        <PermissionDeniedState v-else-if="denied" />
        <ErrorState v-else-if="failed" @retry="load" />
        <EmptyState
            v-else-if="quotations.length === 0"
            :title-key="filtering ? 'quotations.empty.filtered.title' : 'quotations.empty.title'"
            :message-key="filtering ? 'quotations.empty.filtered.message' : 'quotations.empty.message'"
        />

        <div v-else class="flex flex-col gap-3">
            <div class="table-frame overflow-x-auto rounded-xl">
                <table class="w-full text-table" data-testid="quotations-table">
                    <thead class="sticky top-0">
                        <tr class="table-head">
                            <th scope="col" class="p-3 text-start" :aria-sort="ariaSort('code')" data-testid="quotations-column-code">
                                <button
                                    type="button"
                                    class="sort-action min-h-11 rounded-lg px-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                    data-testid="quotations-sort-code"
                                    @click="sortBy('code')"
                                >
                                    {{ t('quotations.column.code') }}
                                    <span aria-hidden="true">{{ sortIndicator('code') }}</span>
                                </button>
                            </th>
                            <th scope="col" class="p-3 text-start">{{ t('quotations.column.version') }}</th>
                            <th scope="col" class="p-3 text-start">{{ t('quotations.column.status') }}</th>
                            <th scope="col" class="p-3 text-start">{{ t('quotations.column.customer') }}</th>

                            <!-- §6.5: money right-aligned by logical `text-end`, tabular numerals. -->
                            <th scope="col" class="p-3 text-end" :aria-sort="ariaSort('final_total')" data-testid="quotations-column-final_total">
                                <button
                                    type="button"
                                    class="sort-action min-h-11 rounded-lg px-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                                    :disabled="!hasCurrency"
                                                    data-testid="quotations-sort-final_total"
                                    @click="sortBy('final_total')"
                                >
                                    {{ t('quotations.column.total') }}
                                    <span aria-hidden="true">{{ sortIndicator('final_total') }}</span>
                                </button>
                            </th>

                            <th scope="col" class="hidden p-3 text-start md:table-cell" :aria-sort="ariaSort('quotation_date')" data-testid="quotations-column-quotation_date">
                                <button
                                    type="button"
                                    class="sort-action min-h-11 rounded-lg px-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                    data-testid="quotations-sort-quotation_date"
                                    @click="sortBy('quotation_date')"
                                >
                                    {{ t('quotations.column.quotationDate') }}
                                    <span aria-hidden="true">{{ sortIndicator('quotation_date') }}</span>
                                </button>
                            </th>

                            <!-- §5.2's column priorities: secondary columns fold first. -->
                            <th scope="col" class="hidden p-3 text-start lg:table-cell">{{ t('quotations.column.validUntil') }}</th>

                            <th scope="col" class="hidden p-3 text-start lg:table-cell" :aria-sort="ariaSort('updated_at')" data-testid="quotations-column-updated_at">
                                <button
                                    type="button"
                                    class="sort-action min-h-11 rounded-lg px-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                    data-testid="quotations-sort-updated_at"
                                    @click="sortBy('updated_at')"
                                >
                                    {{ t('quotations.column.updatedAt') }}
                                    <span aria-hidden="true">{{ sortIndicator('updated_at') }}</span>
                                </button>
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-for="quotation in quotations" :key="quotation.id" class="table-row" data-testid="quotations-row">
                            <!-- §4.7's `QT-2026-0001`, exactly as allocated. The way into the
                                 quotation's own page is Point 6.5; until then the code is text. -->
                            <td class="whitespace-nowrap p-3 tabular-nums" data-testid="quotations-code">{{ quotation.code }}</td>
                            <td class="p-3 tabular-nums" data-testid="quotations-version">
                                {{ t('quotations.version', { version: quotation.version }) }}
                            </td>
                            <td class="p-3">
                                <QuotationStatusChip :status="quotation.status" />
                            </td>
                            <td class="p-3" data-testid="quotations-customer">{{ customerName(quotation.customer_id) }}</td>
                            <!-- `Design System §6.3`: amount and currency visibly paired. The figure
                                 is the server's string — `DB-07` has no client-side exception. -->
                            <td class="whitespace-nowrap p-3 text-end tabular-nums" data-testid="quotations-total">
                                {{ quotation.final_total }} {{ quotation.currency }}
                            </td>
                            <td class="hidden p-3 tabular-nums md:table-cell">{{ onDate(quotation.quotation_date) }}</td>
                            <td class="hidden p-3 tabular-nums lg:table-cell">{{ onDate(quotation.valid_until) }}</td>
                            <td class="hidden p-3 tabular-nums lg:table-cell">{{ onDate(quotation.updated_at) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav
                v-if="pagination"
                class="flex items-center justify-between gap-3"
                :aria-label="t('quotations.pagination.label')"
                data-testid="quotations-pagination"
            >
                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!pagination.has_previous_page || loading"
                    data-testid="quotations-previous"
                    @click="goToPage(pagination.page - 1)"
                >
                    {{ t('quotations.pagination.previous') }}
                </button>

                <p class="tabular-nums text-[var(--color-text-muted)]">
                    {{ t('quotations.pagination.position', { page: pagination.page, pages: pagination.total_pages, total: pagination.total }) }}
                </p>

                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!pagination.has_next_page || loading"
                    data-testid="quotations-next"
                    @click="goToPage(pagination.page + 1)"
                >
                    {{ t('quotations.pagination.next') }}
                </button>
            </nav>
        </div>
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
</style>
