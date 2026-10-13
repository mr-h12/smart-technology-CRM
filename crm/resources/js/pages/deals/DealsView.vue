<script setup lang="ts">
/**
 * §8's *Requests / Deals* screen — Design System §5.2's Table/List
 * (Module 5, Point 6.2).
 *
 * ── Why a table, when §5.2 assigns Deals the Kanban view ───────────────────
 *
 * §5.2's view table names Kanban for Deals and §9's criterion 4 names "a deal
 * Kanban board" outright. **This is a table, deliberately and temporarily.**
 * None of Module 5's five open acceptance criteria names a board, while §5.2's
 * own Table/List row asks for exactly the server pagination `DealListCriteria`
 * already implements. The board is owed its own point list and is on the debt
 * register — deferred, not dropped, and awaiting a `D-xx`.
 *
 * ── Everything is asked of the server, and the tests read the URL ──────────
 *
 * §5.2 requires "server-side filters/sort/search" and §6.5 that "Every list is
 * server-paginated". A client-side filter narrows the 25 rows in hand and
 * silently claims to have narrowed all of them, so every control here changes a
 * parameter and asks again, and the spec asserts on the query string rather
 * than on the rows.
 *
 * The declared surface is `DealListCriteria`'s: **four** filters (`status`,
 * `service_type`, `source`, `approval_status`), three sorts (`code`,
 * `created_at`, `last_activity_at`), `-last_activity_at` by default — and,
 * unlike Module 6's list, a **real search**: `SearchIndex::Deals` indexes
 * `title`, §4.3's one free-text field.
 *
 * ── The nav follows §3.4, not §8 (owner's ruling, 2026-08-31) ──────────────
 *
 * §8 lists Requests/Deals for the Manager, Team Leader, Outdoor Supervisor,
 * Indoor Sales and Procurement — and **not** for the CEO or Outdoor Sales,
 * while §3.4 grants the CEO `deal.view` as `All` and Outdoor Sales as `Own`.
 * This is the conflict Suppliers, Catalog and Supplier Quotations each hit, and
 * it takes the same answer: the route and the nav item both follow the
 * permission matrix, because keying the menu on §8 would leave a screen a
 * person may open with no way to reach it. Recorded in `CHECKLIST.md`,
 * **awaiting a `D-xx`**. `SEC-09` is unaffected — the API is still the gate.
 *
 * ── An empty list here can mean two different things ───────────────────────
 *
 * Unlike Module 6's shared screen, §3.4 is scoped: `All`, `Team`, `Out`, `Own`,
 * `Asgn`. ⚠️ **`Team`, `Out` and `Asgn` resolve to zero rows today** (Point
 * 2.1 — no team entity, no visit linkage, no procurement assignment column), so
 * a Team Leader, an Outdoor Supervisor and Procurement each hold `deal.view`
 * and are answered with an authenticated **200 and an empty page**, not a
 * refusal. The empty state cannot honestly say "there are no deals", and does
 * not: it says none are visible to you, which is true in both cases and is the
 * only thing this screen can know.
 *
 * ── The customer's name comes with the row; the owner's does not ──────────
 *
 * `D-83` (F-07 · 1.5): each list row carries `customer_name`, read by the
 * server once per page through Customers' contract, and the screen shows it
 * as sent — no join, no fallback branch (an unnamed customer arrives as its
 * id). Nothing here reads `/customers`: the create form's picker asks the
 * server for its own rows (`D-84`, F-08 · 1.3).
 *
 * ⚠️ **The owner is not resolved at all.** Identity publishes no list this
 * module may call for a name against an id, so the owner column shows the
 * identifier. A bare id is the honest option; inventing a name is not.
 * Registered as debt.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import { ApiError } from '@/api';
import EmptyState from '@/components/states/EmptyState.vue';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import {
    DEAL_APPROVAL_STATUSES,
    DEAL_SERVICE_TYPES,
    DEAL_SOURCES,
    DEAL_STATUSES,
    listDealOwners,
    listDeals,
    type Deal,
    type DealRow,
    type Pagination,
} from '@/services/deals';
import DealApprovalControls from '@/pages/deals/DealApprovalControls.vue';
import DealFormModal from '@/pages/deals/DealFormModal.vue';
import DealStatusChip from '@/pages/deals/DealStatusChip.vue';
import DealStatusControl from '@/pages/deals/DealStatusControl.vue';
import { useAuth } from '@/stores/auth';

const { t, locale } = useI18n();
const auth = useAuth();

/**
 * §3.4 gives `create` and `edit` separate rows with different columns — the
 * CEO holds neither, Procurement holds `edit` and not `create` — so the two
 * controls are drawn from two computeds and never from one "may write".
 * §6.2: one primary action per context, drawn only for the permission that can
 * complete it.
 */
const canCreate = computed(() => auth.hasPermission('deal.create'));
const canEdit = computed(() => auth.hasPermission('deal.edit'));
/** `D-108`: offered at `all` only (Manager, CEO); the server's row scope applies to anyone who sends it. */
const canFilterOwner = computed(() => auth.hasPermission('deal.view.all'));

const formOpen = ref(false);
const editing = ref<Deal | null>(null);

type SortField = 'code' | 'created_at' | 'last_activity_at';

const deals = ref<DealRow[]>([]);
const pagination = ref<Pagination | null>(null);

const loading = ref(true);
const failed = ref(false);
const denied = ref(false);

const page = ref(1);
const search = ref('');
const statusFilter = ref('');
const serviceTypeFilter = ref('');
const sourceFilter = ref('');
const approvalFilter = ref('');
const ownerFilter = ref('');
const owners = ref<{ id: string; name: string }[]>([]);

const sortField = ref<SortField>('last_activity_at');
/** The server's own default is `-last_activity_at`: newest activity first. */
const sortDescending = ref(true);

const statuses = DEAL_STATUSES;
const sources = DEAL_SOURCES;
const serviceTypes = DEAL_SERVICE_TYPES;
const approvalStatuses = DEAL_APPROVAL_STATUSES;

const total = computed(() => pagination.value?.total ?? 0);
const sortParameter = computed(() => `${sortDescending.value ? '-' : ''}${sortField.value}`);
const filtering = computed(
    () =>
        search.value !== '' ||
        statusFilter.value !== '' ||
        serviceTypeFilter.value !== '' ||
        sourceFilter.value !== '' ||
        approvalFilter.value !== '' ||
        ownerFilter.value !== '',
);

/**
 * A stored code rendered through the dictionary — never a server-side label.
 * `DealPayload` sends `status`, `source`, `service_type` and `approval_status`
 * as their stored codes precisely so they stay usable as filter values.
 */
function statusLabel(code: string): string {
    return t(`deals.status.${code}`);
}

function approvalLabel(code: string | null): string {
    // Null is Flow 1: never submitted for approval, which is not the same fact
    // as "pending" and must not be drawn as one.
    return code === null ? '—' : t(`deals.approval.${code}`);
}

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    denied.value = false;

    try {
        const result = await listDeals({
            page: page.value,
            sort: sortParameter.value,
            q: search.value === '' ? null : search.value,
            status: statusFilter.value === '' ? null : statusFilter.value,
            serviceType: serviceTypeFilter.value === '' ? null : serviceTypeFilter.value,
            source: sourceFilter.value === '' ? null : sourceFilter.value,
            approvalStatus: approvalFilter.value === '' ? null : approvalFilter.value,
            owner: ownerFilter.value === '' ? null : ownerFilter.value,
        });

        deals.value = result.items;
        pagination.value = result.pagination;
    } catch (error) {
        // A 403 and a 500 are different answers and get different screens: one
        // is a boundary and the other is a fault. `SEC-09` — drawing a refusal
        // as an empty list would read as "there are no deals", which is a lie.
        denied.value = error instanceof ApiError && error.status === 403;
        failed.value = !denied.value;
    } finally {
        loading.value = false;
    }
}

/** Any change to the question invalidates the page number. */
async function applyFilters(): Promise<void> {
    page.value = 1;
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

/** `DB-08`: stored UTC, shown in the reader's locale. */
function onDate(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return new Date(value).toLocaleDateString(locale.value === 'ar' ? 'ar-EG' : 'en-GB');
}

/**
 * F-41 · 1.5 (the owner, 2026-10-11): hover shows a deal's whole details, a click
 * keeps them open until a click elsewhere. `popover="auto"` is the browser's own
 * light dismiss (outside click, Escape); this only pins and places it.
 */
const pinnedDetails = ref<string | null>(null);

function popoverAfter(trigger: EventTarget | null): HTMLElement {
    return (trigger as HTMLElement).nextElementSibling as HTMLElement;
}

function openDetails(event: Event): void {
    const popover = popoverAfter(event.currentTarget);

    if (popover.matches(':popover-open')) {
        return;
    }

    // Under the cell, from its start edge; fixed, because the top layer is. Both
    // edges every time, or the other language's edge squeezes it.
    // ponytail: no flip above near the viewport's bottom; the page scrolls.
    const cell = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const rtl = locale.value === 'ar';
    popover.style.top = `${cell.bottom + 4}px`;
    popover.style.right = rtl ? `${window.innerWidth - cell.right}px` : 'auto';
    popover.style.left = rtl ? 'auto' : `${cell.left}px`;
    popover.showPopover();
}

function closeDetails(event: Event, dealId: string): void {
    const popover = popoverAfter(event.currentTarget);

    if (pinnedDetails.value !== dealId && popover.matches(':popover-open')) {
        popover.hidePopover();
    }
}

function pinDetails(event: Event, dealId: string): void {
    pinnedDetails.value = dealId;
    openDetails(event);
}

function onDetailsToggle(event: Event, dealId: string): void {
    if ((event as ToggleEvent).newState === 'closed' && pinnedDetails.value === dealId) {
        pinnedDetails.value = null;
    }
}

function startCreate(): void {
    editing.value = null;
    formOpen.value = true;
}

function startEdit(deal: Deal): void {
    editing.value = deal;
    formOpen.value = true;
}

/**
 * The saved deal may no longer belong on the page in view — a changed title
 * moves it under `q`, and the write refreshes `last_activity_at`, which is the
 * default sort — so the list is asked again rather than patched in place
 * (§5.2, §6.5).
 */
/**
 * A decision changes `approval_status` and touches the row's activity, and the
 * `filter[approval_status]` in force may no longer match it — so the list is
 * asked again rather than patched, for the same reason a save is.
 */
async function onDecided(): Promise<void> {
    await load();
}

async function onSaved(): Promise<void> {
    formOpen.value = false;

    await load();
}

onMounted(async () => {
    // The choices are a convenience beside the list: if they fail, the select
    // stays hidden and the list still answers on its own.
    if (canFilterOwner.value) {
        listDealOwners().then((rows) => (owners.value = rows), () => undefined);
    }

    await load();
});
</script>

<template>
    <section class="flex flex-col gap-4">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-page-title">{{ t('deals.title') }}</h1>

            <p
                v-if="!loading && !failed && !denied"
                data-testid="deals-count"
                class="tabular-nums text-[var(--color-text-muted)]"
            >
                {{ t('deals.total', { count: total }) }}
            </p>

            <!-- §6.2: one primary action per context, drawn only for the
                 permission that can complete it. §3.4 gives the CEO no
                 `create` cell at all. -->
            <button
                v-if="canCreate"
                type="button"
                class="create-action min-h-11 rounded-lg px-4 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                data-testid="deals-create"
                @click="startCreate()"
            >
                {{ t('deals.form.createTitle') }}
            </button>
        </header>

        <form class="flex flex-wrap items-end gap-3" data-testid="deals-filters" @submit.prevent="applyFilters">
            <!-- Unlike Module 6's list, a search box is a control with a server
                 behind it: `SearchIndex::Deals` indexes §4.3's `title`. -->
            <label class="flex flex-col gap-1">
                <span>{{ t('deals.filter.search') }}</span>
                <input
                    v-model="search"
                    type="search"
                    class="form-field min-h-11 rounded-lg px-3"
                    :placeholder="t('deals.filter.searchPlaceholder')"
                    data-testid="deals-filter-search"
                />
            </label>

            <label class="flex flex-col gap-1">
                <span>{{ t('deals.filter.status') }}</span>
                <select
                    v-model="statusFilter"
                    class="form-field min-h-11 rounded-lg px-3"
                    data-testid="deals-filter-status"
                    @change="applyFilters"
                >
                    <option value="">{{ t('deals.filter.statusAll') }}</option>
                    <option v-for="code in statuses" :key="code" :value="code">{{ statusLabel(code) }}</option>
                </select>
            </label>

            <label v-if="canFilterOwner && owners.length > 0" class="flex flex-col gap-1">
                <span>{{ t('deals.column.owner') }}</span>
                <select
                    v-model="ownerFilter"
                    class="form-field min-h-11 rounded-lg px-3"
                    data-testid="deals-filter-owner"
                    @change="applyFilters"
                >
                    <option value="">{{ t('deals.filter.ownerAll') }}</option>
                    <option v-for="owner in owners" :key="owner.id" :value="owner.id">{{ owner.name }}</option>
                </select>
            </label>

            <label class="flex flex-col gap-1">
                <span>{{ t('deals.filter.approval') }}</span>
                <select
                    v-model="approvalFilter"
                    class="form-field min-h-11 rounded-lg px-3"
                    data-testid="deals-filter-approval"
                    @change="applyFilters"
                >
                    <option value="">{{ t('deals.filter.approvalAll') }}</option>
                    <option v-for="code in approvalStatuses" :key="code" :value="code">{{ t(`deals.approval.${code}`) }}</option>
                </select>
            </label>

            <label class="flex flex-col gap-1">
                <span>{{ t('deals.filter.serviceType') }}</span>
                <select
                    v-model="serviceTypeFilter"
                    class="form-field min-h-11 rounded-lg px-3"
                    data-testid="deals-filter-service-type"
                    @change="applyFilters"
                >
                    <option value="">{{ t('deals.filter.serviceTypeAll') }}</option>
                    <option v-for="code in serviceTypes" :key="code" :value="code">{{ t(`deals.serviceType.${code}`) }}</option>
                </select>
            </label>

            <label class="flex flex-col gap-1">
                <span>{{ t('deals.filter.source') }}</span>
                <select
                    v-model="sourceFilter"
                    class="form-field min-h-11 rounded-lg px-3"
                    data-testid="deals-filter-source"
                    @change="applyFilters"
                >
                    <option value="">{{ t('deals.filter.sourceAll') }}</option>
                    <option v-for="code in sources" :key="code" :value="code">{{ t(`deals.source.${code}`) }}</option>
                </select>
            </label>

            <button
                type="submit"
                class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                data-testid="deals-filter-apply"
            >
                {{ t('deals.filter.apply') }}
            </button>
        </form>

        <LoadingState v-if="loading" label-key="deals.loading" />
        <PermissionDeniedState v-else-if="denied" />
        <ErrorState v-else-if="failed" @retry="load" />
        <!-- ⚠️ "None are visible to you", never "there are none": `Team`, `Out`
             and `Asgn` resolve to no rows, so an empty page is as likely to be
             a scope with no mechanism as an empty table. -->
        <EmptyState
            v-else-if="deals.length === 0"
            :title-key="filtering ? 'deals.empty.filtered.title' : 'deals.empty.title'"
            :message-key="filtering ? 'deals.empty.filtered.message' : 'deals.empty.message'"
        />

        <div v-else class="flex flex-col gap-3">
            <div class="table-frame overflow-x-auto rounded-xl">
                <table class="w-full text-table" data-testid="deals-table">
                    <thead class="sticky top-0">
                        <tr class="table-head">
                            <th
                                scope="col"
                                class="p-3 text-start"
                                :aria-sort="ariaSort('code')"
                                data-testid="deals-column-code"
                            >
                                <button
                                    type="button"
                                    class="sort-action min-h-11 rounded-lg px-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                    data-testid="deals-sort-code"
                                    @click="sortBy('code')"
                                >
                                    {{ t('deals.column.code') }}
                                    <span aria-hidden="true">{{ sortIndicator('code') }}</span>
                                </button>
                            </th>
                            <th scope="col" class="p-3 text-start">{{ t('deals.column.customer') }}</th>
                            <th scope="col" class="hidden p-3 text-start lg:table-cell" data-testid="deals-details-header">{{ t('deals.column.details') }}</th>
                            <th scope="col" class="p-3 text-start">{{ t('deals.column.status') }}</th>
                            <th scope="col" class="p-3 text-start">{{ t('deals.column.approval') }}</th>

                            <th
                                scope="col"
                                class="p-3 text-start"
                                :aria-sort="ariaSort('last_activity_at')"
                                data-testid="deals-column-last_activity_at"
                            >
                                <button
                                    type="button"
                                    class="sort-action min-h-11 rounded-lg px-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                    data-testid="deals-sort-last_activity_at"
                                    @click="sortBy('last_activity_at')"
                                >
                                    {{ t('deals.column.lastActivity') }}
                                    <span aria-hidden="true">{{ sortIndicator('last_activity_at') }}</span>
                                </button>
                            </th>

                            <!-- §5.2's column priorities: secondary columns fold first. -->
                            <th scope="col" class="hidden p-3 text-start md:table-cell">{{ t('deals.column.serviceType') }}</th>
                            <th scope="col" class="hidden p-3 text-start lg:table-cell">{{ t('deals.column.owner') }}</th>
                            <th v-if="canEdit" scope="col" class="p-3 text-start">
                                <span class="sr-only">{{ t('action.edit') }}</span>
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-for="deal in deals" :key="deal.id" class="table-row" data-testid="deals-row">
                            <!-- §4.7's `DL-2026-0001`, exactly as the server allocated it,
                                 and the way into §5.2's Detail view (Point 6.6). A named
                                 route and never a built path — `LogicalPropertiesTest`
                                 fails if a nav item names a route `app.ts` does not
                                 register, and the same discipline applies here. -->
                            <td class="p-3 tabular-nums" data-testid="deals-code">
                                <RouterLink
                                    :to="{ name: 'deal-detail', params: { id: deal.id } }"
                                    class="row-link"
                                    data-testid="deals-row-link"
                                >
                                    {{ deal.code }}
                                </RouterLink>
                            </td>
                            <!-- D-109: the customer and the request in one cell, the name first. -->
                            <td class="p-3" data-testid="deals-customer-cell">
                                <span class="block" data-testid="deals-customer">{{ deal.customer_name }}</span>
                                <span class="block text-[var(--color-text-muted)]" dir="auto" data-testid="deals-request">{{ deal.title ?? '—' }}</span>
                            </td>
                            <!-- D-108: shortened to one line; the whole text in a pop-up. -->
                            <td class="hidden p-3 lg:table-cell" data-testid="deals-details">
                                <template v-if="deal.details !== null">
                                    <button
                                        type="button"
                                        class="details-trigger rounded text-start focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                        data-testid="deals-details-trigger"
                                        @mouseenter="openDetails($event)"
                                        @mouseleave="closeDetails($event, deal.id)"
                                        @click="pinDetails($event, deal.id)"
                                    >
                                        <span class="block max-w-32 truncate" dir="auto">{{ deal.details }}</span>
                                    </button>
                                    <div
                                        popover="auto"
                                        dir="auto"
                                        class="details-popover inset-auto m-0 max-w-sm whitespace-pre-line rounded-lg p-3 text-pretty"
                                        data-testid="deals-details-popover"
                                        @toggle="onDetailsToggle($event, deal.id)"
                                    >{{ deal.details }}</div>
                                </template>
                                <template v-else>—</template>
                            </td>
                            <td class="p-3" data-testid="deals-status">
                                <DealStatusChip :status="deal.status" data-testid="deals-status-chip" />
                                <DealStatusControl :deal="deal" @changed="onDecided" />
                            </td>
                            <td class="p-3" data-testid="deals-approval">
                                <!-- Flow 1's null draws no badge at all: never
                                     submitted is not the same fact as waiting. -->
                                <span v-if="deal.approval_status === null">{{ approvalLabel(null) }}</span>
                                <DealApprovalControls v-else :deal="deal" @decided="onDecided" />
                            </td>
                            <td class="p-3 tabular-nums">{{ onDate(deal.last_activity_at) }}</td>
                            <td class="hidden p-3 md:table-cell">
                                {{ deal.service_type === null ? '—' : t(`deals.serviceType.${deal.service_type}`) }}
                            </td>
                            <!-- D-109: the server's name; «—» for the hidden Super Admin,
                                 a deleted account or no owner — never the identifier. -->
                            <td class="hidden p-3 lg:table-cell" data-testid="deals-owner">{{ deal.owner_name ?? '—' }}</td>

                            <td v-if="canEdit" class="p-3">
                                <button
                                    type="button"
                                    class="ghost-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                                    data-testid="deals-row-edit"
                                    @click="startEdit(deal)"
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
                :aria-label="t('deals.pagination.label')"
                data-testid="deals-pagination"
            >
                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!pagination.has_previous_page || loading"
                    data-testid="deals-previous"
                    @click="goToPage(pagination.page - 1)"
                >
                    {{ t('deals.pagination.previous') }}
                </button>

                <p class="tabular-nums text-[var(--color-text-muted)]">
                    {{ t('deals.pagination.position', { page: pagination.page, pages: pagination.total_pages, total: pagination.total }) }}
                </p>

                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!pagination.has_next_page || loading"
                    data-testid="deals-next"
                    @click="goToPage(pagination.page + 1)"
                >
                    {{ t('deals.pagination.next') }}
                </button>
            </nav>
        </div>

        <DealFormModal
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

.row-link {
    color: var(--color-primary);
    text-decoration: underline;
}

.details-trigger {
    color: inherit;
    cursor: help;
}

.details-popover {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border);
    box-shadow: var(--shadow-1);
    color: var(--color-text);
}

.row-action {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

/* §6.2's Ghost: a low-emphasis table action — no fill, no frame until hovered. */
.ghost-action {
    color: var(--color-link);
}

.ghost-action:hover {
    background-color: var(--color-surface);
}

.create-action {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
}
</style>
