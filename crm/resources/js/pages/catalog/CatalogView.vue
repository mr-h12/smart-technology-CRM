<script setup lang="ts">
/**
 * §8's *Catalog* screen — Design System §5.2's Table/List (Point 4.3).
 *
 * ── §7.3, read from the source ─────────────────────────────────────────────
 *
 * "Descriptive data only — **no prices**. Two tabs: Product · Service, grouped
 * by company/team name." Every clause of that sentence is a decision here:
 *
 * - **No prices.** `D-21` puts price, cost and margin on the supplier
 *   quotation. `CatalogItemPayload` carries none of the three, so there is
 *   nothing to render and nothing to hide.
 * - **Two tabs, and no third.** Point 1.2 put both in one table behind `kind`,
 *   so a tab is `filter[kind]` against one endpoint. §7.3 names two, so one of
 *   the two is always active and there is no "everything" position.
 * - **Grouped by company.** `group_by=company` is `API-06`'s server-side
 *   grouping and is sent on every load, not offered as a toggle — §7.3 states
 *   it as how the catalog is read, not as an option.
 *
 * The two tabs also list **different fields**, so the columns follow the tab
 * rather than showing one union with half the cells empty.
 *
 * ── The grouping is the server's order; this screen only notices it ────────
 *
 * `group_by` changes the **ordering, not the envelope**: `data` is still the
 * flat paginated list, with the companies adjacent and alphabetical and nulls
 * last. So the heading rows below are drawn where the value *changes* — there
 * is no client-side `sort()` or `reduce()` into buckets, which would be §6.5's
 * forbidden "UI that requires loading all records" wearing a different hat.
 *
 * ── The sidebar follows §3.7 (owner's ruling, 2026-08-31) ──────────────────
 *
 * §3.7 grants `catalog.view` to eight roles including the CEO; §8 lists a
 * Catalog screen for six of them and none for the CEO. The route and the nav
 * item both follow §3.7, exactly as the Suppliers item does — keying the menu
 * on §8's screen list would leave a screen a person may open with no way to
 * reach it. Recorded in `CHECKLIST.md` awaiting a `D-xx`. `SEC-09` is
 * unaffected: the API is still the gate.
 *
 * ── No write controls, and that is Point 4.4 ───────────────────────────────
 *
 * §3.7's `catalog.manage` covers create, edit and deactivate for items too, and
 * all three arrive with the form modal. A button here now would open nothing.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import EmptyState from '@/components/states/EmptyState.vue';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import { listCatalogItems, type CatalogItem, type Pagination } from '@/services/catalog';

/** §7.3's two tabs, which are `CatalogItemDraft::KINDS` on the server. */
const KINDS = ['product', 'service'] as const;

type Kind = (typeof KINDS)[number];

/**
 * §7.3's two field lists, which are not the same list. The Product tab names
 * product code, category and unit; the Service tab names service type and
 * notes. `name` and the timestamp are on both because they are the two the
 * server will sort by (`ALLOWED_SORTS`).
 */
const COLUMNS: Record<Kind, readonly string[]> = {
    product: ['product_code', 'category', 'unit', 'description'],
    service: ['service_type', 'description', 'notes'],
};

const { t, locale } = useI18n();

const items = ref<CatalogItem[]>([]);
const pagination = ref<Pagination | null>(null);
const loading = ref(true);
const failed = ref(false);
const denied = ref(false);

const page = ref(1);
const search = ref('');
const categoryFilter = ref('');
const kind = ref<Kind>('product');

/**
 * Three positions, not two. §10.4 hides a deactivated item from **selection
 * lists** — Modules 6 and 7 — and not from this management screen, and the
 * server's `filter[is_active]` is tri-state for the same reason. A screen that
 * hid them by default would be one nobody could reactivate from.
 */
const activeFilter = ref<'' | 'active' | 'inactive'>('');

/** `CatalogItemListCriteria::DEFAULT_SORT`. */
const sortField = ref<string>('name');
const sortDescending = ref(false);

const total = computed(() => pagination.value?.total ?? 0);

/**
 * The tab is not part of this: one of the two is always on, so it can never be
 * the reason a list came back empty. "No products matched" and "there are no
 * products" are still different sentences.
 */
const filtering = computed(
    () => search.value !== '' || categoryFilter.value !== '' || activeFilter.value !== '',
);

/** §6.2: "Comma-separated allowed fields. Prefix `-` means descending." */
const sortParameter = computed(() => `${sortDescending.value ? '-' : ''}${sortField.value}`);

const columns = computed<readonly string[]>(() => COLUMNS[kind.value]);

/** Name, the tab's own fields, status, added. */
const columnCount = computed(() => columns.value.length + 3);

/**
 * Where the server's ordering changes company, which is the only thing this
 * screen has to work out. A group that spans a page boundary is drawn with a
 * heading again on the next page — the alternative is to remember the previous
 * page's last row, which is state this screen does not keep.
 */
const rows = computed(() => items.value.map((item, index) => ({
    item,
    startsGroup: index === 0 || items.value[index - 1]?.company !== item.company,
})));

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    denied.value = false;

    try {
        const result = await listCatalogItems({
            page: page.value,
            sort: sortParameter.value,
            // §7.3's "grouped by company/team name", on every load.
            groupBy: 'company',
            kind: kind.value,
            q: search.value === '' ? null : search.value,
            category: categoryFilter.value === '' ? null : categoryFilter.value,
            // `null`, never `false`, when unset. An unset select asks nothing
            // about the column; `false` would ask for the deactivated ones only.
            isActive: activeFilter.value === '' ? null : activeFilter.value === 'active',
        });

        items.value = result.items;
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

/** Any change to the question invalidates the page number, for `sortBy`'s reason. */
async function applyFilters(): Promise<void> {
    page.value = 1;
    await load();
}

/** The tab is the biggest change of question there is, so it resets the page too. */
async function selectKind(next: Kind): Promise<void> {
    if (kind.value === next) {
        return;
    }

    kind.value = next;
    page.value = 1;
    await load();
}

/**
 * A sort change goes back to page 1: "page 2" is a position in an order, so
 * changing the order makes the same page number address a different set of rows.
 */
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

/** The arrow beside the active column. `aria-hidden`, because `aria-sort` already says it. */
function sortIndicator(field: string): string {
    return sortField.value === field ? (sortDescending.value ? '▼' : '▲') : '';
}

/** The cell for one of §7.3's tab-specific fields. An absent value is a dash, not a blank. */
function cell(item: CatalogItem, column: string): string {
    const value = item[column as keyof CatalogItem];

    return typeof value === 'string' && value !== '' ? value : '—';
}

/** `DB-08` — stored UTC, shown in the reader's own timezone. */
function addedOn(timestamp: string): string {
    return new Date(timestamp).toLocaleDateString(locale.value);
}

onMounted(load);
</script>

<template>
    <section class="flex flex-col gap-4">
        <header class="flex flex-wrap items-baseline justify-between gap-2">
            <h1 class="text-page-title">{{ t('catalog.title') }}</h1>

            <p
                v-if="!loading && !failed && !denied"
                data-testid="catalog-total"
                class="tabular-nums text-[var(--color-text-muted)]"
            >
                {{ t(`catalog.total.${kind}`, { count: total }) }}
            </p>
        </header>

        <!-- §7.3's two tabs. `aria-selected` rather than colour alone (§6.4),
             and a real tablist so the state is announced (§8). -->
        <div class="tab-strip flex gap-1" role="tablist" :aria-label="t('catalog.tabs.label')">
            <button
                v-for="code in KINDS"
                :key="code"
                type="button"
                role="tab"
                class="tab min-h-11 rounded-t-lg px-4 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                :class="kind === code ? 'tab-current' : ''"
                :aria-selected="kind === code"
                :data-testid="`catalog-tab-${code}`"
                @click="selectKind(code)"
            >
                {{ t(`catalog.tab.${code}`) }}
            </button>
        </div>

        <!-- §5.2's "server-side filters/sort/search". Every control below sends a
             declared parameter and nothing narrows anything in the browser. -->
        <form class="flex flex-wrap items-end gap-3" data-testid="catalog-search-form" @submit.prevent="applyFilters">
            <label class="flex flex-col gap-1.5">
                <span>{{ t('catalog.filter.search') }}</span>
                <input
                    v-model="search"
                    type="search"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="catalog-search"
                />
            </label>

            <!-- §7.3 calls category "for search", and there is no endpoint that
                 enumerates the values, so it is free text and it travels with
                 the search rather than firing on every keystroke. -->
            <label class="flex flex-col gap-1.5">
                <span>{{ t('catalog.column.category') }}</span>
                <input
                    v-model="categoryFilter"
                    type="text"
                    maxlength="128"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="catalog-filter-category"
                />
            </label>

            <button
                type="submit"
                class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                data-testid="catalog-search-submit"
            >
                {{ t('catalog.filter.searchAction') }}
            </button>

            <!-- Three positions, because §10.4's hiding belongs to a selection
                 list and not to this screen. -->
            <label class="flex flex-col gap-1.5">
                <span>{{ t('catalog.filter.active') }}</span>
                <select
                    v-model="activeFilter"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="catalog-filter-active"
                    @change="applyFilters"
                >
                    <option value="">{{ t('catalog.filter.activeAll') }}</option>
                    <option value="active">{{ t('catalog.status.active') }}</option>
                    <option value="inactive">{{ t('catalog.status.inactive') }}</option>
                </select>
            </label>
        </form>

        <LoadingState v-if="loading" label-key="catalog.loading" />
        <PermissionDeniedState v-else-if="denied" />
        <ErrorState v-else-if="failed" @retry="load" />
        <!-- "There are none" and "nothing matched" are different sentences, and
             only one of them is ever true. -->
        <EmptyState
            v-else-if="items.length === 0"
            :title-key="filtering ? 'catalog.empty.filtered.title' : 'state.empty.title'"
            :message-key="filtering ? 'catalog.empty.filtered.message' : 'state.empty.message'"
        />

        <div v-else class="flex flex-col gap-3">
            <div class="table-frame overflow-x-auto rounded-xl">
                <table class="w-full text-table" data-testid="catalog-table">
                    <!-- §6.5: a sticky header on long lists. The block axis does
                         not mirror, so `top` is correct in both directions. -->
                    <thead class="sticky top-0">
                        <tr class="table-head">
                            <th
                                scope="col"
                                class="p-3 text-start"
                                :aria-sort="ariaSort('name')"
                                data-testid="catalog-column-name"
                            >
                                <button
                                    type="button"
                                    class="sort-action min-h-11 rounded-lg px-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                    data-testid="catalog-sort-name"
                                    @click="sortBy('name')"
                                >
                                    {{ t('catalog.column.name') }}
                                    <span aria-hidden="true">{{ sortIndicator('name') }}</span>
                                </button>
                            </th>

                            <!-- §7.3's per-tab fields. §5.2's column priorities:
                                 the secondary ones fold away first. -->
                            <th
                                v-for="column in columns"
                                :key="column"
                                scope="col"
                                class="hidden p-3 text-start md:table-cell"
                                :data-testid="`catalog-column-${column}`"
                            >
                                {{ t(`catalog.column.${column}`) }}
                            </th>

                            <th scope="col" class="p-3 text-start">{{ t('catalog.column.status') }}</th>

                            <th
                                scope="col"
                                class="hidden p-3 text-start lg:table-cell"
                                :aria-sort="ariaSort('created_at')"
                                data-testid="catalog-column-created_at"
                            >
                                <button
                                    type="button"
                                    class="sort-action min-h-11 rounded-lg px-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                    data-testid="catalog-sort-created_at"
                                    @click="sortBy('created_at')"
                                >
                                    {{ t('catalog.column.added') }}
                                    <span aria-hidden="true">{{ sortIndicator('created_at') }}</span>
                                </button>
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <template v-for="row in rows" :key="row.item.id">
                            <!-- §7.3's grouping, drawn where the server's order
                                 changes company. `colgroup` scope, because the
                                 heading labels the rows beneath it. -->
                            <tr v-if="row.startsGroup" class="group-row" data-testid="catalog-group-heading">
                                <th :colspan="columnCount" scope="colgroup" class="p-3 text-start font-medium">
                                    {{ row.item.company ?? t('catalog.group.none') }}
                                </th>
                            </tr>

                            <tr class="table-row" data-testid="catalog-row">
                                <td class="p-3">{{ row.item.name ?? '—' }}</td>

                                <td v-for="column in columns" :key="column" class="hidden p-3 md:table-cell">
                                    {{ cell(row.item, column) }}
                                </td>

                                <td class="p-3">
                                    <!-- §6.4: never colour alone — the word is the state. -->
                                    <span class="status-chip rounded-full px-2 py-0.5" data-testid="catalog-status">
                                        {{ row.item.is_active ? t('catalog.status.active') : t('catalog.status.inactive') }}
                                    </span>
                                </td>

                                <!-- D-70: Inter draws Western digits in both locales;
                                     tabular-nums keeps a column of figures aligned. -->
                                <td class="hidden p-3 tabular-nums lg:table-cell">{{ addedOn(row.item.created_at) }}</td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <nav
                v-if="pagination"
                class="flex items-center justify-between gap-3"
                :aria-label="t('catalog.pagination.label')"
                data-testid="catalog-pagination"
            >
                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!pagination.has_previous_page || loading"
                    data-testid="catalog-previous"
                    @click="goToPage(pagination.page - 1)"
                >
                    {{ t('catalog.pagination.previous') }}
                </button>

                <p class="tabular-nums text-[var(--color-text-muted)]">
                    {{ t('catalog.pagination.position', { page: pagination.page, pages: pagination.total_pages, total: pagination.total }) }}
                </p>

                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!pagination.has_next_page || loading"
                    data-testid="catalog-next"
                    @click="goToPage(pagination.page + 1)"
                >
                    {{ t('catalog.pagination.next') }}
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

/* §7.3's group heading — a band, not a row that could be mistaken for data. */
.group-row {
    background-color: var(--color-surface-muted);
    border-block-start: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.tab-strip {
    border-block-end: 1px solid var(--color-border);
}

.tab {
    background-color: transparent;
    border: 1px solid transparent;
    color: var(--color-text-muted);
}

/* §6.4: the current tab carries weight and a border, not only a hue. */
.tab-current {
    background-color: var(--color-surface);
    border-color: var(--color-border);
    color: var(--color-text);
    font-weight: 600;
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
