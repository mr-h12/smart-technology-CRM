<script setup lang="ts">
/**
 * §8's *Quotations* screen — `Design System §5.2`'s Table/List (Module 7,
 * Point 6.3), with §6.6's views and split (Point 6.4).
 *
 * ── §6.6's three views and its fixed split ─────────────────────────────────
 *
 * *By employee · by customer · flat* is one toggle and one parameter:
 * `group_by`, which the server answers with `{key, label, count, items}` per
 * group (Point 5.5, `OpenAPI §6.2` "server-side grouping only"). The employee
 * label is the owner's name resolved on the server (Step 6 Q2), because no
 * users endpoint answers a Team Leader; the customer label is the id, named
 * here from the same list the filter uses (Step 5 Q7). Pagination counts
 * quotations, not groups, so a group may continue on the next page — the
 * screen says so under a grouped table rather than pretending otherwise.
 *
 * *Active · history* is `filter[bucket]` (Step 5 Q1): two panels, two calls,
 * each with its own page, sharing every other control. The choice of view is
 * remembered per browser under `crm.quotations.view` (Step 6 Q1 — the
 * `theme.ts` shape: a storage that throws is a storage that remembers
 * nothing, and an unknown value is the flat default).
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
import { RouterLink } from 'vue-router';
import { ApiError, type Pagination } from '@/api';
import EmptyState from '@/components/states/EmptyState.vue';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import { listCustomers, type Customer } from '@/services/customers';
import {
    QUOTATION_DEFAULT_SORT,
    QUOTATION_STATUSES,
    listQuotationGroups,
    listQuotations,
    type QuotationGroup,
} from '@/services/quotations';
import QuotationStatusChip from '@/pages/quotations/QuotationStatusChip.vue';
import { displayDecimals } from '@/domain/displayDecimals';
import SelfApprovedBadge from '@/pages/quotations/SelfApprovedBadge.vue';

const { t, locale } = useI18n();

type SortField = 'code' | 'quotation_date' | 'updated_at' | 'final_total';

const VIEWS = ['employee', 'customer', 'flat'] as const;
type View = (typeof VIEWS)[number];
const VIEW_STORAGE_KEY = 'crm.quotations.view';

function storedView(): View {
    try {
        const stored = window.localStorage.getItem(VIEW_STORAGE_KEY);

        return VIEWS.find((view) => view === stored) ?? 'flat';
    } catch {
        return 'flat';
    }
}

/** Step 5 Q1's two, plus Module 8 · 2.2's returned drafts between them (§8 "including incomplete"). */
const BUCKETS = ['active', 'incomplete', 'history'] as const;
type Bucket = (typeof BUCKETS)[number];

/** One panel's answer. The flat view is one nameless group, so one row template serves both. */
interface Panel {
    groups: QuotationGroup[];
    pagination: Pagination | null;
    page: number;
    loading: boolean;
    failed: boolean;
    denied: boolean;
}

function emptyPanel(): Panel {
    return { groups: [], pagination: null, page: 1, loading: true, failed: false, denied: false };
}

const view = ref<View>(storedView());
const panels = ref<Record<Bucket, Panel>>({ active: emptyPanel(), incomplete: emptyPanel(), history: emptyPanel() });
const customers = ref<Customer[]>([]);

const loading = computed(() => BUCKETS.some((bucket) => panels.value[bucket].loading));
const denied = computed(() => BUCKETS.some((bucket) => panels.value[bucket].denied));
const failed = computed(() => BUCKETS.some((bucket) => panels.value[bucket].failed));
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

/** Both buckets' `meta.pagination.total`, never the rows in hand. */
// A returned draft is an active row too, so `incomplete` is not added to the count.
const total = computed(() => (['active', 'history'] as const).reduce((sum, bucket) => sum + (panels.value[bucket].pagination?.total ?? 0), 0));
/** Eight columns, so a group heading spans the row. */
const columnCount = 8;
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

async function loadBucket(bucket: Bucket): Promise<void> {
    const panel = panels.value[bucket];
    panel.loading = true;
    panel.failed = false;
    panel.denied = false;

    try {
        const query = {
            page: panel.page,
            bucket,
            sort: sortParameter.value,
            status: statusFilter.value === '' ? null : statusFilter.value,
            customerId: customerFilter.value === '' ? null : customerFilter.value,
            from: fromFilter.value === '' ? null : fromFilter.value,
            to: toFilter.value === '' ? null : toFilter.value,
            currency: hasCurrency.value ? currencyCode.value : null,
            // Step 5 Q3: the pair travels only with its currency.
            amountMin: hasCurrency.value && amountMinFilter.value !== '' ? amountMinFilter.value : null,
            amountMax: hasCurrency.value && amountMaxFilter.value !== '' ? amountMaxFilter.value : null,
        };

        if (view.value === 'flat') {
            const result = await listQuotations(query);
            panel.groups = result.items.length === 0 ? [] : [{ key: null, label: '', count: result.items.length, items: result.items }];
            panel.pagination = result.pagination;
        } else {
            const result = await listQuotationGroups(view.value, query);
            panel.groups = result.groups;
            panel.pagination = result.pagination;
        }
    } catch (error) {
        // A 403 and a 500 are different answers and get different screens
        // (`SEC-09`): a refusal drawn as an empty list would read as "there
        // are no quotations", which is a lie.
        panel.denied = error instanceof ApiError && error.status === 403;
        panel.failed = !panel.denied;
    } finally {
        panel.loading = false;
    }
}

/** Both buckets, from their first page: the question changed. */
async function load(): Promise<void> {
    for (const bucket of BUCKETS) {
        panels.value[bucket].page = 1;
    }

    await Promise.all(BUCKETS.map(loadBucket));
}

async function chooseView(next: View): Promise<void> {
    view.value = next;

    try {
        window.localStorage.setItem(VIEW_STORAGE_KEY, next);
    } catch {
        // A preference that cannot be written is still applied for this visit.
    }

    await load();
}

/** The customer group's label is its id (Step 5 Q7); the employee's is the server's name. */
function groupLabel(group: QuotationGroup): string {
    return view.value === 'customer' && group.key !== null ? customerName(group.key) : group.label;
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

    await load();
}

async function goToPage(bucket: Bucket, target: number): Promise<void> {
    panels.value[bucket].page = target;
    await loadBucket(bucket);
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

        <!-- §6.6's toggle. Three buttons and `aria-pressed`, not a select: the
             current view is read at a glance, and the choice is one click. -->
        <div class="flex flex-wrap gap-2" role="group" :aria-label="t('quotations.view.label')" data-testid="quotations-view">
            <button
                v-for="option in VIEWS"
                :key="option"
                type="button"
                class="view-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                :aria-pressed="view === option"
                :data-testid="`quotations-view-${option}`"
                @click="chooseView(option)"
            >
                {{ t(`quotations.view.${option}`) }}
            </button>
        </div>

        <!-- One refusal, not two: the permission is the same for both buckets. -->
        <PermissionDeniedState v-if="denied" />

        <section
            v-for="bucket in BUCKETS"
            v-else
            :key="bucket"
            class="flex flex-col gap-3"
            :data-testid="`quotations-bucket-${bucket}`"
        >
            <h2 class="text-section-title">{{ t(`quotations.bucket.${bucket}`) }}</h2>

            <LoadingState v-if="panels[bucket].loading" label-key="quotations.loading" />
            <ErrorState v-else-if="panels[bucket].failed" @retry="loadBucket(bucket)" />
            <EmptyState
                v-else-if="panels[bucket].groups.length === 0"
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
                        <template v-for="group in panels[bucket].groups" :key="group.key ?? ''">
                        <!-- §6.6's grouping, on Catalog's precedent: `colgroup`
                             scope, because the heading labels the rows beneath it. -->
                        <tr v-if="view !== 'flat'" class="group-row">
                            <th :colspan="columnCount" scope="colgroup" class="p-3 text-start font-medium" data-testid="quotations-group-heading">
                                {{ groupLabel(group) }} ({{ group.count }})
                            </th>
                        </tr>

                        <tr v-for="quotation in group.items" :key="quotation.id" class="table-row" data-testid="quotations-row">
                            <!-- §4.7's `QT-2026-0001`, exactly as allocated — the way into the
                                 quotation's own page (Point 6.5). -->
                            <td class="whitespace-nowrap p-3 tabular-nums" data-testid="quotations-code">
                                <RouterLink
                                    :to="{ name: 'quotation-detail', params: { id: quotation.id } }"
                                    class="row-link"
                                    data-testid="quotations-row-link"
                                >
                                    {{ quotation.code }}
                                </RouterLink>
                            </td>
                            <td class="p-3 tabular-nums" data-testid="quotations-version">
                                {{ t('quotations.version', { version: quotation.version }) }}
                            </td>
                            <td class="p-3">
                                <span class="flex flex-wrap items-center gap-1.5">
                                    <QuotationStatusChip :status="quotation.status" />
                                    <SelfApprovedBadge v-if="quotation.is_self_approved" />
                                </span>
                            </td>
                            <td class="p-3" data-testid="quotations-customer">{{ customerName(quotation.customer_id) }}</td>
                            <!-- `Design System §6.3`: amount and currency visibly paired. The figure
                                 is the server's string — `DB-07` has no client-side exception. -->
                            <td class="whitespace-nowrap p-3 text-end tabular-nums" data-testid="quotations-total">
                                {{ displayDecimals(quotation.final_total) }} {{ quotation.currency }}
                            </td>
                            <td class="hidden p-3 tabular-nums md:table-cell">{{ onDate(quotation.quotation_date) }}</td>
                            <td class="hidden p-3 tabular-nums lg:table-cell">{{ onDate(quotation.valid_until) }}</td>
                            <td class="hidden p-3 tabular-nums lg:table-cell">{{ onDate(quotation.updated_at) }}</td>
                        </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <p
                v-if="view !== 'flat' && (panels[bucket].pagination?.total_pages ?? 1) > 1"
                class="text-[var(--color-text-muted)]"
            >
                {{ t('quotations.groups.continues') }}
            </p>

            <nav
                v-if="panels[bucket].pagination"
                class="flex items-center justify-between gap-3"
                :aria-label="t('quotations.pagination.label')"
                data-testid="quotations-pagination"
            >
                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!panels[bucket].pagination?.has_previous_page || panels[bucket].loading"
                    data-testid="quotations-previous"
                    @click="goToPage(bucket, panels[bucket].page - 1)"
                >
                    {{ t('quotations.pagination.previous') }}
                </button>

                <p class="tabular-nums text-[var(--color-text-muted)]">
                    {{ t('quotations.pagination.position', { page: panels[bucket].pagination?.page, pages: panels[bucket].pagination?.total_pages, total: panels[bucket].pagination?.total }) }}
                </p>

                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!panels[bucket].pagination?.has_next_page || panels[bucket].loading"
                    data-testid="quotations-next"
                    @click="goToPage(bucket, panels[bucket].page + 1)"
                >
                    {{ t('quotations.pagination.next') }}
                </button>
            </nav>
            </div>
        </section>
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

.group-row {
    background-color: var(--color-surface-muted);
}

.view-action {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

/* Pressed = the current view, told by more than colour: the border thickens too. */
.view-action[aria-pressed='true'] {
    background-color: var(--color-surface-muted);
    border-width: 2px;
    font-weight: 600;
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
</style>
