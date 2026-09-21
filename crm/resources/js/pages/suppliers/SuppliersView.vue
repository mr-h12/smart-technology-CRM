<script setup lang="ts">
/**
 * §8's *Suppliers* screen — Design System §5.2's Table/List (Point 4.1).
 *
 * ── Everything is asked of the server, and the tests read the URL ──────────
 *
 * §5.2 requires "server-side filters/sort/search" and §6.5 that "Every list is
 * server-paginated. Do not create a UI that requires loading all records."
 * Those are one rule seen twice: a client-side filter narrows the 25 rows in
 * hand and silently claims to have narrowed all of them. So every control here
 * changes a parameter and asks again, and `SuppliersView.spec.ts` asserts on
 * the query string rather than on the rendered rows.
 *
 * The declared surface is `SupplierListCriteria`'s — `ALLOWED_FILTERS` is
 * `color_rating · type · is_active · has_open_account`, `ALLOWED_SORTS` is
 * `name · created_at`, `DEFAULT_SORT` is `name` — and `OpenAPI §6.2` answers
 * anything else with a 400, so a control this screen invents breaks it.
 *
 * ── §3.7 has no scope, so this screen has no scope story ───────────────────
 *
 * `CustomersView` explains which of five row scopes a caller holds. §3.7 grants
 * `Scope::All` to every role in both its columns, so there is nothing to
 * explain: everyone who reaches this screen sees every supplier. That is why
 * there is no "no rows for your scope" case here — an empty list means the
 * table is empty.
 *
 * ── The sidebar follows §3.7 too (owner's ruling, 2026-08-31) ──────────────
 *
 * §8 gives the CEO no Suppliers screen while §3.7 grants them `catalog.view`.
 * The route and the nav item both follow §3.7; keying the menu on §8's screen
 * list would leave a screen a person may open with no way to reach it, which is
 * the mirror image of the defect §5.1 forbids. Recorded in `CHECKLIST.md`
 * awaiting a `D-xx`. `SEC-09` is unaffected: the API is still the gate.
 *
 * ── §7.1's chip, which is why this screen matters to the criterion ─────────
 *
 * "The colour appears as a chip beside the supplier name on **every** screen."
 * This is the first screen there is, so it is the first place that sentence can
 * be true. Design System §6.4 requires the chip to carry a word, which
 * `SupplierRatingChip` does.
 *
 * ── One permission draws all four write controls (Point 4.2) ───────────────
 *
 * §3.7's `catalog.manage` covers create, edit, deactivate and set colour in a
 * single cell, so `canManage` draws every control below and there is no second
 * permission to ask about — and no action route either: `is_active` and
 * `color_rating` travel as fields on the form's PATCH.
 *
 * The CEO is the documented negative case. §3.7 grants them `catalog.view` and
 * annotates the write column "read-only", so they reach this screen through the
 * sidebar and see no Edit button on it. `SEC-09` holds regardless: the button
 * is a menu and `SupplierWriteEndpointTest` is the gate.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import EmptyState from '@/components/states/EmptyState.vue';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import ImportModal from '@/components/imports/ImportModal.vue';
import SupplierRatingChip from '@/components/suppliers/SupplierRatingChip.vue';
import { importSuppliers, listSuppliers, type Pagination, type Supplier } from '@/services/suppliers';
import SupplierFormModal from '@/pages/suppliers/SupplierFormModal.vue';
import { useAuth } from '@/stores/auth';

/**
 * §7.1's colour meanings and its two types, restated here because both are a
 * database CHECK rather than a managed list — there is no endpoint to ask.
 * `SupplierDraft::RATINGS` and `::TYPES` are the originals; a value missing
 * from here narrows a dropdown, it does not break one.
 */
const RATINGS = ['green', 'yellow', 'red', 'white'] as const;
const TYPES = ['supplier', 'distributor'] as const;

const { t, locale } = useI18n();
const auth = useAuth();

/** §3.7's write row is one cell, so one permission draws all four verbs. */
const canManage = computed(() => auth.hasPermission('catalog.manage'));

/** `D-85`: `catalog.import` is the Manager's alone, although `catalog.manage` is not. */
const canImport = computed(() => auth.hasPermission('catalog.import'));

const importOpen = ref(false);

const formOpen = ref(false);
const editing = ref<Supplier | null>(null);

const suppliers = ref<Supplier[]>([]);
const pagination = ref<Pagination | null>(null);
const loading = ref(true);
const failed = ref(false);
const denied = ref(false);

const page = ref(1);
const search = ref('');
const ratingFilter = ref('');
const typeFilter = ref('');

/**
 * Three positions, not two. §10.4 hides a deactivated supplier from **selection
 * lists** — Modules 6 and 7 — and not from this management screen, and the
 * server's `filter[is_active]` is tri-state for the same reason. A screen that
 * hid them by default would be one nobody could reactivate from.
 */
const activeFilter = ref<'' | 'active' | 'inactive'>('');

/** `D-85`/§10's dedicated filter. Unticked asks nothing about the flag, never `false`. */
const incompleteOnly = ref(false);

/** `SupplierListCriteria::DEFAULT_SORT`. */
const sortField = ref<string>('name');
const sortDescending = ref(false);

const total = computed(() => pagination.value?.total ?? 0);

/** Which of the two empty states is true: "there are none" or "none matched". */
const filtering = computed(
    () => search.value !== '' || ratingFilter.value !== '' || typeFilter.value !== '' || activeFilter.value !== '' || incompleteOnly.value,
);

/** §6.2: "Comma-separated allowed fields. Prefix `-` means descending." */
const sortParameter = computed(() => `${sortDescending.value ? '-' : ''}${sortField.value}`);

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    denied.value = false;

    try {
        const result = await listSuppliers({
            page: page.value,
            sort: sortParameter.value,
            q: search.value === '' ? null : search.value,
            colorRating: ratingFilter.value === '' ? null : ratingFilter.value,
            type: typeFilter.value === '' ? null : typeFilter.value,
            // `null`, never `false`, when unset. An unset select asks nothing
            // about the column; `false` would ask for the deactivated ones only.
            isActive: activeFilter.value === '' ? null : activeFilter.value === 'active',
            isIncomplete: incompleteOnly.value ? true : null,
        });

        suppliers.value = result.items;
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

/** `DB-08` — stored UTC, shown in the reader's own timezone. */
function addedOn(timestamp: string): string {
    return new Date(timestamp).toLocaleDateString(locale.value);
}

function startCreate(): void {
    editing.value = null;
    formOpen.value = true;
}

function startEdit(supplier: Supplier): void {
    editing.value = supplier;
    formOpen.value = true;
}

/**
 * The saved row may no longer belong on the page in view — a rename moves it
 * under `sort=name`, a deactivation drops it out of `filter[is_active]` — so
 * the list is asked again rather than patched in place (§5.2, §6.5).
 */
async function onSaved(): Promise<void> {
    formOpen.value = false;

    await load();
}

/** The customers' `onShowIncomplete`: close the dialog, then show the rows it flagged. */
async function onShowIncomplete(): Promise<void> {
    importOpen.value = false;
    incompleteOnly.value = true;

    await applyFilters();
}

onMounted(load);
</script>

<template>
    <section class="flex flex-col gap-4">
        <header class="flex flex-wrap items-baseline justify-between gap-2">
            <h1 class="text-page-title">{{ t('suppliers.title') }}</h1>

            <p
                v-if="!loading && !failed && !denied"
                data-testid="suppliers-total"
                class="tabular-nums text-[var(--color-text-muted)]"
            >
                {{ t('suppliers.total', { count: total }) }}
            </p>

            <!-- One group, so `justify-between` spreads three items and not
                 four: the import sits beside *New supplier*, as on Customers. -->
            <div class="flex flex-wrap items-baseline gap-3">
                <!-- `D-85`: the Manager's alone. §6.2 allows one Primary per
                     context and *New supplier* is it, so this is Secondary. -->
                <button
                    v-if="canImport"
                    type="button"
                    class="row-action min-h-11 rounded-lg px-4 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="suppliers-import"
                    @click="importOpen = true"
                >
                    {{ t('suppliers.import.title') }}
                </button>

                <!-- §6.2: one primary action per context, drawn only for the
                     permission that can complete it. -->
                <button
                    v-if="canManage"
                    type="button"
                    class="create-action min-h-11 rounded-lg px-4 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="suppliers-create"
                    @click="startCreate()"
                >
                    {{ t('suppliers.form.createTitle') }}
                </button>
            </div>
        </header>

        <!-- §5.2's "server-side filters/sort/search". Every control below sends a
             declared parameter and nothing narrows anything in the browser. -->
        <form class="flex flex-wrap items-end gap-3" data-testid="suppliers-search-form" @submit.prevent="applyFilters">
            <label class="flex flex-col gap-1.5">
                <span>{{ t('suppliers.filter.search') }}</span>
                <input
                    v-model="search"
                    type="search"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="suppliers-search"
                />
            </label>

            <button
                type="submit"
                class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                data-testid="suppliers-search-submit"
            >
                {{ t('suppliers.filter.searchAction') }}
            </button>

            <label class="flex flex-col gap-1.5">
                <span>{{ t('suppliers.filter.rating') }}</span>
                <select
                    v-model="ratingFilter"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="suppliers-filter-rating"
                    @change="applyFilters"
                >
                    <option value="">{{ t('suppliers.filter.ratingAll') }}</option>
                    <option v-for="code in RATINGS" :key="code" :value="code">{{ t(`suppliers.rating.${code}`) }}</option>
                </select>
            </label>

            <label class="flex flex-col gap-1.5">
                <span>{{ t('suppliers.filter.type') }}</span>
                <select
                    v-model="typeFilter"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="suppliers-filter-type"
                    @change="applyFilters"
                >
                    <option value="">{{ t('suppliers.filter.typeAll') }}</option>
                    <option v-for="code in TYPES" :key="code" :value="code">{{ t(`suppliers.type.${code}`) }}</option>
                </select>
            </label>

            <!-- Three positions, because §10.4's hiding belongs to a selection
                 list and not to this screen. -->
            <label class="flex flex-col gap-1.5">
                <span>{{ t('suppliers.filter.active') }}</span>
                <select
                    v-model="activeFilter"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="suppliers-filter-active"
                    @change="applyFilters"
                >
                    <option value="">{{ t('suppliers.filter.activeAll') }}</option>
                    <option value="active">{{ t('suppliers.status.active') }}</option>
                    <option value="inactive">{{ t('suppliers.status.inactive') }}</option>
                </select>
            </label>

            <label class="flex min-h-11 items-center gap-2">
                <input
                    v-model="incompleteOnly"
                    type="checkbox"
                    class="size-4 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="suppliers-filter-incomplete"
                    @change="applyFilters"
                />
                <span>{{ t('suppliers.filter.incomplete') }}</span>
            </label>
        </form>

        <LoadingState v-if="loading" label-key="suppliers.loading" />
        <PermissionDeniedState v-else-if="denied" />
        <ErrorState v-else-if="failed" @retry="load" />
        <!-- "There are no suppliers" and "nothing matched" are different
             sentences, and only one of them is ever true. -->
        <EmptyState
            v-else-if="suppliers.length === 0"
            :title-key="filtering ? 'suppliers.empty.filtered.title' : 'state.empty.title'"
            :message-key="filtering ? 'suppliers.empty.filtered.message' : 'state.empty.message'"
        />

        <div v-else class="flex flex-col gap-3">
            <div class="table-frame overflow-x-auto rounded-xl">
                <table class="w-full text-table" data-testid="suppliers-table">
                    <!-- §6.5: a sticky header on long lists. The block axis does
                         not mirror, so `top` is correct in both directions. -->
                    <thead class="sticky top-0">
                        <tr class="table-head">
                            <th
                                scope="col"
                                class="p-3 text-start"
                                :aria-sort="ariaSort('name')"
                                data-testid="suppliers-column-name"
                            >
                                <button
                                    type="button"
                                    class="sort-action min-h-11 rounded-lg px-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                    data-testid="suppliers-sort-name"
                                    @click="sortBy('name')"
                                >
                                    {{ t('suppliers.column.name') }}
                                    <span aria-hidden="true">{{ sortIndicator('name') }}</span>
                                </button>
                            </th>

                            <th scope="col" class="p-3 text-start">{{ t('suppliers.column.rating') }}</th>

                            <!-- §5.2's column priorities: the secondary columns
                                 fold away first on a narrow viewport. -->
                            <th scope="col" class="hidden p-3 text-start md:table-cell">{{ t('suppliers.column.type') }}</th>
                            <th scope="col" class="hidden p-3 text-start md:table-cell">{{ t('suppliers.column.contact') }}</th>
                            <th scope="col" class="hidden p-3 text-start md:table-cell">{{ t('suppliers.column.phone') }}</th>
                            <th scope="col" class="hidden p-3 text-start lg:table-cell">{{ t('suppliers.column.account') }}</th>
                            <th scope="col" class="p-3 text-start">{{ t('suppliers.column.status') }}</th>

                            <th
                                scope="col"
                                class="hidden p-3 text-start lg:table-cell"
                                :aria-sort="ariaSort('created_at')"
                                data-testid="suppliers-column-created_at"
                            >
                                <button
                                    type="button"
                                    class="sort-action min-h-11 rounded-lg px-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                    data-testid="suppliers-sort-created_at"
                                    @click="sortBy('created_at')"
                                >
                                    {{ t('suppliers.column.added') }}
                                    <span aria-hidden="true">{{ sortIndicator('created_at') }}</span>
                                </button>
                            </th>

                            <!-- §5.2's Detail/Form controls: "action controls only by permission". -->
                            <th v-if="canManage" scope="col" class="p-3 text-start">
                                <span class="sr-only">{{ t('suppliers.column.actions') }}</span>
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-for="supplier in suppliers" :key="supplier.id" class="table-row" data-testid="suppliers-row">
                            <td class="p-3">
                                {{ supplier.name }}
                                <!-- `D-31`: the word is the flag; §6.4 never colour alone. -->
                                <span
                                    v-if="supplier.is_incomplete"
                                    class="ms-2 whitespace-nowrap rounded-full border border-[var(--color-warning)] bg-[var(--color-surface-muted)] px-2 py-0.5"
                                    data-testid="suppliers-incomplete"
                                >{{ t('suppliers.incomplete') }}</span>
                            </td>
                            <td class="p-3">
                                <!-- §7.1: "beside the supplier name on every screen". -->
                                <SupplierRatingChip :rating="(supplier.color_rating as 'green' | 'yellow' | 'red' | 'white')" />
                            </td>
                            <td class="hidden p-3 md:table-cell">
                                {{ supplier.type === null ? '—' : t(`suppliers.type.${supplier.type}`) }}
                            </td>
                            <td class="hidden p-3 md:table-cell">{{ supplier.contact_person ?? '—' }}</td>
                            <!-- D-70: Inter draws Western digits in both locales;
                                 tabular-nums keeps a column of figures aligned. -->
                            <td class="hidden p-3 tabular-nums md:table-cell">{{ supplier.phone ?? '—' }}</td>
                            <td class="hidden p-3 lg:table-cell">
                                {{ supplier.has_open_account ? t('suppliers.account.open') : t('suppliers.account.none') }}
                            </td>
                            <td class="p-3">
                                <!-- §6.4: never colour alone — the word is the state. -->
                                <span class="status-chip rounded-full px-2 py-0.5" data-testid="suppliers-status">
                                    {{ supplier.is_active ? t('suppliers.status.active') : t('suppliers.status.inactive') }}
                                </span>
                            </td>
                            <td class="hidden p-3 tabular-nums lg:table-cell">{{ addedOn(supplier.created_at) }}</td>

                            <td v-if="canManage" class="p-3">
                                <button
                                    type="button"
                                    class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                    data-testid="suppliers-row-edit"
                                    @click="startEdit(supplier)"
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
                :aria-label="t('suppliers.pagination.label')"
                data-testid="suppliers-pagination"
            >
                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!pagination.has_previous_page || loading"
                    data-testid="suppliers-previous"
                    @click="goToPage(pagination.page - 1)"
                >
                    {{ t('suppliers.pagination.previous') }}
                </button>

                <p class="tabular-nums text-[var(--color-text-muted)]">
                    {{ t('suppliers.pagination.position', { page: pagination.page, pages: pagination.total_pages, total: pagination.total }) }}
                </p>

                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!pagination.has_next_page || loading"
                    data-testid="suppliers-next"
                    @click="goToPage(pagination.page + 1)"
                >
                    {{ t('suppliers.pagination.next') }}
                </button>
            </nav>
        </div>

        <ImportModal
            :open="importOpen"
            :title="t('suppliers.import.title')"
            :upload="importSuppliers"
            @imported="load()"
            @show-incomplete="onShowIncomplete"
            @cancel="importOpen = false"
        />

        <SupplierFormModal
            :open="formOpen"
            :editing="editing"
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

/* §6.2's Primary: the one main permitted action on this screen. */
.create-action {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
}

.status-chip {
    background-color: color-mix(in srgb, var(--color-status-neutral) 14%, transparent);
    color: var(--color-status-neutral);
}
</style>
