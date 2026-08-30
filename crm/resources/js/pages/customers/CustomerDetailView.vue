<script setup lang="ts">
/**
 * One customer — Design System §5.2's Detail view (Point 4.4).
 *
 * §5.2: "Summary first, related data/timeline second, action controls only by
 * permission." That is the shape below: §4.2's fields, then `D-16`'s notes,
 * then the one action this point ships.
 *
 * ── A 404 is one state and must stay one state ─────────────────────────────
 *
 * `OpenAPI §5.1` defines 404 as "Resource does not exist **or is not visible to
 * the caller**. Do not reveal which case applies", which is why
 * `CustomerNotFound` is a single exception covering both. A screen that said
 * "you do not have access to this customer" would undo that in the one place
 * the person can read it, so this one says the record could not be opened and
 * stops there.
 *
 * A **403** is a different answer and gets a different screen: it is about the
 * caller lacking `customer.view` at all, and says nothing about any row.
 *
 * ── What is deliberately not here ──────────────────────────────────────────
 *
 * **No archive or restore.** §3.3 gives them one merged `customer.archive`
 * permission and the approved decomposition puts them on Point 4.5's screen.
 * **No assign**, though Point 3.5 shipped the route: no point in the approved
 * list names a screen for it, and inventing one here would be building past the
 * plan. Both are recorded rather than quietly added.
 *
 * **No deals and no timeline.** §5.2 wants "related data/timeline second" and
 * for a customer that is Module 5's deals, which do not exist. `D-16` puts the
 * communication history in `notes`, so that is the related section this module
 * can honestly draw today.
 *
 * **No "years of dealing".** §4.2 says `start_date` "drives" it, and that is
 * the only mention in the sources: no field on the wire, no definition of a
 * partial year, no rounding rule. Computing one here would be the SPA inventing
 * a business value (`D-67`), so the date is shown and the derived figure is
 * owed a decision.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import { ApiError } from '@/api';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import { listEntries, type ListEntry } from '@/services/admin';
import { readCustomer, type Customer } from '@/services/customers';
import { useAuth } from '@/stores/auth';
import CustomerFormModal from '@/pages/customers/CustomerFormModal.vue';

const route = useRoute();
const { t, locale } = useI18n();
const auth = useAuth();

const customer = ref<Customer | null>(null);
const loading = ref(true);
const failed = ref(false);
const denied = ref(false);
const missing = ref(false);
const sectors = ref<ListEntry[]>([]);
const formOpen = ref(false);

const canEdit = computed(() => auth.hasPermission('customer.edit'));

const id = computed(() => String(route.params.id ?? ''));

/**
 * §4.2's contact fields, as label/value pairs. A list rather than markup per
 * field, so a field cannot be added to §4.2 and drawn in one place only.
 */
const CONTACT_FIELDS = [
    { key: 'contact_person', label: 'customers.column.contact' },
    { key: 'phone', label: 'customers.column.phone' },
    { key: 'phone2', label: 'customers.form.phone2' },
    { key: 'whatsapp', label: 'customers.form.whatsapp' },
    { key: 'email', label: 'customers.form.email' },
] as const;

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    denied.value = false;
    missing.value = false;

    try {
        customer.value = await readCustomer(id.value);
    } catch (error) {
        const status = error instanceof ApiError ? error.status : 0;

        denied.value = status === 403;
        missing.value = status === 404;
        failed.value = !denied.value && !missing.value;
    } finally {
        loading.value = false;
    }
}

/** The form's sector dropdown needs them; a failure costs the dropdown, not the page. */
async function loadSectors(): Promise<void> {
    try {
        sectors.value = (await listEntries('sectors', 1)).items;
    } catch {
        sectors.value = [];
    }
}

/**
 * The sector arrives as a code (`CustomerPayload` sends the stored value so it
 * stays usable as a filter). A code with no entry prints itself, which is ugly
 * and true — better than an empty cell hiding that the list changed.
 */
function sectorLabel(code: string | null): string {
    if (code === null) {
        return '—';
    }

    const entry = sectors.value.find((candidate) => candidate.code === code);

    if (entry === undefined) {
        return code;
    }

    return locale.value.startsWith('ar') ? entry.label_ar : entry.label_en;
}

/** Nullable §4.2 columns print an em-dash rather than an empty cell or "null". */
function orDash(value: string | null): string {
    return value === null || value === '' ? '—' : value;
}

/** `DB-08` — stored UTC, shown in the reader's own timezone. */
function addedOn(timestamp: string): string {
    return new Date(timestamp).toLocaleDateString(locale.value);
}

async function onSaved(): Promise<void> {
    formOpen.value = false;

    // Re-read rather than patch the object in hand: the server owns the record,
    // and `is_incomplete` in particular is its calculation, not the form's.
    await load();
}

onMounted(async () => {
    await Promise.all([load(), loadSectors()]);
});
</script>

<template>
    <section class="flex flex-col gap-4">
        <LoadingState v-if="loading" label-key="customers.loading" />

        <PermissionDeniedState v-else-if="denied" />

        <!-- `OpenAPI §5.1`: one state for "does not exist" and "not visible to
             you", saying which would be the leak the 404 exists to prevent. -->
        <div v-else-if="missing" class="flex flex-col items-center gap-2 p-8 text-center" data-testid="customer-not-found">
            <h1 class="text-page-title">{{ t('customers.detail.notFoundTitle') }}</h1>
            <p class="text-[var(--color-text-muted)] text-pretty">{{ t('customers.detail.notFoundMessage') }}</p>
            <RouterLink
                :to="{ name: 'customers' }"
                class="row-action min-h-11 rounded-lg px-4 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                data-testid="customer-back"
            >
                {{ t('customers.detail.backToList') }}
            </RouterLink>
        </div>

        <ErrorState v-else-if="failed" @retry="load()" />

        <template v-else-if="customer !== null">
            <header class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <RouterLink
                        :to="{ name: 'customers' }"
                        class="text-[var(--color-text-muted)] focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                        data-testid="customer-back"
                    >
                        {{ t('customers.detail.backToList') }}
                    </RouterLink>
                    <h1 class="text-page-title">{{ customer.name }}</h1>
                </div>

                <!-- §5.2: "action controls only by permission" — and `SEC-09`
                     makes that a mirror of §3.3's row, never the enforcement. -->
                <button
                    v-if="canEdit"
                    type="button"
                    class="edit-action min-h-11 rounded-lg px-4 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="customer-detail-edit"
                    @click="formOpen = true"
                >
                    {{ t('action.edit') }}
                </button>
            </header>

            <!-- §5.2's "Summary first". -->
            <div class="detail-card flex flex-col gap-4 rounded-2xl p-4" data-testid="customer-summary">
                <div class="flex flex-wrap items-center gap-3">
                    <!-- §6.4: never colour alone — the chip carries its own word. -->
                    <span class="status-chip rounded-full px-2 py-0.5" data-testid="customer-detail-status">
                        {{ t(`customers.status.${customer.customer_status}`) }}
                    </span>

                    <!-- §4.2's two flags, which the table has no column for. -->
                    <span v-if="customer.is_archived" class="flag-chip rounded-full px-2 py-0.5" data-testid="customer-detail-archived">
                        {{ t('customers.detail.archived') }}
                    </span>
                    <span v-if="customer.is_incomplete" class="flag-chip rounded-full px-2 py-0.5" data-testid="customer-detail-incomplete">
                        {{ t('customers.detail.incomplete') }}
                    </span>
                </div>

                <!-- §4.5 · `D-49`: derived, and the screen says why it cannot be edited. -->
                <p class="flex items-center gap-2 text-[var(--color-text-muted)] text-pretty" data-testid="customer-detail-status-reason">
                    <svg class="size-4 shrink-0" viewBox="0 0 20 20" aria-hidden="true" fill="currentColor">
                        <path d="M10 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16zM9 5h2v2H9zm0 4h2v6H9z" />
                    </svg>
                    {{ t('customers.form.statusIsDerived') }}
                </p>

                <dl class="grid gap-3 sm:grid-cols-2">
                    <div class="flex flex-col">
                        <dt class="text-[var(--color-text-muted)]">{{ t('customers.column.sector') }}</dt>
                        <dd>{{ sectorLabel(customer.sector) }}</dd>
                    </div>
                    <div class="flex flex-col">
                        <dt class="text-[var(--color-text-muted)]">{{ t('customers.form.region') }}</dt>
                        <dd>{{ orDash(customer.region) }}</dd>
                    </div>
                    <div v-for="field in CONTACT_FIELDS" :key="field.key" class="flex flex-col">
                        <dt class="text-[var(--color-text-muted)]">{{ t(field.label) }}</dt>
                        <dd class="tabular-nums">{{ orDash(customer[field.key]) }}</dd>
                    </div>
                    <div class="flex flex-col">
                        <dt class="text-[var(--color-text-muted)]">{{ t('customers.column.startDate') }}</dt>
                        <!-- Printed as stored: a calendar date has no timezone,
                             and a `Date` would place it at UTC midnight. -->
                        <dd class="tabular-nums">{{ orDash(customer.start_date) }}</dd>
                    </div>
                    <div class="flex flex-col">
                        <dt class="text-[var(--color-text-muted)]">{{ t('customers.column.added') }}</dt>
                        <dd class="tabular-nums">{{ addedOn(customer.created_at) }}</dd>
                    </div>
                </dl>
            </div>

            <!-- §5.2's "related data/timeline second". `D-16` puts the
                 communication history in the notes field. -->
            <section class="detail-card flex flex-col gap-2 rounded-2xl p-4">
                <h2 class="text-card-title">{{ t('customers.detail.notesTitle') }}</h2>

                <p v-if="customer.notes !== null && customer.notes !== ''" class="whitespace-pre-line text-pretty" data-testid="customer-notes">
                    {{ customer.notes }}
                </p>
                <p v-else class="text-[var(--color-text-muted)] text-pretty" data-testid="customer-notes-empty">
                    {{ t('customers.detail.notesEmpty') }}
                </p>
            </section>

            <CustomerFormModal
                :open="formOpen"
                :editing="customer"
                :sectors="sectors"
                @saved="onSaved"
                @cancel="formOpen = false"
            />
        </template>
    </section>
</template>

<style scoped>
.detail-card {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border);
}

.status-chip {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-border-strong);
}

.flag-chip {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-warning);
}

.row-action {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.edit-action {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
}
</style>
