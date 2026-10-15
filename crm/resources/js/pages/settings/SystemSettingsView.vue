<script setup lang="ts">
/**
 * *System Settings* — **the one settings page** (owner decision, 2026-08-29).
 *
 * ── One page, four sections, three permissions ─────────────────────────────
 *
 * §13 names screens 4, 5 and 6 separately. The owner merged them into one long
 * page on 2026-08-29; that is a change to the master documentation's screen
 * inventory and is recorded as a **pending `D-xx`** rather than applied
 * silently. This component is the page: its own form, then §13 screen 5's two
 * halves ({@link CurrenciesView}) and screen 6 ({@link SystemLimitsView}).
 *
 * ⚠️ **The three sections do not share a permission, and merging them naively
 * would have deleted a grant §3.11 makes.** §3.11 gives *system settings* and
 * *system limits* to the Super Admin alone, and **FX rates to the Manager as
 * well**. A single page behind `admin.system_settings` would therefore lock the
 * Manager out of a row they hold. So the route carries the **widest** of the
 * three — `admin.fx_rates` — and each section is drawn by its own:
 *
 * | section | permission |
 * |---|---|
 * | company & defaults (below) | `admin.system_settings` |
 * | rounding per currency | `admin.system_settings` (inside `CurrenciesView`) |
 * | exchange rates | `admin.fx_rates` — the route already required it |
 * | limits & SLAs | `admin.system_limits` |
 *
 * A section the caller does not hold is **not rendered and not requested**: a
 * guaranteed 403 buys nothing but a denial block inside a page they were
 * invited to open. `SEC-09` still applies — the server refuses regardless.
 *
 * ── Eight fields, not ten ──────────────────────────────────────────────────
 *
 * §13 names ten. The **logo** is Module 5 — a `files` row, `SEC-15`'s scan and
 * `D-38`'s permission-checked download — and the **PDF and email templates**
 * are Module 9, which owns what a template *is*. `SystemSetting` exposes ten
 * and this form draws ten (the two report settings joined in Module 13). **Owner decision of 2026-08-28, option (A):** no
 * disabled placeholders for the two that are not built. A control that cannot
 * be used is a promise the product has not made, and a person who clicks it
 * learns nothing except that the software is lying to them.
 *
 * ── Nothing here is authorization ──────────────────────────────────────────
 *
 * §3.12 rule 1 · `SEC-09`. The route carries `admin.system_settings` so the
 * menu and the guard agree with the endpoint, but the decision is the server's:
 * a 403 renders {@link PermissionDeniedState} because the request came back
 * refused, not because this component decided anything.
 *
 * Every field here is text: `defaults.tax_percent`, the one number, left with
 * `D-118` (F-48 · 2) — a quotation's and a supplier offer's rates are typed on them.
 *
 * ── Only what changed is sent ──────────────────────────────────────────────
 *
 * `UpdateSettings` writes one audit entry per field with the old and the new
 * value. Sending all ten on every save would fill `audit_log` with "changed X
 * to X", and `UpdateSettingsRequest` refuses an empty change set with a 422 —
 * so the save button does nothing when nothing was edited, rather than asking
 * the server to say no.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import { SETTING_KEYS, listCurrencies, readSettings, updateSettings, type SystemSettings } from '@/services/admin';
import { listUsers } from '@/services/identity';
import { useAuth } from '@/stores/auth';
import CurrenciesView from '@/pages/currencies/CurrenciesView.vue';
import SystemLimitsView from '@/pages/limits/SystemLimitsView.vue';

const { t } = useI18n();
const auth = useAuth();

/**
 * Which sections this caller gets. `SEC-09`'s visual complement, never the
 * check — and the reason each is *asked* rather than assumed is §3.12 rule 5:
 * the matrix is configuration, so a role holding one of these and not another
 * is a state an administrator can create without a deployment.
 */
const canConfigureSettings = computed(() => auth.hasPermission('admin.system_settings'));
const canConfigureLimits = computed(() => auth.hasPermission('admin.system_limits'));

/** What the server last said. The baseline every edit is compared against. */
const stored = ref<SystemSettings>({});
/** What is in the inputs. Never null — an unset field is an empty control. */
const draft = ref<Record<string, string>>({});

const loading = ref(true);
const denied = ref(false);
/** A lang-file key, or null. */
const loadError = ref<string | null>(null);

const saving = ref(false);
const saved = ref(false);
const saveError = ref<string | null>(null);
/** Keyed by setting key — the server's sentence for that field (§5.1). */
const fieldErrors = ref<Record<string, string>>({});

// ── S-02.2 / S-02.3: assisted input ────────────────────────────────────────
//
// One `<select>` and four suggestion lists, and the split is not cosmetic.
//
// **A `<select>` closes the set.** That is honest only where a document closes
// it: §14.2 names Arabic and English as the two languages this product ships
// in, so `locale.language` is a real choice between two known values.
//
// **Everything else stays typeable**, because nothing closes those sets — the
// server least of all: `SystemSetting::rule()` is `string` (an address for the
// e-mail), so the API accepts any value. A `<select>` there would
// be the client inventing a constraint the product has not made, and worse, a
// stored value outside the list would vanish from the control that is supposed
// to be showing it. `<datalist>` gives the dropdown without the lie: Safari
// draws the arrow, the suggestions are offered, and anything may still be typed.

/** §14.2 — the two languages, and the only closed set on this form. */
const LANGUAGES = [
    { value: 'ar', labelKey: 'language.arabic' },
    { value: 'en', labelKey: 'language.english' },
] as const;

/**
 * Suggestions, not rules. Owner decision of 2026-08-29: *suggest, and keep it
 * typeable*.
 *
 * `locale.date_format` offers three PHP format strings. No document names one;
 * these are spellings, not business values, and the field accepts any string.
 */
const DATE_FORMATS = ['d/m/Y', 'Y-m-d', 'd-m-Y'];

/**
 * The IANA zone list, from the platform rather than from a list written here.
 *
 * Feature-detected rather than assumed: `Intl.supportedValuesOf` is ES2023 and
 * this project's `lib` is ES2022, so it is not on the ambient type. The
 * narrow structural type below is paired with a real `typeof` check — it
 * describes what is actually called, and the empty array is what a runtime
 * without it gets. Measured in the test runtime: **418 zones**.
 */
function timeZones(): string[] {
    const supported = (Intl as { supportedValuesOf?: (key: string) => string[] }).supportedValuesOf;

    return typeof supported === 'function' ? supported('timeZone') : [];
}

const TIME_ZONES = timeZones();

/**
 * The codes `GET /currencies` offers, for `defaults.currency`.
 *
 * ponytail: a second read of `/currencies` — `CurrenciesView` below makes the
 * same call for the rounding table. Lift it into this component and pass the
 * rows down when one extra request on one admin screen starts to matter.
 *
 * Empty on failure rather than fatal: the field is a suggestion list, so losing
 * it costs the dropdown and nothing else. The section is already behind
 * `admin.system_settings`, which is the same row `GET /currencies` carries, so
 * this is not a request the caller was going to be refused.
 */
const currencyCodes = ref<string[]>([]);

/**
 * `D-129` — the active Managers the default report recipient may name. The
 * server refuses anyone else; this only offers the choice.
 *
 * ponytail: the first page of `GET /users` (25). A company with more active
 * Managers than that needs the select to page or search.
 */
const managers = ref<Array<{ id: string; name: string }>>([]);

/** The stored recipient stays visible even when they left the list (deactivated, or moved role). */
const recipientOptions = computed(() => {
    const stored = draft.value['reports.default_recipient'] ?? '';

    return stored === '' || managers.value.some((manager) => manager.id === stored)
        ? managers.value
        : [...managers.value, { id: stored, name: t('settings.recipient_not_manager') }];
});

/**
 * §8 asks for *error association*; the same mechanism carries the explanation.
 *
 * Both ids when there is an error, so a screen reader reads what the field is
 * for **and** what went wrong. The hint is always present, so it is always
 * first — the explanation before the complaint.
 */
function describedBy(key: string): string {
    return fieldErrors.value[key] === undefined ? `hint-${key}` : `hint-${key} error-${key}`;
}

function suggestionsFor(key: string): string[] {
    if (key === 'defaults.currency') {
        return currencyCodes.value;
    }

    if (key === 'locale.timezone') {
        return TIME_ZONES;
    }

    if (key === 'locale.date_format') {
        return DATE_FORMATS;
    }

    return [];
}

const changed = computed(() => {
    const values: Record<string, string> = {};

    for (const key of SETTING_KEYS) {
        const next = draft.value[key] ?? '';

        // `null` and `''` are the same edit to make: a field that was never set
        // and is still blank has not changed.
        if (next !== (stored.value[key] ?? '')) {
            values[key] = next;
        }
    }

    return values;
});

function adopt(fromServer: SystemSettings): void {
    stored.value = fromServer;

    const next: Record<string, string> = {};

    for (const key of SETTING_KEYS) {
        // Null is "never set" and renders as an empty control — never as the
        // word "null", which is what a template interpolation would print.
        next[key] = fromServer[key] ?? '';
    }

    draft.value = next;
}

async function load(): Promise<void> {
    // Not requested at all without the row. §5.1: do not show what the role may
    // not reach — and do not ask for it either.
    if (!canConfigureSettings.value) {
        loading.value = false;

        return;
    }

    loading.value = true;
    denied.value = false;
    loadError.value = null;

    try {
        adopt(await readSettings());
    } catch (error) {
        if (error instanceof ApiError && error.status === 403) {
            denied.value = true;
        } else {
            loadError.value = 'settings.error.load';
        }
    } finally {
        loading.value = false;
    }
}

async function save(): Promise<void> {
    const values = changed.value;

    saved.value = false;
    saveError.value = null;
    fieldErrors.value = {};

    // Nothing to say, so nothing is said. The server would answer 422.
    if (Object.keys(values).length === 0) {
        return;
    }

    saving.value = true;

    try {
        adopt(await updateSettings(values));
        saved.value = true;
    } catch (error) {
        if (error instanceof ApiError) {
            const named: Record<string, string> = {};

            for (const key of SETTING_KEYS) {
                // The server's field path is the submitted one, `settings.` and
                // all — `UpdateSettingsRequest`'s rule paths are what it echoes.
                const message = error.messageFor(`settings.${key}`);

                if (message !== null) {
                    named[key] = message;
                }
            }

            fieldErrors.value = named;

            // A refusal with no field named still has to be visible: §6.6 says
            // a toast "must not be the only place an error is explained", and
            // silence is worse than a toast.
            saveError.value = Object.keys(named).length === 0 ? 'settings.error.save' : null;
        } else {
            saveError.value = 'settings.error.save';
        }
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    await load();

    if (!canConfigureSettings.value) {
        return;
    }

    try {
        currencyCodes.value = (await listCurrencies()).map((currency) => currency.code);
    } catch {
        // The dropdown loses its suggestions; the field keeps working, because
        // it was never a closed list to begin with.
        currencyCodes.value = [];
    }

    try {
        managers.value = (await listUsers({ isActive: true, roleSlug: 'manager' })).items
            .map((user) => ({ id: user.id, name: user.name }));
    } catch {
        // No list, no choices: the stored value still shows, and the server
        // still refuses anyone who is not an active Manager.
        managers.value = [];
    }
});
</script>

<template>
    <section class="flex w-full flex-col gap-10">
        <header class="flex flex-col gap-1">
            <h1 class="text-page-title" data-testid="settings-heading">{{ t('settings.title') }}</h1>
            <p class="text-[var(--color-text-muted)] text-pretty">{{ t('settings.subtitle') }}</p>
        </header>

        <!-- Section one — §13 screen 4's eight fields, and Module 13's two report settings. -->
        <section
            v-if="canConfigureSettings"
            class="flex flex-col gap-4"
            data-testid="settings-section"
        >
            <div class="flex flex-col gap-1">
                <h2 class="text-card-title">{{ t('settings.section.title') }}</h2>
                <p class="text-[var(--color-text-muted)] text-pretty">{{ t('settings.section.description') }}</p>
            </div>

        <LoadingState v-if="loading" />

        <PermissionDeniedState v-else-if="denied" />

        <ErrorState v-else-if="loadError !== null" :message-key="loadError" @retry="load" />

        <form v-else class="flex flex-col gap-6" data-testid="settings-save" @submit.prevent="save">
            <div class="grid gap-4 md:grid-cols-2">
                <label
                    v-for="key in SETTING_KEYS"
                    :key="key"
                    class="flex flex-col gap-1.5"
                    :data-setting-key="key"
                >
                    <span class="text-form-label text-[var(--color-text)]">
                        {{ t(`settings.field.${key.replace('.', '_')}`) }}
                    </span>

                    <!-- §14.2 closes this set at two, so this one is a real
                         `<select>`. Every other field on this form is a text
                         control with suggestions: nothing closes those sets,
                         and a select would drop a stored value that is not in
                         its list. -->
                    <select
                        v-if="key === 'locale.language'"
                        v-model="draft[key]"
                        :aria-invalid="fieldErrors[key] !== undefined"
                        :aria-describedby="describedBy(key)"
                        class="field min-h-11 rounded-lg border px-3 text-[var(--color-text)]"
                        :class="fieldErrors[key] !== undefined
                            ? 'border-[var(--color-danger)]'
                            : 'border-[var(--color-border-strong)]'"
                        data-testid="setting-select"
                    >
                        <option value="">{{ t('settings.unset') }}</option>
                        <option
                            v-for="language in LANGUAGES"
                            :key="language.value"
                            :value="language.value"
                        >{{ t(language.labelKey) }}</option>
                    </select>

                    <!-- `D-129`: closed by the data, not the document — the
                         active Managers, and nobody else. -->
                    <select
                        v-else-if="key === 'reports.default_recipient'"
                        v-model="draft[key]"
                        :aria-invalid="fieldErrors[key] !== undefined"
                        :aria-describedby="describedBy(key)"
                        class="field min-h-11 rounded-lg border px-3 text-[var(--color-text)]"
                        :class="fieldErrors[key] !== undefined
                            ? 'border-[var(--color-danger)]'
                            : 'border-[var(--color-border-strong)]'"
                    >
                        <option value="">{{ t('settings.unset') }}</option>
                        <option
                            v-for="manager in recipientOptions"
                            :key="manager.id"
                            :value="manager.id"
                        >{{ manager.name }}</option>
                    </select>

                    <!-- `list` turns the text control into a dropdown without
                         closing the set. The go-live date is a calendar date. -->
                    <input
                        v-else
                        v-model="draft[key]"
                        :type="key === 'reports.go_live_date' ? 'date' : 'text'"
                        :list="suggestionsFor(key).length > 0 ? `options-${key}` : undefined"
                        :aria-invalid="fieldErrors[key] !== undefined"
                        :aria-describedby="describedBy(key)"
                        class="field min-h-11 rounded-lg border px-3 text-[var(--color-text)]"
                        :class="fieldErrors[key] !== undefined
                            ? 'border-[var(--color-danger)]'
                            : 'border-[var(--color-border-strong)]'"
                    >

                    <datalist v-if="suggestionsFor(key).length > 0" :id="`options-${key}`">
                        <option
                            v-for="option in suggestionsFor(key)"
                            :key="option"
                            :value="option"
                        />
                    </datalist>

                    <!-- What to write here. The Design System names no hint
                         pattern (§5.2 asks a Form view for sections, required
                         markers and inline validation, and stops), so this
                         establishes one on the owner's request of 2026-08-29 —
                         recorded rather than assumed to be house style. -->
                    <span
                        :id="`hint-${key}`"
                        class="text-table text-[var(--color-text-muted)] text-pretty"
                        data-testid="setting-hint"
                    >{{ t(`settings.hint.${key.replace('.', '_')}`) }}</span>

                    <!-- The server's sentence, localised by the server for this
                         request. §9.5: the message carries the meaning, the red
                         only reinforces it. -->
                    <span
                        v-if="fieldErrors[key] !== undefined"
                        :id="`error-${key}`"
                        class="text-table text-[var(--color-danger)]"
                    >{{ fieldErrors[key] }}</span>
                </label>
            </div>

            <p v-if="saveError !== null" class="text-[var(--color-danger)]" role="alert">
                {{ t(saveError) }}
            </p>

            <p
                v-if="saved"
                class="text-[var(--color-success)]"
                role="status"
                data-testid="settings-saved"
            >{{ t('settings.saved') }}</p>

            <div class="flex">
                <button
                    type="submit"
                    class="action inline-flex min-h-11 items-center justify-center rounded-lg bg-[var(--color-primary)] px-5 text-[var(--color-primary-text)] disabled:opacity-60"
                    :disabled="saving"
                >
                    {{ saving ? t('settings.saving') : t('action.save') }}
                </button>
            </div>
        </form>
        </section>

        <!-- §13 screen 5, both halves. `CurrenciesView` draws its rounding half
             by `admin.system_settings` and its rates half unconditionally —
             the route already required `admin.fx_rates` to get here. -->
        <CurrenciesView />

        <!-- §13 screen 6. Gated here rather than inside the component: the page
             owns the composition, so the section that does not belong to this
             caller is simply never mounted and never fetches. -->
        <SystemLimitsView v-if="canConfigureLimits" />
    </section>
</template>

<style scoped>
.field {
    background-color: var(--color-surface);
}

.action {
    touch-action: manipulation;
    transition-property: background-color, color;
    transition-duration: 160ms;
    transition-timing-function: ease-out;
}

/* §6.2: "All button variants have default, hover, active, focus, disabled, and
   loading states." The transition above was declared for properties nothing
   ever changed. The fill is a Tailwind utility on the element, so these two
   rules are what override it — `.action:hover` outranks a single class. */
.action:hover:not(:disabled) {
    background-color: var(--color-primary-hover);
}

.action:active:not(:disabled) {
    background-color: var(--color-primary-active);
}

@media (prefers-reduced-motion: reduce) {
    .action {
        transition: none;
    }
}
</style>
