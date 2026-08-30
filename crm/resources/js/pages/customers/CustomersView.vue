<script setup lang="ts">
/**
 * §8's *Customers* screen — the shell Step 4 builds on.
 *
 * ── What Point 4.0 draws, and what it deliberately does not ────────────────
 *
 * `navigation.ts` states the rule this point obeys: "a dead link is not a
 * permission problem, it is a lie". So the sidebar item added with this point
 * resolves to a screen that really calls `GET /customers` and really renders
 * Design System §5.2's loading, empty and error states. The **table, the
 * filters, the sort and the paginator are Points 4.1 and 4.2** — this shows the
 * total the server reported, which is the smallest honest thing a customers
 * screen can say.
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
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import EmptyState from '@/components/states/EmptyState.vue';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import { listCustomers, type Customer } from '@/services/customers';

const { t } = useI18n();

const customers = ref<Customer[]>([]);
const total = ref(0);
const loading = ref(true);
const failed = ref(false);
const denied = ref(false);

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    denied.value = false;

    try {
        const page = await listCustomers();

        customers.value = page.items;
        total.value = page.pagination.total;
    } catch (error) {
        // A 403 and a 500 are different answers and get different screens: one
        // is a boundary and the other is a fault.
        denied.value = error instanceof ApiError && error.status === 403;
        failed.value = !denied.value;
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <section class="flex flex-col gap-4">
        <header class="flex flex-wrap items-baseline justify-between gap-2">
            <h1 class="text-xl font-semibold">{{ t('customers.title') }}</h1>

            <p v-if="!loading && !failed && !denied" data-testid="customer-total" class="text-sm opacity-70">
                {{ t('customers.total', { count: total }) }}
            </p>
        </header>

        <LoadingState v-if="loading" />
        <PermissionDeniedState v-else-if="denied" />
        <ErrorState v-else-if="failed" @retry="load" />
        <EmptyState v-else-if="customers.length === 0" />

        <!-- Point 4.1 replaces this with §5.2's table: column priorities,
             server-side sort and the paginator. -->
        <ul v-else class="flex flex-col gap-1">
            <li v-for="customer in customers" :key="customer.id" class="rounded-lg px-3 py-2" data-customer-id>
                {{ customer.name }}
            </li>
        </ul>
    </section>
</template>
