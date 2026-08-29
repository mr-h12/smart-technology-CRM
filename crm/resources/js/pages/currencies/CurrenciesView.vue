<script setup lang="ts">
/**
 * §13 screen 5's content — *Currencies & FX* — rendered as two sections of the
 * one settings page (**owner decision, 2026-08-29**, recorded as a pending
 * `D-xx`: §13 names screens 4, 5 and 6 separately and the owner merged them).
 *
 * ── One screen, two audiences ──────────────────────────────────────────────
 *
 * §13 names a single screen: "base currency · manual rate per currency ·
 * rounding unit per currency · rate history". §3.11 splits it in half. The
 * rounding unit is filed under `System Settings → Currencies` (`D-65`,
 * §5.3) and carries `admin.system_settings`, which §3.11 gives to the Super
 * Admin and to nobody else; the rates carry `admin.fx_rates`, which the
 * **Manager holds too**. So the two halves are drawn by permission, and the
 * Manager's view — the rate history and its form, no rounding table — is a
 * real state rather than a degraded one.
 *
 * The route carries `admin.fx_rates` for the same reason: every seeded holder
 * of `admin.system_settings` also holds `admin.fx_rates`, so it is the wider of
 * the two and admits everyone §3.11 sends here. Guarding with
 * `admin.system_settings` would bounce the Manager off a screen §3.11 grants
 * them, which is the defect `navigation.ts` names — "a dead link is not a
 * permission problem, it is a lie".
 *
 * ── Nothing here is authorization ──────────────────────────────────────────
 *
 * §3.12 rule 1 · `SEC-09`. Hiding the rounding table is presentation. The
 * server refuses `PATCH /currencies/{code}` for anyone without the row
 * regardless, which is where the answer has always been — and the currencies
 * are not even requested for a caller who does not hold it, because a
 * guaranteed 403 buys nothing but an error state on a half that should not be
 * drawn.
 *
 * ── The base currency is read-only ─────────────────────────────────────────
 *
 * Owner decision of 2026-08-28. `CurrencyController` offers the unit and the
 * switch and nothing else — moving the base is not an edit this API has — so
 * the base is drawn as a fact beside its code, never as a control.
 *
 * ── No staleness element ───────────────────────────────────────────────────
 *
 * §13's fifth item is "staleness alert". **Withdrawn by the owner on
 * 2026-08-28**: `J-12` is not built, there is no threshold, and rates are
 * passive records updated by hand. A badge with no rule behind it would be a
 * product promise nothing keeps, so there is none.
 *
 * ── `DB-07` reaches the screen ─────────────────────────────────────────────
 *
 * A rounding unit and an FX rate are decimal **strings** end to end. Every
 * control here is `type="text"` with `inputmode="decimal"` for the keypad — a
 * `type="number"` binds to a JavaScript number, and a JavaScript number is the
 * float that may never touch `0.01` or an exchange rate.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError, type Pagination } from '@/api';
import { useAuth } from '@/stores/auth';
import EmptyState from '@/components/states/EmptyState.vue';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import {
    listCurrencies,
    listFxRates,
    recordFxRate,
    updateCurrencyRounding,
    type Currency,
    type FxRate,
} from '@/services/admin';

const { t, locale } = useI18n();
const auth = useAuth();

/**
 * §3.11's `system settings` row. `SEC-09`: the visual complement to the API's
 * refusal, never the refusal.
 */
const canConfigureRounding = computed(() => auth.hasPermission('admin.system_settings'));

// ── the rounding half ──────────────────────────────────────────────────────

/**
 * One row, carrying its own draft and its own baseline.
 *
 * A row rather than three maps keyed by code: `PATCH /currencies/{code}` writes
 * one currency, so what changed is a per-row question, and `stored` is what
 * makes "only the fields that were edited" answerable without re-reading the
 * list.
 */
interface RoundingRow {
    code: string;
    isBase: boolean;
    stored: { unit: string; enabled: boolean };
    unit: string;
    enabled: boolean;
    /** The server's sentence for `rounding_unit`, localised by the server. */
    error: string | null;
}

const rows = ref<RoundingRow[]>([]);
const currenciesLoading = ref(true);
const currenciesDenied = ref(false);
/** A lang-file key, or null. */
const currenciesError = ref<string | null>(null);

const savingCode = ref<string | null>(null);
const savedCode = ref<string | null>(null);
const roundingSaveError = ref<string | null>(null);

function toRow(currency: Currency): RoundingRow {
    return {
        code: currency.code,
        isBase: currency.is_base,
        stored: { unit: currency.rounding_unit, enabled: currency.rounding_enabled },
        unit: currency.rounding_unit,
        enabled: currency.rounding_enabled,
        error: null,
    };
}

async function loadCurrencies(): Promise<void> {
    currenciesLoading.value = true;
    currenciesDenied.value = false;
    currenciesError.value = null;

    try {
        rows.value = (await listCurrencies()).map(toRow);
    } catch (error) {
        if (error instanceof ApiError && error.status === 403) {
            currenciesDenied.value = true;
        } else {
            currenciesError.value = 'currencies.error.load';
        }
    } finally {
        currenciesLoading.value = false;
    }
}

/** Only what this row actually changed — the server answers 422 to an empty body. */
function changesFor(row: RoundingRow): { rounding_unit?: string; rounding_enabled?: boolean } {
    const changes: { rounding_unit?: string; rounding_enabled?: boolean } = {};

    if (row.unit !== row.stored.unit) {
        changes.rounding_unit = row.unit;
    }

    if (row.enabled !== row.stored.enabled) {
        changes.rounding_enabled = row.enabled;
    }

    return changes;
}

async function saveRounding(row: RoundingRow): Promise<void> {
    const changes = changesFor(row);

    savedCode.value = null;
    roundingSaveError.value = null;
    row.error = null;

    // Nothing to say, so nothing is said.
    if (Object.keys(changes).length === 0) {
        return;
    }

    savingCode.value = row.code;

    try {
        const updated = await updateCurrencyRounding(row.code, changes);

        row.stored = { unit: updated.rounding_unit, enabled: updated.rounding_enabled };
        row.unit = updated.rounding_unit;
        row.enabled = updated.rounding_enabled;
        row.isBase = updated.is_base;
        savedCode.value = row.code;
    } catch (error) {
        if (error instanceof ApiError) {
            row.error = error.messageFor('rounding_unit') ?? error.messageFor('rounding_enabled');
        }

        // §6.6: an error must be explained somewhere that stays on screen.
        // A refusal that named no field still has to be visible.
        roundingSaveError.value = row.error === null ? 'currencies.error.save' : null;
    } finally {
        savingCode.value = null;
    }
}

// ── the rates half ─────────────────────────────────────────────────────────

const rates = ref<FxRate[]>([]);
const ratesPagination = ref<Pagination | null>(null);
const ratePage = ref(1);
const ratesLoading = ref(true);
const ratesDenied = ref(false);
const ratesError = ref<string | null>(null);

const form = ref({ from: '', to: '', rate: '' });
const recording = ref(false);
const recorded = ref(false);
const recordError = ref<string | null>(null);
const formErrors = ref<{ from: string | null; to: string | null; rate: string | null }>({
    from: null,
    to: null,
    rate: null,
});

async function loadRates(): Promise<void> {
    ratesLoading.value = true;
    ratesDenied.value = false;
    ratesError.value = null;

    try {
        const result = await listFxRates(ratePage.value);

        rates.value = result.items;
        ratesPagination.value = result.pagination;
    } catch (error) {
        if (error instanceof ApiError && error.status === 403) {
            ratesDenied.value = true;
        } else {
            ratesError.value = 'currencies.error.rates';
        }
    } finally {
        ratesLoading.value = false;
    }
}

async function goToRatePage(target: number): Promise<void> {
    ratePage.value = target;
    await loadRates();
}

async function record(): Promise<void> {
    recorded.value = false;
    recordError.value = null;
    formErrors.value = { from: null, to: null, rate: null };
    recording.value = true;

    try {
        // Upper-cased here because the server does the same before it looks the
        // code up; sending `usd` and having it accepted is not a reason to make
        // the person type capitals.
        await recordFxRate({
            from_currency: form.value.from.trim().toUpperCase(),
            to_currency: form.value.to.trim().toUpperCase(),
            rate: form.value.rate.trim(),
        });

        recorded.value = true;
        form.value.rate = '';

        // `AP-06`: the rate is appended, never written over, so the history has
        // to be re-read rather than patched in place. Page 1, because that is
        // where a newest-first history puts what was just recorded.
        ratePage.value = 1;
        await loadRates();
    } catch (error) {
        if (error instanceof ApiError) {
            formErrors.value = {
                from: error.messageFor('from_currency'),
                to: error.messageFor('to_currency'),
                rate: error.messageFor('rate'),
            };

            const named = Object.values(formErrors.value).some((message) => message !== null);

            recordError.value = named ? null : 'currencies.error.record';
        } else {
            recordError.value = 'currencies.error.record';
        }
    } finally {
        recording.value = false;
    }
}

/** `DB-08`: the server sends UTC and the browser converts, in the reader's locale. */
function formatMoment(iso: string): string {
    const at = new Date(iso);

    if (Number.isNaN(at.getTime())) {
        return iso;
    }

    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(at);
}

onMounted(async () => {
    await Promise.all([
        loadRates(),
        canConfigureRounding.value ? loadCurrencies() : Promise.resolve(),
    ]);
});
</script>

<template>
    <!-- No page header. This is a **section of** §13 screen 4 since S-02, not a
         screen of its own: the page's `<h1>` belongs to `SystemSettingsView`,
         and a second one here would give the document two top-level headings —
         which is exactly what a screen reader reads out as two pages. -->
    <section class="flex w-full flex-col gap-8">
        <!-- Half one — §5.3's unit and D-65's switch, behind admin.system_settings. -->
        <section
            v-if="canConfigureRounding"
            class="flex flex-col gap-3"
            data-testid="currencies-rounding"
        >
            <div class="flex flex-col gap-1">
                <h2 class="text-card-title">{{ t('currencies.rounding.title') }}</h2>
                <p class="text-[var(--color-text-muted)] text-pretty">{{ t('currencies.rounding.description') }}</p>
            </div>

            <LoadingState v-if="currenciesLoading" label-key="currencies.rounding.loading" />

            <PermissionDeniedState v-else-if="currenciesDenied" />

            <ErrorState
                v-else-if="currenciesError !== null"
                :message-key="currenciesError"
                @retry="loadCurrencies"
            />

            <div v-else class="table-frame overflow-x-auto rounded-xl">
                <table class="w-full text-table" data-testid="currencies-table">
                    <thead>
                        <tr class="table-head">
                            <th scope="col" class="p-3 text-start">{{ t('currencies.rounding.column.code') }}</th>
                            <th scope="col" class="p-3 text-start">{{ t('currencies.rounding.column.enabled') }}</th>
                            <th scope="col" class="p-3 text-start">{{ t('currencies.rounding.column.unit') }}</th>
                            <th scope="col" class="p-3 text-end">{{ t('currencies.rounding.column.actions') }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr
                            v-for="row in rows"
                            :key="row.code"
                            class="table-row"
                            :data-currency-code="row.code"
                        >
                            <td class="p-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="tabular-nums">{{ row.code }}</span>

                                    <!-- Read-only by owner decision: the API has
                                         no way to move the base, so this is a
                                         fact and not a control. -->
                                    <span
                                        v-if="row.isBase"
                                        class="status-badge rounded-full px-2 py-0.5"
                                        data-testid="currencies-base"
                                    >{{ t('currencies.rounding.base') }}</span>
                                </div>
                            </td>

                            <td class="p-3">
                                <input
                                    v-model="row.enabled"
                                    type="checkbox"
                                    class="size-5"
                                    :aria-label="t('currencies.rounding.column.enabled')"
                                    data-testid="currencies-enabled"
                                >
                            </td>

                            <td class="p-3">
                                <!-- type="text": DB-07. A number input binds to a
                                     JavaScript float, which is what §5.3's 0.01
                                     may never become. -->
                                <input
                                    v-model="row.unit"
                                    type="text"
                                    inputmode="decimal"
                                    class="field min-h-11 w-32 rounded-lg border px-3 tabular-nums text-[var(--color-text)]"
                                    :class="row.error === null
                                        ? 'border-[var(--color-border-strong)]'
                                        : 'border-[var(--color-danger)]'"
                                    :aria-label="t('currencies.rounding.column.unit')"
                                    :aria-invalid="row.error !== null"
                                    data-testid="currencies-unit"
                                >

                                <span
                                    v-if="row.error !== null"
                                    class="block text-[var(--color-danger)]"
                                >{{ row.error }}</span>
                            </td>

                            <td class="p-3">
                                <div class="flex items-center justify-end gap-2">
                                    <span
                                        v-if="savedCode === row.code"
                                        class="text-[var(--color-success)]"
                                        role="status"
                                        data-testid="currencies-saved"
                                    >{{ t('currencies.rounding.saved') }}</span>

                                    <button
                                        type="button"
                                        class="row-action min-h-11 rounded-lg px-3 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                                        :disabled="savingCode !== null"
                                        data-testid="currencies-save"
                                        @click="saveRounding(row)"
                                    >
                                        {{ savingCode === row.code ? t('action.saving') : t('action.save') }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-if="roundingSaveError !== null" class="text-[var(--color-danger)]" role="alert">
                {{ t(roundingSaveError) }}
            </p>
        </section>

        <!-- Half two — §13's "manual rate per currency · rate history", behind
             admin.fx_rates, which the Manager holds as well. -->
        <section class="flex flex-col gap-3" data-testid="currencies-rates">
            <div class="flex flex-col gap-1">
                <h2 class="text-card-title">{{ t('currencies.rates.title') }}</h2>
                <p class="text-[var(--color-text-muted)] text-pretty">{{ t('currencies.rates.description') }}</p>
            </div>

            <form
                class="flex flex-wrap items-end gap-3"
                data-testid="rates-form"
                @submit.prevent="record"
            >
                <label class="flex flex-col gap-1.5">
                    <span class="text-form-label text-[var(--color-text)]">{{ t('currencies.rates.field.from') }}</span>
                    <input
                        v-model="form.from"
                        type="text"
                        maxlength="3"
                        autocapitalize="characters"
                        class="field min-h-11 w-24 rounded-lg border border-[var(--color-border-strong)] px-3 uppercase text-[var(--color-text)]"
                        :aria-invalid="formErrors.from !== null"
                        data-testid="rates-from"
                    >
                    <span
                        v-if="formErrors.from !== null"
                        class="text-[var(--color-danger)]"
                    >{{ formErrors.from }}</span>
                </label>

                <label class="flex flex-col gap-1.5">
                    <span class="text-form-label text-[var(--color-text)]">{{ t('currencies.rates.field.to') }}</span>
                    <input
                        v-model="form.to"
                        type="text"
                        maxlength="3"
                        autocapitalize="characters"
                        class="field min-h-11 w-24 rounded-lg border border-[var(--color-border-strong)] px-3 uppercase text-[var(--color-text)]"
                        :aria-invalid="formErrors.to !== null"
                        data-testid="rates-to"
                    >
                    <span
                        v-if="formErrors.to !== null"
                        class="text-[var(--color-danger)]"
                    >{{ formErrors.to }}</span>
                </label>

                <label class="flex flex-col gap-1.5">
                    <span class="text-form-label text-[var(--color-text)]">{{ t('currencies.rates.field.rate') }}</span>
                    <!-- DB-07 again: the multiplier that reaches every converted
                         line of every quotation travels as a string. -->
                    <input
                        v-model="form.rate"
                        type="text"
                        inputmode="decimal"
                        class="field min-h-11 w-40 rounded-lg border px-3 tabular-nums text-[var(--color-text)]"
                        :class="formErrors.rate === null
                            ? 'border-[var(--color-border-strong)]'
                            : 'border-[var(--color-danger)]'"
                        :aria-invalid="formErrors.rate !== null"
                        data-testid="rates-rate"
                    >
                    <span
                        v-if="formErrors.rate !== null"
                        class="text-[var(--color-danger)]"
                    >{{ formErrors.rate }}</span>
                </label>

                <button
                    type="submit"
                    class="primary-action min-h-11 rounded-lg px-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="recording"
                    data-testid="rates-record"
                >
                    {{ recording ? t('currencies.rates.recording') : t('currencies.rates.record') }}
                </button>

                <p
                    v-if="recorded"
                    class="w-full text-[var(--color-success)]"
                    role="status"
                    data-testid="rates-recorded"
                >{{ t('currencies.rates.recorded') }}</p>

                <p v-if="recordError !== null" class="w-full text-[var(--color-danger)]" role="alert">
                    {{ t(recordError) }}
                </p>
            </form>

            <LoadingState v-if="ratesLoading" label-key="currencies.rates.loading" />

            <PermissionDeniedState v-else-if="ratesDenied" />

            <ErrorState v-else-if="ratesError !== null" :message-key="ratesError" @retry="loadRates" />

            <EmptyState
                v-else-if="rates.length === 0"
                title-key="currencies.rates.empty.title"
                message-key="currencies.rates.empty.message"
            />

            <div v-else class="table-frame overflow-x-auto rounded-xl">
                <table class="w-full text-table" data-testid="rates-table">
                    <thead>
                        <tr class="table-head">
                            <th scope="col" class="p-3 text-start">{{ t('currencies.rates.column.from') }}</th>
                            <th scope="col" class="p-3 text-start">{{ t('currencies.rates.column.to') }}</th>
                            <th scope="col" class="p-3 text-start">{{ t('currencies.rates.column.rate') }}</th>
                            <th scope="col" class="p-3 text-start">{{ t('currencies.rates.column.effective') }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-for="rate in rates" :key="rate.id" class="table-row" data-testid="rates-row">
                            <td class="p-3 tabular-nums">{{ rate.from_currency }}</td>
                            <td class="p-3 tabular-nums">{{ rate.to_currency }}</td>
                            <td class="p-3 tabular-nums">{{ rate.rate }}</td>
                            <td class="p-3 tabular-nums">{{ formatMoment(rate.effective_from) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav
                v-if="ratesPagination !== null && ratesPagination.total_pages > 1"
                class="flex items-center justify-between gap-3"
                :aria-label="t('currencies.pagination.label')"
                data-testid="rates-pagination"
            >
                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-3 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!ratesPagination.has_previous_page || ratesLoading"
                    data-testid="rates-previous"
                    @click="goToRatePage(ratesPagination.page - 1)"
                >
                    {{ t('currencies.pagination.previous') }}
                </button>

                <p class="tabular-nums text-[var(--color-text-muted)]">
                    {{ t('currencies.pagination.position', {
                        page: ratesPagination.page,
                        pages: ratesPagination.total_pages,
                        total: ratesPagination.total,
                    }) }}
                </p>

                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-3 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!ratesPagination.has_next_page || ratesLoading"
                    data-testid="rates-next"
                    @click="goToRatePage(ratesPagination.page + 1)"
                >
                    {{ t('currencies.pagination.next') }}
                </button>
            </nav>
        </section>
    </section>
</template>

<style scoped>
.field {
    background-color: var(--color-surface);
}

.primary-action {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
}

.row-action {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

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

.status-badge {
    background-color: color-mix(in srgb, var(--color-primary) 14%, transparent);
    color: var(--color-primary);
}
</style>
