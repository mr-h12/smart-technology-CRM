<script setup lang="ts">
/**
 * One customer — Design System §5.2's Detail view (Point 4.4).
 *
 * §5.2: "Summary first, related data/timeline second, action controls only by
 * permission." That is the shape below: §4.2's fields, then `D-122`'s notes,
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
 * Recorded rather than quietly added.
 *
 * **No owner's name, though the owner can be changed.** Assign is the last
 * section since F-19 · 1.1 (E2-1: no screen reached the route Point 3.5
 * shipped), on §3.3's own `assign` row. The name belongs to Identity and
 * arrives from the server with F-19 · 1.1a (`D-83`), not by a lookup here.
 *
 * **Its requests and quotations** (`D-114`, F-45 · 2) are §5.2's "related data
 * second": each list under its own view row (§3.4, §3.5), the rows scoped by the
 * server, the page only naming the customer. «New request» opens the deal form
 * with the customer fixed, and a save goes to the new deal. `D-122` makes the
 * communication history a list of notes, drawn after them.
 *
 * **No "years of dealing".** §4.2 says `start_date` "drives" it, and that is
 * the only mention in the sources: no field on the wire, no definition of a
 * partial year, no rounding rule. Computing one here would be the SPA inventing
 * a business value (`D-67`), so the date is shown and the derived figure is
 * owed a decision.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { ApiError } from '@/api';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import { listEntries, type ListEntry } from '@/services/admin';
import { assignCustomer, contactLine, readCustomer, type CustomerDetail } from '@/services/customers';
import { useAuth } from '@/stores/auth';
import CustomerFormModal from '@/pages/customers/CustomerFormModal.vue';
import CustomerFollowUpsSection from '@/components/customers/CustomerFollowUpsSection.vue';
import CustomerNotesSection from '@/components/customers/CustomerNotesSection.vue';
import DealOwnerPicker from '@/pages/deals/DealOwnerPicker.vue';
import DealFormModal from '@/pages/deals/DealFormModal.vue';
import DealStatusChip from '@/pages/deals/DealStatusChip.vue';
import DealStatusControl from '@/pages/deals/DealStatusControl.vue';
import QuotationStatusChip from '@/pages/quotations/QuotationStatusChip.vue';
import { listDeals, type Deal, type DealRow } from '@/services/deals';
import { listQuotations, type QuotationSummary } from '@/services/quotations';
import { displayDate } from '@/domain/displayDate';
import { displayDecimals } from '@/domain/displayDecimals';

const route = useRoute();
const router = useRouter();
const { t, locale } = useI18n();
const auth = useAuth();

const customer = ref<CustomerDetail | null>(null);
const loading = ref(true);
const failed = ref(false);
const denied = ref(false);
const missing = ref(false);
const sectors = ref<ListEntry[]>([]);
const titles = ref<ListEntry[]>([]);
const formOpen = ref(false);
const ownerId = ref('');
const assignDone = ref(false);
/** A-30: the assign in flight, so a second press sends nothing. */
const assigning = ref(false);
/** A flag, not a sentence: the template translates it when drawn, so a language switch reaches it. */
const ownerMissing = ref(false);
/** The server's own sentence, already in the language of the request that earned it. */
const assignFieldError = ref<string | null>(null);
const assignErrorKey = ref<string | null>(null);

const canEdit = computed(() => auth.hasPermission('customer.edit'));
/** §3.3's own `assign` row, never `edit`: two roles hold it where five hold `edit`. */
const canAssign = computed(() => auth.hasPermission('customer.assign'));
/** `D-114`: each list under its own view row, the button under §3.4's `create`. */
const canSeeDeals = computed(() => auth.hasPermission('deal.view'));
const canSeeQuotations = computed(() => auth.hasPermission('quotation.view'));
const canCreateDeal = computed(() => auth.hasPermission('deal.create'));

/** Null while loading; a failure costs that list, not the page. */
const deals = ref<DealRow[] | null>(null);
const quotations = ref<QuotationSummary[] | null>(null);
const dealsFailed = ref(false);
const quotationsFailed = ref(false);
const dealFormOpen = ref(false);

const id = computed(() => String(route.params.id ?? ''));

/**
 * §4.2's contact fields, as label/value pairs. A list rather than markup per
 * field, so a field cannot be added to §4.2 and drawn in one place only.
 * The contact person is drawn above them: `D-104` prints it with its title.
 */
const CONTACT_FIELDS = [
    { key: 'phone', label: 'customers.column.phone', href: (value: string) => `tel:${value.replace(/[^\d+]/g, '')}`, newTab: false },
    { key: 'phone2', label: 'customers.form.phone2', href: (value: string) => `tel:${value.replace(/[^\d+]/g, '')}`, newTab: false },
    // wa.me takes the international number as digits only.
    { key: 'whatsapp', label: 'customers.form.whatsapp', href: (value: string) => `https://wa.me/${value.replace(/\D/g, '')}`, newTab: false },
    { key: 'email', label: 'customers.form.email', href: (value: string) => `mailto:${value}`, newTab: false },
    // `D-121`: a site opens in a new tab and never gets this page (`noopener noreferrer`).
    // The server stored only http(s), so the value is the href as it is.
    { key: 'location_url', label: 'customers.form.locationUrl', href: (value: string) => value, newTab: true },
    { key: 'website_url', label: 'customers.form.websiteUrl', href: (value: string) => value, newTab: true },
    { key: 'social_url_1', label: 'customers.form.socialUrl1', href: (value: string) => value, newTab: true },
    { key: 'social_url_2', label: 'customers.form.socialUrl2', href: (value: string) => value, newTab: true },
    { key: 'social_url_3', label: 'customers.form.socialUrl3', href: (value: string) => value, newTab: true },
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

/** `D-104`'s titles: the form's box and «title name». A failure prints the name alone. */
async function loadTitles(): Promise<void> {
    try {
        titles.value = (await listEntries('contact_titles', 1)).items;
    } catch {
        titles.value = [];
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

async function onSaved(): Promise<void> {
    formOpen.value = false;

    // Re-read rather than patch the object in hand: the server owns the record,
    // and `is_incomplete` in particular is its calculation, not the form's.
    // `D-113`: the save may have added a typed sector to the list.
    await Promise.all([load(), loadSectors()]);
}

/**
 * Flow 10. The route checks `customer.assign`, the row scope and that the
 * owner exists, and writes §3.12 rule 4's audit entry in the same transaction.
 * The page only asks.
 */
async function submitAssign(): Promise<void> {
    if (assigning.value) {
        return;
    }

    ownerMissing.value = false;
    assignFieldError.value = null;
    assignErrorKey.value = null;
    assignDone.value = false;

    // §3.3 has no unassign row, so an empty choice never reaches the server.
    if (ownerId.value === '') {
        ownerMissing.value = true;

        return;
    }

    assigning.value = true;

    try {
        // The answer is the page's own shape, so the new owner's name shows at once.
        customer.value = await assignCustomer(id.value, ownerId.value);
        assignDone.value = true;
    } catch (error) {
        // The server's sentence, e.g. an owner who is not a user of this system.
        const sentence = error instanceof ApiError ? error.messageFor('sales_owner_id') : null;

        if (sentence !== null) {
            assignFieldError.value = sentence;
        } else {
            assignErrorKey.value = error instanceof ApiError && error.status === 403
                ? 'customers.assign.forbidden'
                : 'customers.assign.rejected';
        }
    } finally {
        assigning.value = false;
    }
}

// ponytail: the first 100 rows of each list (§6.1's ceiling), no pager; add one when a customer outgrows it.
async function loadHistory(): Promise<void> {
    await Promise.all([
        canSeeDeals.value && listDeals({ customer: id.value, perPage: 100 })
            .then((page) => { deals.value = page.items; }, () => { dealsFailed.value = true; }),
        canSeeQuotations.value && listQuotations({ customerId: id.value, perPage: 100 })
            .then((page) => { quotations.value = page.items; }, () => { quotationsFailed.value = true; }),
    ]);
}

/** F-49 · 1.4a: a manual move redraws its row from the server's deal; the row keeps its own `customer_name`. */
function onDealChanged(changed: Deal): void {
    deals.value = deals.value?.map((deal) => (deal.id === changed.id ? { ...deal, ...changed } : deal)) ?? null;
}

/** `D-114`: a new request opens on its own page. */
async function onDealSaved(deal: Deal): Promise<void> {
    dealFormOpen.value = false;
    await router.push({ name: 'deal-detail', params: { id: deal.id } });
}

onMounted(async () => {
    await Promise.all([load(), loadSectors(), loadTitles(), loadHistory()]);
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
                class="row-action inline-flex min-h-11 items-center rounded-lg px-4 py-2"
                data-testid="customer-back"
            >
                {{ t('customers.detail.backToList') }}
            </RouterLink>
        </div>

        <!-- A-43: the way back stays above the error, as on the not-found state. -->
        <div v-else-if="failed" class="flex flex-col gap-1">
            <RouterLink :to="{ name: 'customers' }" class="inline-flex min-h-11 items-center self-start text-[var(--color-text-muted)]" data-testid="customer-back">
                {{ t('customers.detail.backToList') }}
            </RouterLink>
            <ErrorState @retry="load()" />
        </div>

        <template v-else-if="customer !== null">
            <header class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <RouterLink
                        :to="{ name: 'customers' }"
                        class="inline-flex min-h-11 items-center self-start text-[var(--color-text-muted)]"
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
                    class="edit-action min-h-11 rounded-lg px-4"
                    data-testid="customer-detail-edit"
                    @click="formOpen = true"
                >
                    {{ t('action.edit') }}
                </button>
            </header>

            <!-- §5.2's "Summary first". -->
            <div class="detail-card flex flex-col gap-4 rounded-xl p-4" data-testid="customer-summary">
                <div class="flex flex-wrap items-center gap-3">
                    <!-- §6.4: never colour alone — the chip carries its own word. -->
                    <span class="status-chip status-chip--neutral rounded-full px-2 py-0.5" data-testid="customer-detail-status">
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
                    <div class="flex flex-col" data-testid="customer-contact-person">
                        <dt class="text-[var(--color-text-muted)]">{{ t('customers.column.contact') }}</dt>
                        <dd>{{ contactLine(customer, titles, locale, t('customers.contactTitleShort')) }}</dd>
                    </div>
                    <div v-for="field in CONTACT_FIELDS" :key="field.key" class="flex flex-col">
                        <dt class="text-[var(--color-text-muted)]">{{ t(field.label) }}</dt>
                        <!-- A-07: numbers and addresses keep their order inside Arabic text. -->
                        <dd class="tabular-nums" :data-testid="field.newTab ? `customer-link-${field.key}` : undefined">
                            <!-- A-54: one tap opens the phone, WhatsApp or mail app. -->
                            <a
                                v-if="customer[field.key]"
                                :href="field.href(customer[field.key] ?? '')"
                                :target="field.newTab ? '_blank' : undefined"
                                :rel="field.newTab ? 'noopener noreferrer' : undefined"
                                dir="ltr"
                                :class="['row-link', { 'break-all': field.newTab }]"
                            >{{ customer[field.key] }}</a>
                            <template v-else>—</template>
                        </dd>
                    </div>
                    <!-- §4.2's order; the name comes from the server (F-19 · 1.1a, `D-83`). -->
                    <div class="flex flex-col">
                        <dt class="text-[var(--color-text-muted)]">{{ t('customers.assign.title') }}</dt>
                        <dd data-testid="customer-detail-owner">{{ orDash(customer.sales_owner_name) }}</dd>
                    </div>
                    <div class="flex flex-col">
                        <dt class="text-[var(--color-text-muted)]">{{ t('customers.column.startDate') }}</dt>
                        <!-- Printed as stored: a calendar date has no timezone,
                             and a `Date` would place it at UTC midnight. -->
                        <dd class="tabular-nums">{{ orDash(customer.start_date) }}</dd>
                    </div>
                    <div class="flex flex-col">
                        <dt class="text-[var(--color-text-muted)]">{{ t('customers.column.added') }}</dt>
                        <dd class="tabular-nums">{{ displayDate(customer.created_at, locale) }}</dd>
                    </div>
                </dl>
            </div>

            <!-- `D-114`: the customer's requests, §3.4's `view` row. -->
            <section v-if="canSeeDeals" class="detail-card flex flex-col gap-2 rounded-xl p-4" data-testid="customer-deals">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-card-title">{{ t('deals.title') }}</h2>
                    <button
                        v-if="canCreateDeal"
                        type="button"
                        class="edit-action min-h-11 rounded-lg px-4"
                        data-testid="customer-new-deal"
                        @click="dealFormOpen = true"
                    >
                        {{ t('customers.detail.newDeal') }}
                    </button>
                </div>

                <p v-if="dealsFailed" class="text-[var(--color-danger)]" role="alert">{{ t('customers.detail.historyFailed') }}</p>
                <p v-else-if="deals === null" class="text-[var(--color-text-muted)]">{{ t('deals.loading') }}</p>
                <p v-else-if="deals.length === 0" class="text-[var(--color-text-muted)]" data-testid="customer-deals-empty">{{ t('customers.detail.dealsEmpty') }}</p>
                <ul v-else class="flex flex-col divide-y divide-[var(--color-border)]">
                    <!-- F-49 · 1.4a: the status control sits beside the link — a button inside an <a> is invalid and its click would open the deal. -->
                    <li v-for="deal in deals" :key="deal.id" class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <RouterLink :to="{ name: 'deal-detail', params: { id: deal.id } }" class="flex min-h-11 min-w-0 flex-[1_1_16rem] flex-wrap items-center gap-x-3 gap-y-1 py-2">
                            <bdi dir="ltr" class="font-medium tabular-nums">{{ deal.code }}</bdi>
                            <span class="min-w-0 flex-1 text-pretty">{{ orDash(deal.title) }}</span>
                            <DealStatusChip :status="deal.status" />
                            <span class="tabular-nums text-[var(--color-text-muted)]">{{ displayDate(deal.last_activity_at, locale) }}</span>
                        </RouterLink>
                        <DealStatusControl :deal="deal" @changed="onDealChanged" />
                    </li>
                </ul>
            </section>

            <!-- `D-114`: the customer's quotations, §3.5's `view` row (the Outdoor Supervisor has none). -->
            <section v-if="canSeeQuotations" class="detail-card flex flex-col gap-2 rounded-xl p-4" data-testid="customer-quotations">
                <h2 class="text-card-title">{{ t('quotations.title') }}</h2>

                <p v-if="quotationsFailed" class="text-[var(--color-danger)]" role="alert">{{ t('customers.detail.historyFailed') }}</p>
                <p v-else-if="quotations === null" class="text-[var(--color-text-muted)]">{{ t('quotations.loading') }}</p>
                <p v-else-if="quotations.length === 0" class="text-[var(--color-text-muted)]" data-testid="customer-quotations-empty">{{ t('customers.detail.quotationsEmpty') }}</p>
                <ul v-else class="flex flex-col divide-y divide-[var(--color-border)]">
                    <li v-for="quotation in quotations" :key="quotation.id">
                        <RouterLink :to="{ name: 'quotation-detail', params: { id: quotation.id } }" class="flex min-h-11 flex-wrap items-center gap-x-3 gap-y-1 py-2">
                            <bdi dir="ltr" class="font-medium tabular-nums">{{ quotation.code }}</bdi>
                            <span class="tabular-nums text-[var(--color-text-muted)]">{{ t('quotations.version', { version: quotation.version }) }}</span>
                            <QuotationStatusChip :status="quotation.status" />
                            <!-- The server's total, drawn as the quotations list draws it (`D-110`); never computed here (`D-67`). -->
                            <bdi dir="ltr" class="ms-auto tabular-nums">{{ displayDecimals(quotation.final_total) }} {{ quotation.currency }}</bdi>
                        </RouterLink>
                    </li>
                </ul>
            </section>

            <!-- §5.2's "related data/timeline second". `D-122`: the
                 communication history is a list of notes, each its own entry. -->
            <CustomerNotesSection :customer-id="customer.id" :can-edit="canEdit" />
            <CustomerFollowUpsSection :customer-id="customer.id" />

            <!-- Flow 10, last after §5.2's summary and related data. The picker
                 is the deal page's own control, reused rather than copied. -->
            <section v-if="canAssign" class="detail-card flex flex-col gap-2 rounded-xl p-4" data-testid="customer-assign">
                <h2 class="text-card-title">{{ t('customers.assign.title') }}</h2>

                <p v-if="assignErrorKey !== null" class="form-alert rounded-lg p-3" role="alert" data-testid="customer-assign-error">
                    {{ t(assignErrorKey) }}
                </p>

                <form class="flex flex-wrap items-end gap-2" data-testid="customer-assign-form" @submit.prevent="submitAssign()">
                    <!-- `min-w-0`: a flex item never shrinks below its content, and the
                         picker's longest option is wider than a 375 px card. -->
                    <label class="flex min-w-0 flex-col gap-1.5" for="customer-assign-owner">
                        <span>
                            {{ t('customers.assign.owner') }}
                            <span class="text-[var(--color-danger)]">{{ t('customers.form.required') }}</span>
                        </span>
                        <DealOwnerPicker v-model="ownerId" field-id="customer-assign-owner" test-id="customer-assign-owner" />

                        <span v-if="ownerMissing || assignFieldError !== null" class="text-[var(--color-danger)]" data-testid="customer-assign-owner-error">
                            {{ ownerMissing ? t('customers.assign.ownerRequired') : assignFieldError }}
                        </span>
                    </label>

                    <button
                        type="submit"
                        class="edit-action min-h-11 rounded-lg px-4 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="assigning"
                        data-testid="customer-assign-submit"
                    >
                        {{ assigning ? t('action.saving') : t('customers.assign.action') }}
                    </button>
                </form>

                <!-- A-31: present from load and empty; a live region announces a change to what it holds. -->
                <p role="status" aria-live="polite" data-testid="customer-assign-done">{{ assignDone ? t('customers.assign.done') : '' }}</p>
            </section>

            <CustomerFormModal
                :open="formOpen"
                :editing="customer"
                :sectors="sectors"
                :titles="titles"
                @saved="onSaved"
                @cancel="formOpen = false"
            />

            <DealFormModal
                :open="dealFormOpen"
                :editing="null"
                :customer="{ id: customer.id, name: customer.name }"
                @saved="onDealSaved"
                @cancel="dealFormOpen = false"
            />
        </template>
    </section>
</template>

<style scoped>
.detail-card {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border);
}

.flag-chip {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-warning);
}

.form-alert {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-border-strong);
}

.edit-action {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
}
</style>
