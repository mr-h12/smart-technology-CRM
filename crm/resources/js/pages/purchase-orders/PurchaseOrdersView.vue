<script setup lang="ts">
/**
 * The purchase orders — §4.6 (`D-53`), Module 10 · 3.3.
 *
 * `GET /purchase-orders` (2.2) under `quotation.view`: a purchase order has no
 * permission of its own and is read by whoever may view its quotation, scoped
 * through the deal (Q10). §8 names no such screen; the route and the sidebar
 * item follow the matrix, as the Catalog's did (owner, 2026-09-23, Q-A).
 *
 * **"Search works on both numbers."** One box, one `q`: the server searches
 * `po_number` and `customer_po_reference` alike (`SearchIndex::PurchaseOrders`),
 * so the screen never guesses which number was typed.
 *
 * Deliberately small (Q-C): a search and previous/next, on the server's own
 * `-created_at`. No sort headers — the point asks for search — and so less of
 * the list boilerplate on the debt register is copied here. A row links to its
 * quotation, where the order and its files live (Q-B); there is no PO page.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import { ApiError, type Pagination } from '@/api';
import EmptyState from '@/components/states/EmptyState.vue';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import { displayDate } from '@/domain/displayDate';
import { displayDecimals } from '@/domain/displayDecimals';
import { listPurchaseOrders, type PurchaseOrderSummary } from '@/services/quotations';

const { t, locale } = useI18n();

const orders = ref<PurchaseOrderSummary[]>([]);
const pagination = ref<Pagination | null>(null);

const loading = ref(true);
const failed = ref(false);
const denied = ref(false);

const page = ref(1);
const search = ref('');
/** The question the rows on screen answer — not what is typed in the box since. */
const searched = ref('');

const searching = computed(() => searched.value !== '');

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    denied.value = false;

    try {
        const result = await listPurchaseOrders({ page: page.value, q: searched.value });

        orders.value = result.items;
        pagination.value = result.pagination;
    } catch (error) {
        // `SEC-09`: a refusal drawn as an empty list would read "there are none".
        denied.value = error instanceof ApiError && error.status === 403;
        failed.value = !denied.value;
    } finally {
        loading.value = false;
    }
}

/** A new question starts again on page one. */
async function applySearch(): Promise<void> {
    searched.value = search.value.trim();
    page.value = 1;
    await load();
}

async function goToPage(target: number): Promise<void> {
    page.value = target;
    await load();
}

onMounted(load);
</script>

<template>
    <section class="flex flex-col gap-4">
        <header class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-page-title">{{ t('purchaseOrders.title') }}</h1>
        </header>

        <form class="flex flex-wrap items-end gap-3" data-testid="purchase-orders-filters" @submit.prevent="applySearch">
            <label class="flex flex-col gap-1">
                <span>{{ t('purchaseOrders.search') }}</span>
                <input
                    v-model="search"
                    type="search"
                    class="form-field min-h-11 rounded-lg px-3"
                    :placeholder="t('purchaseOrders.searchPlaceholder')"
                    data-testid="purchase-orders-search"
                />
            </label>

            <button
                type="submit"
                class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
            >
                {{ t('purchaseOrders.apply') }}
            </button>
        </form>

        <LoadingState v-if="loading" label-key="purchaseOrders.loading" />
        <PermissionDeniedState v-else-if="denied" />
        <ErrorState v-else-if="failed" @retry="load" />
        <!-- "None are visible to you", never "there are none": the rows are scoped through the deal. -->
        <EmptyState
            v-else-if="orders.length === 0"
            :title-key="searching ? 'purchaseOrders.empty.filtered.title' : 'purchaseOrders.empty.title'"
            :message-key="searching ? 'purchaseOrders.empty.filtered.message' : 'purchaseOrders.empty.message'"
        />

        <div v-else class="flex flex-col gap-3">
            <div class="table-frame overflow-x-auto rounded-xl">
                <table class="w-full text-table" data-testid="purchase-orders-table">
                    <thead>
                        <tr class="table-head">
                            <th scope="col" class="p-3 text-start">{{ t('purchaseOrders.column.poNumber') }}</th>
                            <th scope="col" class="p-3 text-start">{{ t('quotations.detail.poReference') }}</th>
                            <th scope="col" class="p-3 text-start">{{ t('quotations.detail.poDate') }}</th>
                            <th scope="col" class="p-3 text-start">{{ t('purchaseOrders.column.quotation') }}</th>
                            <th scope="col" class="p-3 text-start">{{ t('quotations.column.customer') }}</th>
                            <th scope="col" class="p-3 text-end">{{ t('quotations.column.total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="order in orders" :key="order.id" class="table-row" data-testid="purchase-orders-row">
                            <td class="p-3 tabular-nums">{{ order.po_number }}</td>
                            <td class="p-3">{{ order.customer_po_reference }}</td>
                            <td class="p-3 tabular-nums" data-testid="purchase-orders-date">{{ displayDate(order.po_date, locale) }}</td>
                            <td class="p-3">
                                <RouterLink :to="{ name: 'quotation-detail', params: { id: order.quotation_id } }" class="row-link">
                                    {{ order.quotation_code }}
                                </RouterLink>
                            </td>
                            <!-- `D-83`: the name when Customers could give one, the identifier otherwise — never a blank. -->
                            <td class="p-3">{{ order.customer_name ?? order.customer_id }}</td>
                            <td class="p-3 text-end tabular-nums" data-testid="purchase-orders-total">
                                {{ displayDecimals(order.final_total) }} {{ order.currency }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav
                v-if="pagination"
                class="flex items-center justify-between gap-3"
                :aria-label="t('purchaseOrders.pagination.label')"
            >
                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!pagination.has_previous_page || loading"
                    data-testid="purchase-orders-previous"
                    @click="goToPage(pagination.page - 1)"
                >
                    {{ t('purchaseOrders.pagination.previous') }}
                </button>

                <p class="tabular-nums text-[var(--color-text-muted)]">
                    {{ t('purchaseOrders.pagination.position', { page: pagination.page, pages: pagination.total_pages, total: pagination.total }) }}
                </p>

                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!pagination.has_next_page || loading"
                    data-testid="purchase-orders-next"
                    @click="goToPage(pagination.page + 1)"
                >
                    {{ t('purchaseOrders.pagination.next') }}
                </button>
            </nav>
        </div>
    </section>
</template>

<style scoped>
/* `DealsView`'s list, copied — the "same table boilerplate" debt row owns the extraction. */
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

.form-field {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
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
