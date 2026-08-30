<script setup lang="ts">
/**
 * §8's *Customers* screen — Design System §5.2's Table/List (Point 4.1).
 *
 * ── The sort is the server's, and the test reads the URL to prove it ───────
 *
 * §5.2 requires "server-side filters/sort/search" and §6.5 requires "Every
 * list is server-paginated. Do not create a UI that requires loading all
 * records." Those two are the same rule seen twice: a client-side `sort()`
 * orders the 25 rows in hand and silently claims to have ordered 4,000. So a
 * header click changes `sort` and asks again, and `CustomersView.spec.ts`
 * asserts on the query string rather than on the rendered order.
 *
 * The three sortable columns are `CustomerListCriteria::ALLOWED_SORTS` and the
 * initial order is its `DEFAULT_SORT` — restated here because `OpenAPI §6.2`
 * answers an undeclared sort field with a 400, so a header this screen invents
 * is a button that breaks the screen.
 *
 * **One sort key, though the server accepts several.** §6.2's example is
 * comma-separated and `CustomerListCriteria` parses a list; no source asks the
 * UI for a second key, and a two-key header interaction is a design nobody has
 * approved. The service and the criteria both already carry it when it is
 * wanted.
 *
 * ── Why a sort change goes back to page 1 ──────────────────────────────────
 *
 * "Page 2" is a position in an order. Change the order and the same page number
 * addresses a different set of rows, so keeping it shows the user a page they
 * never asked for.
 *
 * ── Two date columns, formatted two different ways, deliberately ───────────
 *
 * `created_at` is a UTC timestamp and `DB-08` says timestamps are stored in UTC
 * and displayed in the user's timezone — hence `toLocaleDateString`.
 * `start_date` is a calendar date (§4.2, "First engagement"), which has no
 * timezone: passing it through a `Date` would place it at UTC midnight and show
 * the day before to anyone west of Greenwich. It is printed as it was stored.
 *
 * ── Three roles will see the empty state, and that is the backend's ────────
 *
 * `team`, `out` and `asgn` resolve to no rows (owner's deferral, 2026-08-29),
 * so the Team Leader, the Outdoor Supervisor and Procurement reach this screen
 * and see nothing. An empty result is rendered as empty — not as a refusal,
 * which it is not.
 *
 * ── A 403 is the server's answer, shown as one ─────────────────────────────
 *
 * `SEC-09`: hiding is a visual complement to API enforcement, never the
 * enforcement. The route already carries `customer.view`, so a 403 here means
 * the two disagree — and the screen says so rather than rendering an empty
 * list, which would read as "you have no customers".
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import EmptyState from '@/components/states/EmptyState.vue';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import { listEntries, type ListEntry } from '@/services/admin';
import { listCustomers, type Customer, type Pagination } from '@/services/customers';

/**
 * The two date columns of `CustomerListCriteria::ALLOWED_SORTS`; `name` is the
 * third and is written out below, because it is the one column that never folds
 * away on a narrow viewport.
 */
const DATE_COLUMNS = [
    { field: 'start_date', label: 'customers.column.startDate' },
    { field: 'created_at', label: 'customers.column.added' },
] as const;

/**
 * §4.5's four codes, which are a database CHECK rather than a managed list —
 * so there is no endpoint to ask, and this is the one place the set is
 * restated. `customers_known_status` in the create-customers migration is the
 * original; a code missing from here narrows a filter, it does not break one.
 */
const STATUS_CODES = ['prospect', 'customer', 'no_response', 'deal_not_completed'] as const;

const { t, te, locale } = useI18n();

const customers = ref<Customer[]>([]);
const pagination = ref<Pagination | null>(null);
const loading = ref(true);
const failed = ref(false);
const denied = ref(false);

/** §4.2's sectors, from `DB-05`'s managed list rather than from a copy of the seeded six. */
const sectors = ref<ListEntry[]>([]);

const page = ref(1);
const search = ref('');
const statusFilter = ref('');
const sectorFilter = ref('');
const incompleteOnly = ref(false);
const ownerInactiveOnly = ref(false);
/** `CustomerListCriteria::DEFAULT_SORT`. */
const sortField = ref<string>('name');
const sortDescending = ref(false);

const total = computed(() => pagination.value?.total ?? 0);

/** Which of the two empty states is true: "you have none" or "none matched". */
const filtering = computed(
    () =>
        search.value !== '' ||
        statusFilter.value !== '' ||
        sectorFilter.value !== '' ||
        incompleteOnly.value ||
        ownerInactiveOnly.value,
);

/** §6.2: "Comma-separated allowed fields. Prefix `-` means descending." */
const sortParameter = computed(() => `${sortDescending.value ? '-' : ''}${sortField.value}`);

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    denied.value = false;

    try {
        const result = await listCustomers({
            page: page.value,
            sort: sortParameter.value,
            q: search.value === '' ? null : search.value,
            customerStatus: statusFilter.value === '' ? null : statusFilter.value,
            sector: sectorFilter.value === '' ? null : sectorFilter.value,
            // `null`, never `false`. An unchecked box asks nothing about the
            // column; `false` would ask for the complete records only, which is
            // a different question and would hide `D-31`'s rows entirely.
            isIncomplete: incompleteOnly.value ? true : null,
            ownerInactive: ownerInactiveOnly.value ? true : null,
        });

        customers.value = result.items;
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
 * The sector options, and why a failure here is not the screen's failure.
 *
 * `GET /managed-lists/sectors` names no permission — authentication alone — but
 * §3.12 rule 5 lets an administrator change what a role reaches, and a dropdown
 * that could not be filled is one filter short, not a broken screen. The list
 * still loads and still says so.
 *
 * ponytail: page 1 only. The seeded set is §4.2's six and the endpoint pages at
 * 25; a 26th sector needs a paged fetch or a `per_page`, not a redesign.
 */
async function loadSectors(): Promise<void> {
    try {
        sectors.value = (await listEntries('sectors', 1)).items;
    } catch {
        sectors.value = [];
    }
}

/** Any change to the question invalidates the page number, for `sortBy`'s reason. */
async function applyFilters(): Promise<void> {
    page.value = 1;
    await load();
}

/** `DB-05` keeps both labels on every entry, so neither language falls back to a code. */
function sectorLabel(entry: ListEntry): string {
    return locale.value.startsWith('ar') ? entry.label_ar : entry.label_en;
}

async function sortBy(field: string): Promise<void> {
    if (sortField.value === field) {
        sortDescending.value = !sortDescending.value;
    } else {
        sortField.value = field;
        sortDescending.value = false;
    }

    page.value = 1;
    await load();
}

async function goToPage(target: number): Promise<void> {
    page.value = target;
    await load();
}

/** §8: "announced status changes" — the sort state belongs on the header cell, not only in an icon. */
function ariaSort(field: string): 'ascending' | 'descending' | 'none' {
    if (sortField.value !== field) {
        return 'none';
    }

    return sortDescending.value ? 'descending' : 'ascending';
}

/** The arrow beside the active column. `aria-hidden`, because `aria-sort` above already says it. */
function sortIndicator(field: string): string {
    return sortField.value === field ? (sortDescending.value ? '▼' : '▲') : '';
}

/**
 * §4.5's derived status, as a word rather than a code.
 *
 * Asked of the catalogue rather than assumed: the four codes are a database
 * CHECK, and §3.12 rule 5 makes configuration the administrator's. A status
 * this screen has no word for prints its code, which is ugly and true —
 * better than an empty cell that hides that the list gained a state.
 */
function statusLabel(code: string): string {
    const key = `customers.status.${code}`;

    return te(key) ? t(key) : code;
}

/** `DB-08` — stored UTC, shown in the reader's own timezone. */
function addedOn(timestamp: string): string {
    return new Date(timestamp).toLocaleDateString(locale.value);
}

onMounted(async () => {
    await Promise.all([load(), loadSectors()]);
});
</script>

<template>
    <section class="flex flex-col gap-4">
        <header class="flex flex-wrap items-baseline justify-between gap-2">
            <h1 class="text-page-title">{{ t('customers.title') }}</h1>

            <p v-if="!loading && !failed && !denied" data-testid="customer-total" class="tabular-nums text-[var(--color-text-muted)]">
                {{ t('customers.total', { count: total }) }}
            </p>
        </header>

        <!-- §5.2's "server-side filters/sort/search". Every control below sends a
             declared parameter and nothing narrows anything in the browser. -->
        <form class="flex flex-wrap items-end gap-3" data-testid="customers-search-form" @submit.prevent="applyFilters">
            <label class="flex flex-col gap-1.5">
                <span>{{ t('customers.filter.search') }}</span>
                <input
                    v-model="search"
                    type="search"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="customers-search"
                />
            </label>

            <button
                type="submit"
                class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                data-testid="customers-search-submit"
            >
                {{ t('customers.filter.searchAction') }}
            </button>

            <label class="flex flex-col gap-1.5">
                <span>{{ t('customers.filter.status') }}</span>
                <select
                    v-model="statusFilter"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="customers-filter-status"
                    @change="applyFilters"
                >
                    <option value="">{{ t('customers.filter.statusAll') }}</option>
                    <option v-for="code in STATUS_CODES" :key="code" :value="code">{{ t(`customers.status.${code}`) }}</option>
                </select>
            </label>

            <label class="flex flex-col gap-1.5">
                <span>{{ t('customers.filter.sector') }}</span>
                <select
                    v-model="sectorFilter"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="customers-filter-sector"
                    @change="applyFilters"
                >
                    <option value="">{{ t('customers.filter.sectorAll') }}</option>
                    <option v-for="sector in sectors" :key="sector.code" :value="sector.code">{{ sectorLabel(sector) }}</option>
                </select>
            </label>

            <label class="flex min-h-11 items-center gap-2">
                <input
                    v-model="incompleteOnly"
                    type="checkbox"
                    class="size-4 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="customers-filter-incomplete"
                    @change="applyFilters"
                />
                <span>{{ t('customers.filter.incomplete') }}</span>
            </label>

            <!-- §10.1, required in as many words: "a 'Customers of deactivated
                 employees' filter on the customer screen". -->
            <label class="flex min-h-11 items-center gap-2">
                <input
                    v-model="ownerInactiveOnly"
                    type="checkbox"
                    class="size-4 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="customers-filter-owner-inactive"
                    @change="applyFilters"
                />
                <span>{{ t('customers.filter.ownerInactive') }}</span>
            </label>
        </form>

        <LoadingState v-if="loading" label-key="customers.loading" />
        <PermissionDeniedState v-else-if="denied" />
        <ErrorState v-else-if="failed" @retry="load" />
        <!-- "You have no customers" and "nothing matched" are different
             sentences, and only one of them is ever true. -->
        <EmptyState
            v-else-if="customers.length === 0"
            :title-key="filtering ? 'customers.empty.filtered.title' : 'state.empty.title'"
            :message-key="filtering ? 'customers.empty.filtered.message' : 'state.empty.message'"
        />

        <div v-else class="table-frame overflow-x-auto rounded-xl">
            <table class="w-full text-table" data-testid="customers-table">
                <!-- §6.5: a sticky header on long lists. The block axis does not
                     mirror, so `top` is correct in both directions. -->
                <thead class="sticky top-0">
                    <tr class="table-head">
                        <th scope="col" class="p-3 text-start" :aria-sort="ariaSort('name')" data-testid="customers-column-name">
                            <button
                                type="button"
                                class="sort-action min-h-11 rounded-lg px-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                data-testid="customers-sort-name"
                                @click="sortBy('name')"
                            >
                                {{ t('customers.column.name') }}
                                <span aria-hidden="true">{{ sortIndicator('name') }}</span>
                            </button>
                        </th>

                        <th scope="col" class="p-3 text-start">{{ t('customers.column.status') }}</th>

                        <!-- §5.2's column priorities: the secondary columns fold
                             away first on a narrow viewport, the dates last. -->
                        <th scope="col" class="hidden p-3 text-start md:table-cell">{{ t('customers.column.sector') }}</th>
                        <th scope="col" class="hidden p-3 text-start md:table-cell">{{ t('customers.column.contact') }}</th>
                        <th scope="col" class="hidden p-3 text-start md:table-cell">{{ t('customers.column.phone') }}</th>

                        <th
                            v-for="column in DATE_COLUMNS"
                            :key="column.field"
                            scope="col"
                            class="hidden p-3 text-start lg:table-cell"
                            :aria-sort="ariaSort(column.field)"
                            :data-testid="`customers-column-${column.field}`"
                        >
                            <button
                                type="button"
                                class="sort-action min-h-11 rounded-lg px-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                :data-testid="`customers-sort-${column.field}`"
                                @click="sortBy(column.field)"
                            >
                                {{ t(column.label) }}
                                <span aria-hidden="true">{{ sortIndicator(column.field) }}</span>
                            </button>
                        </th>
                    </tr>
                </thead>

                <tbody>
                    <tr v-for="customer in customers" :key="customer.id" class="table-row" data-testid="customers-row">
                        <td class="p-3">{{ customer.name }}</td>
                        <td class="p-3">
                            <!-- §6.4: never colour alone — the chip carries its own word. -->
                            <span class="status-chip rounded-full px-2 py-0.5" data-testid="customers-status">
                                {{ statusLabel(customer.customer_status) }}
                            </span>
                        </td>
                        <td class="hidden p-3 md:table-cell">{{ customer.sector ?? '—' }}</td>
                        <td class="hidden p-3 md:table-cell">{{ customer.contact_person ?? '—' }}</td>
                        <!-- D-70: Inter draws Western digits in both locales;
                             tabular-nums keeps a column of figures aligned. -->
                        <td class="hidden p-3 tabular-nums md:table-cell">{{ customer.phone ?? '—' }}</td>
                        <td class="hidden p-3 tabular-nums lg:table-cell">{{ customer.start_date ?? '—' }}</td>
                        <td class="hidden p-3 tabular-nums lg:table-cell">{{ addedOn(customer.created_at) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="pagination !== null && pagination.total_pages > 1"
            class="flex items-center justify-between gap-3"
            :aria-label="t('customers.pagination.label')"
            data-testid="customers-pagination"
        >
            <button
                type="button"
                class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="!pagination.has_previous_page || loading"
                data-testid="customers-previous"
                @click="goToPage(pagination.page - 1)"
            >
                {{ t('customers.pagination.previous') }}
            </button>

            <p class="tabular-nums text-[var(--color-text-muted)]">
                {{ t('customers.pagination.position', { page: pagination.page, pages: pagination.total_pages, total: pagination.total }) }}
            </p>

            <button
                type="button"
                class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="!pagination.has_next_page || loading"
                data-testid="customers-next"
                @click="goToPage(pagination.page + 1)"
            >
                {{ t('customers.pagination.next') }}
            </button>
        </nav>
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

.status-chip {
    background-color: color-mix(in srgb, var(--color-status-neutral) 14%, transparent);
    color: var(--color-status-neutral);
}
</style>
