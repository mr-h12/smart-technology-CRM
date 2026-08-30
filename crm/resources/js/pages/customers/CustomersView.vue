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
 *
 * ── Two write controls, drawn by two different permissions (Point 4.3) ─────
 *
 * §3.3 gives `create` and `edit` separate rows with different scopes on the
 * same roles, which is why `SaveCustomerRequest` sits behind two route
 * middlewares and not one. The buttons mirror that split — and mirroring is
 * all they do: `SEC-09` makes the API the enforcement, so a caller who
 * reaches the endpoint another way is still refused by it.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import EmptyState from '@/components/states/EmptyState.vue';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import { listEntries, type ListEntry } from '@/services/admin';
import { archiveCustomer, listCustomers, restoreCustomer, type Customer, type Pagination } from '@/services/customers';
import { useAuth } from '@/stores/auth';
import CustomerFormModal from '@/pages/customers/CustomerFormModal.vue';
// ponytail: reused where it lives. It is already generic and text-driven — its
// own docblock says so — and moving it to a shared folder would edit Module 1's
// screens for a tidier import path. Recorded as debt instead.
import ConfirmDialog from '@/components/users/ConfirmDialog.vue';
import CustomerImportModal from '@/pages/customers/CustomerImportModal.vue';

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

const auth = useAuth();

/** §3.3's two rows. `resource.action` matches any scope — the scope is the row's, not the button's. */
const canCreate = computed(() => auth.hasPermission('customer.create'));
const canEdit = computed(() => auth.hasPermission('customer.edit'));

const formOpen = ref(false);
const editing = ref<Customer | null>(null);

/**
 * Point 4.5 — the archive half.
 *
 * Two positions, not three, because the server has two: `CustomerListCriteria`
 * reads `$filters['is_archived'] ?? false`, so absence *is* `false` and there
 * is no "show me both" for a third position to ask for.
 */
const archivedFilter = ref<'active' | 'archived'>('active');
const archivedOnly = computed(() => archivedFilter.value === 'archived');

/** §3.3 writes `archive / restore` as one merged row — one permission, both directions. */
const canArchive = computed(() => auth.hasPermission('customer.archive'));

/** §3.3's `import (Excel)` row is `All · — · — · — · — · — · —` — the Manager alone. */
const canImport = computed(() => auth.hasPermission('customer.import'));

const importOpen = ref(false);

const selectedIds = ref<string[]>([]);
const acting = ref(false);
/** §6.6: "a toast must not be the only place an error is explained" — so it is not a toast. */
const actionMessage = ref('');
const pending = ref<{ kind: 'archive'; customer: Customer } | { kind: 'restore-selected' } | null>(null);
/** §6.6: "return focus to the invoking control". */
let invoker: HTMLElement | null = null;

/**
 * Selection follows the row, never the filter. A row carries `is_archived`, so
 * a list holding both kinds still offers the right action on each one.
 */
const restorableIds = computed(() => customers.value.filter((row) => row.is_archived).map((row) => row.id));
const showSelection = computed(() => canArchive.value && restorableIds.value.length > 0);
const allSelected = computed(
    () => restorableIds.value.length > 0 && selectedIds.value.length === restorableIds.value.length,
);

const total = computed(() => pagination.value?.total ?? 0);

/** Which of the two empty states is true: "you have none" or "none matched". */
const filtering = computed(
    () =>
        search.value !== '' ||
        statusFilter.value !== '' ||
        sectorFilter.value !== '' ||
        incompleteOnly.value ||
        ownerInactiveOnly.value ||
        archivedOnly.value,
);

/** §6.2: "Comma-separated allowed fields. Prefix `-` means descending." */
const sortParameter = computed(() => `${sortDescending.value ? '-' : ''}${sortField.value}`);

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    denied.value = false;
    // A selection names rows on the page in hand. Fetch another page and those
    // ids address rows the person never ticked.
    selectedIds.value = [];

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
            // Sent in both positions, including `false`. The server would
            // default to the same thing, but a screen that shows one half of
            // the records should say which half rather than leave it implied.
            isArchived: archivedOnly.value,
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
    actionMessage.value = '';
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

function startCreate(): void {
    editing.value = null;
    formOpen.value = true;
}

function startEdit(customer: Customer): void {
    editing.value = customer;
    formOpen.value = true;
}

/**
 * The list is refreshed either way; the dialog closes only when there is
 * nothing left to read. §10.2's warning names records the person may want to
 * open instead, and closing over it is how a warning becomes a thing nobody saw.
 */
async function onSaved(_customer: Customer, similar: Customer[]): Promise<void> {
    formOpen.value = similar.length > 0;

    await load();
}

function toggleAll(checked: boolean): void {
    selectedIds.value = checked ? [...restorableIds.value] : [];
}

/** §6.6's focus contract, kept in the caller so Module 1's dialog is not edited. */
function ask(request: { kind: 'archive'; customer: Customer } | { kind: 'restore-selected' }): void {
    invoker = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    actionMessage.value = '';
    pending.value = request;
}

function dismiss(): void {
    pending.value = null;
    invoker?.focus();
    invoker = null;
}

/**
 * Flow 7's select-all restore, as a loop over `PATCH /customers/{id}/restore`
 * (owner's decision, 2026-08-30).
 *
 * `API-07` and `OpenAPI §7.3` describe a bulk endpoint and none is built. Each
 * call here carries the same `customer.archive` middleware and the same
 * row-scoped lookup, so §7.3's "authorize and audit each affected record" and
 * "do not allow a bulk request to bypass row scope" hold by construction.
 *
 * `allSettled`, not `all`: §7.3 also asks a bulk operation to "return
 * per-record result data", and one refused row must survive as a refusal
 * rather than collapsing the batch into a single rejection.
 *
 * ponytail: N requests and no transaction, bounded to one page by §6.5's "do
 * not create a UI that requires loading all records". `API-07`'s endpoint
 * replaces this function body without touching anything else on the screen.
 */
async function restoreSelected(): Promise<void> {
    const ids = [...selectedIds.value];
    const results = await Promise.allSettled(ids.map((id) => restoreCustomer(id)));
    const refused = results.filter((result) => result.status === 'rejected').length;

    actionMessage.value = refused === 0
        ? t('customers.restore.done', { count: ids.length })
        : t('customers.restore.failed', { done: ids.length - refused, failed: refused });
}

/** One row, no question asked — see the template for why §6.6 does not ask for one. */
async function restoreOne(customer: Customer): Promise<void> {
    acting.value = true;
    actionMessage.value = '';

    try {
        await restoreCustomer(customer.id);
    } catch {
        actionMessage.value = t('customers.archive.failed');
    } finally {
        acting.value = false;
    }

    await load();
}

async function onConfirm(): Promise<void> {
    const request = pending.value;

    if (request === null) {
        return;
    }

    acting.value = true;

    try {
        if (request.kind === 'archive') {
            await archiveCustomer(request.customer.id);
        } else {
            await restoreSelected();
        }
    } catch {
        // Archive is one record, so a refusal is the whole outcome and is said
        // in words. `CustomerArchiveEndpointTest` is what actually refuses it.
        actionMessage.value = t('customers.archive.failed');
    } finally {
        acting.value = false;
        dismiss();
    }

    await load();
}

/**
 * §10's "dedicated filter", reached rather than described.
 *
 * The dialog closes first: it has just sent the person to a set of rows, and
 * sitting on top of them is the one place the result is less useful than the
 * list behind it.
 */
async function onShowIncomplete(): Promise<void> {
    importOpen.value = false;
    incompleteOnly.value = true;

    await applyFilters();
}

onMounted(async () => {
    await Promise.all([load(), loadSectors()]);
});
</script>

<template>
    <section class="flex flex-col gap-4">
        <header class="flex flex-wrap items-baseline justify-between gap-2">
            <h1 class="text-page-title">{{ t('customers.title') }}</h1>

            <div class="flex flex-wrap items-baseline gap-3">
                <p v-if="!loading && !failed && !denied" data-testid="customer-total" class="tabular-nums text-[var(--color-text-muted)]">
                    {{ t('customers.total', { count: total }) }}
                </p>

                <!-- §3.3's Manager-only row. §6.2 allows one Primary per
                     context and *New customer* is it, so this is Secondary. -->
                <button
                    v-if="canImport"
                    type="button"
                    class="row-action min-h-11 rounded-lg px-4 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="customers-import"
                    @click="importOpen = true"
                >
                    {{ t('customers.import.title') }}
                </button>

                <!-- §6.2: one primary action per context. -->
                <button
                    v-if="canCreate"
                    type="button"
                    class="create-action min-h-11 rounded-lg px-4 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="customers-create"
                    @click="startCreate()"
                >
                    {{ t('customers.form.createTitle') }}
                </button>
            </div>
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

            <!-- Flow 7 takes an archived customer out of the working list.
                 This is the control that asks for the other half — the one
                 Point 4.2 deliberately left out. -->
            <label class="flex flex-col gap-1.5">
                <span>{{ t('customers.filter.archived') }}</span>
                <select
                    v-model="archivedFilter"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="customers-filter-archived"
                    @change="applyFilters"
                >
                    <option value="active">{{ t('customers.filter.archivedActive') }}</option>
                    <option value="archived">{{ t('customers.filter.archivedArchived') }}</option>
                </select>
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

        <!-- §6.6: the outcome is explained in the page, not only in a toast.
             `aria-live` so a screen reader is told what a click just did. -->
        <p
            v-if="actionMessage !== ''"
            class="action-message rounded-lg px-3 py-2"
            role="status"
            aria-live="polite"
            data-testid="customers-bulk-result"
        >
            {{ actionMessage }}
        </p>

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

        <div v-else class="flex flex-col gap-3">
            <!-- Flow 7: "Manager / TL (individually or select-all)". The count
                 is on the button because it is the consequence of the click. -->
            <div v-if="showSelection" class="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="selectedIds.length === 0 || acting"
                    data-testid="customers-bulk-restore"
                    @click="ask({ kind: 'restore-selected' })"
                >
                    {{ t('customers.restore.bulkAction', { count: selectedIds.length }) }}
                </button>
            </div>

            <div class="table-frame overflow-x-auto rounded-xl">
            <table class="w-full text-table" data-testid="customers-table">
                <!-- §6.5: a sticky header on long lists. The block axis does not
                     mirror, so `top` is correct in both directions. -->
                <thead class="sticky top-0">
                    <tr class="table-head">
                        <!-- Flow 7 gives select-all to *Restore*, so the column
                             exists only where there is something to restore. -->
                        <th v-if="showSelection" scope="col" class="p-3 text-start">
                            <input
                                type="checkbox"
                                class="size-4 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                :checked="allSelected"
                                :aria-label="t('customers.archive.selectAll')"
                                data-testid="customers-select-all"
                                @change="toggleAll(($event.target as HTMLInputElement).checked)"
                            />
                        </th>

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
                        <!-- §5.2's Detail/Form controls: "action controls only by permission". -->
                        <th v-if="canEdit || canArchive" scope="col" class="p-3 text-start">
                            <span class="sr-only">{{ t('customers.column.actions') }}</span>
                        </th>
                    </tr>
                </thead>

                <tbody>
                    <tr v-for="customer in customers" :key="customer.id" class="table-row" data-testid="customers-row">
                        <td v-if="showSelection" class="p-3">
                            <input
                                v-model="selectedIds"
                                type="checkbox"
                                :value="customer.id"
                                :disabled="!customer.is_archived"
                                class="size-4 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:opacity-40"
                                :aria-label="t('customers.archive.select', { name: customer.name })"
                                data-testid="customers-select-row"
                            />
                        </td>
                        <td class="p-3">
                            <!-- Point 4.4 registered the detail route, so the
                                 name resolves. `navigation.ts`'s rule is why it
                                 was plain text until then. -->
                            <RouterLink
                                :to="{ name: 'customer-detail', params: { id: customer.id } }"
                                class="row-link focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                data-testid="customers-row-link"
                            >
                                {{ customer.name }}
                            </RouterLink>
                        </td>
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
                        <td v-if="canEdit || canArchive" class="p-3">
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-if="canEdit"
                                    type="button"
                                    class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                    data-testid="customers-row-edit"
                                    @click="startEdit(customer)"
                                >
                                    {{ t('action.edit') }}
                                </button>

                                <!-- §6.2 puts Archive in the Danger variant.
                                     Restore is not destructive and is not one. -->
                                <button
                                    v-if="canArchive && !customer.is_archived"
                                    type="button"
                                    class="danger-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                    data-testid="customers-row-archive"
                                    @click="ask({ kind: 'archive', customer })"
                                >
                                    {{ t('customers.archive.action') }}
                                </button>

                                <!-- No confirmation: §6.6 lists archive,
                                     deactivate, rejection, return and approval,
                                     and a single restore is none of them. It is
                                     also idempotent (Point 3.4). -->
                                <button
                                    v-if="canArchive && customer.is_archived"
                                    type="button"
                                    class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                    :disabled="acting"
                                    data-testid="customers-row-restore"
                                    @click="restoreOne(customer)"
                                >
                                    {{ t('customers.restore.action') }}
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            </div>
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
        <!-- §6.6: the question names its consequence, and §6.2's Danger variant
             is the archive half only — a restore puts a record back. -->
        <ConfirmDialog
            :open="pending !== null"
            :title-key="pending?.kind === 'archive' ? 'customers.archive.confirmTitle' : 'customers.restore.confirmTitle'"
            :message-key="pending?.kind === 'archive' ? 'customers.archive.confirmMessage' : 'customers.restore.confirmMessage'"
            :confirm-key="pending?.kind === 'archive' ? 'customers.archive.confirmAction' : 'customers.restore.confirmAction'"
            :subject="pending?.kind === 'archive' ? pending.customer.name : String(selectedIds.length)"
            :busy="acting"
            :danger="pending?.kind === 'archive'"
            @confirm="onConfirm"
            @cancel="dismiss"
        />

        <CustomerImportModal
            :open="importOpen"
            @imported="load()"
            @show-incomplete="onShowIncomplete"
            @cancel="importOpen = false"
        />

        <CustomerFormModal
            :open="formOpen"
            :editing="editing"
            :sectors="sectors"
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

.row-link {
    color: var(--color-primary);
    text-decoration: underline;
}

/* §6.2's Primary: the one main permitted action on this screen. */
.create-action {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
}

/* §6.2's Danger variant: "Archive, deactivate, reject, or irreversible-equivalent". */
.danger-action {
    background-color: var(--color-danger);
    color: var(--color-text-inverse);
}

.action-message {
    background-color: var(--color-surface-muted);
    color: var(--color-text);
    border: 1px solid var(--color-border);
}

.status-chip {
    background-color: color-mix(in srgb, var(--color-status-neutral) 14%, transparent);
    color: var(--color-status-neutral);
}
</style>
