<script setup lang="ts">
/**
 * §13 screen 6 — *Limits & SLAs*.
 *
 * ── Six, and the sixth is `D-75`'s ─────────────────────────────────────────
 *
 * §13 names five: stale-deal threshold · daily report deadline · quotation
 * approval SLA · weekly review window · maximum file size. `SystemLimit` adds
 * `identity.lockout_minutes`, and including it is the point rather than an
 * extra: it is the **only limit the documentation values**, the only one with a
 * live reader (`AuthenticateUser`, on every failed login), and a screen that
 * drew §13's five without it would leave the one working limit in the system
 * uneditable through the screen built to edit limits.
 *
 * ── The server owns the list, the order and the units ──────────────────────
 *
 * `DatabaseSystemLimitRepository::all()` iterates `SystemLimit::cases()`, so
 * the response is the whole enum in §13's order, each entry carrying its
 * `unit` and its `value_type`. This screen restates none of that. A key list
 * copied into the client is the copy that goes stale, and §13 screen 6 mixes
 * days, hours and megabytes on one form — a threshold in days cannot be shown
 * beside an SLA in hours without saying which is which, which is why
 * `system_limits` has a `unit` column and `settings` does not.
 *
 * The unit arrives as an English word and is **translated here**, not printed.
 * The set is closed by the enum, so an unrecognised one renders nothing rather
 * than leaking a key into the page.
 *
 * ── Every value is a string ────────────────────────────────────────────────
 *
 * `SystemLimit::rule()` is a `regex` on the digits and not `integer`, and the
 * column stores text. So every control is `type="text"` and `value_type` picks
 * the **keypad** and nothing else: a `type="number"` would hand the API back a
 * JavaScript number, and `1e3` and `1.5` are values the boundary exists to
 * refuse rather than to receive rounded.
 *
 * ── Nothing here is authorization ──────────────────────────────────────────
 *
 * §3.12 rule 1 · `SEC-09`. The route carries `admin.system_limits` — §3.11's
 * own row, one line **below** *system settings* and deliberately not it — so
 * the menu, the guard and the endpoint name the same thing. The decision is
 * still the server's: a 403 renders {@link PermissionDeniedState} because the
 * request came back refused.
 *
 * ── Only what changed is sent ──────────────────────────────────────────────
 *
 * `UpdateSystemLimits` writes one audit entry per limit with the old and the
 * new value (`AUD-01` — a threshold that decides when a deal goes stale is not
 * silent), and `UpdateSystemLimitsRequest` refuses an empty change set with a
 * 422. So the button does nothing when nothing was edited, rather than asking
 * the server to say no.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import { readLimits, updateLimits, type SystemLimits } from '@/services/admin';

const { t } = useI18n();

/**
 * One limit, carrying its own draft and its own baseline.
 *
 * A row rather than three maps keyed by name, for `CurrenciesView`'s reason:
 * `noUncheckedIndexedAccess` makes every keyed read a `| undefined`, and what
 * changed is a per-limit question anyway.
 */
interface LimitRow {
    key: string;
    unit: string | null;
    valueType: string;
    /** What the server last said. `null` is "never configured", not empty. */
    stored: string | null;
    /** What is in the input. Never null — an unset limit is an empty control. */
    value: string;
    /** The server's sentence for this limit, localised by the server. */
    error: string | null;
}

const rows = ref<LimitRow[]>([]);
const loading = ref(true);
const denied = ref(false);
/** A lang-file key, or null. */
const loadError = ref<string | null>(null);

const saving = ref(false);
const saved = ref(false);
const saveError = ref<string | null>(null);

/**
 * The units `SystemLimit::unit()` can return. Closed by the enum, and checked
 * rather than trusted: an unknown word would otherwise be handed to `t()` and
 * printed as `limits.unit.furlongs` in front of a person.
 */
const UNIT_KEYS = new Set(['days', 'hours', 'megabytes', 'minutes']);

function unitLabel(unit: string | null): string | null {
    return unit !== null && UNIT_KEYS.has(unit) ? t(`limits.unit.${unit}`) : null;
}

/** Each key holds exactly one dot, and `__()` reads a dot as nesting. */
function labelFor(key: string): string {
    return t(`limits.field.${key.replace('.', '_')}`);
}

const changed = computed(() => {
    const values: Record<string, string> = {};

    for (const row of rows.value) {
        // `null` and `''` are the same edit to make: a limit that was never
        // configured and is still blank has not changed.
        if (row.value !== (row.stored ?? '')) {
            values[row.key] = row.value;
        }
    }

    return values;
});

function adopt(limits: SystemLimits): void {
    // `Object.entries` preserves insertion order, which is the server's — the
    // enum's, which is §13's.
    rows.value = Object.entries(limits).map(([key, limit]) => ({
        key,
        unit: limit.unit,
        valueType: limit.value_type,
        stored: limit.value,
        // Null renders as an empty control, never as the word "null", which is
        // what a template interpolation would print.
        value: limit.value ?? '',
        error: null,
    }));
}

async function load(): Promise<void> {
    loading.value = true;
    denied.value = false;
    loadError.value = null;

    try {
        adopt(await readLimits());
    } catch (error) {
        if (error instanceof ApiError && error.status === 403) {
            denied.value = true;
        } else {
            loadError.value = 'limits.error.load';
        }
    } finally {
        loading.value = false;
    }
}

async function save(): Promise<void> {
    const values = changed.value;

    saved.value = false;
    saveError.value = null;

    for (const row of rows.value) {
        row.error = null;
    }

    // Nothing to say, so nothing is said. The server would answer 422.
    if (Object.keys(values).length === 0) {
        return;
    }

    saving.value = true;

    try {
        adopt(await updateLimits(values));
        saved.value = true;
    } catch (error) {
        if (error instanceof ApiError) {
            let named = false;

            for (const row of rows.value) {
                // The server's field path is the **submitted** one, `limits.`
                // prefix and all — measured against the running server, where a
                // refusal on `limits.stale_deal_days` comes back as
                // `limits.limits.stale_deal_days`.
                row.error = error.messageFor(`limits.${row.key}`);
                named = named || row.error !== null;
            }

            // §6.6: a refusal that named no field still has to be visible.
            saveError.value = named ? null : 'limits.error.save';
        } else {
            saveError.value = 'limits.error.save';
        }
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>

<template>
    <section class="flex w-full flex-col gap-6">
        <header class="flex flex-col gap-1">
            <h1 class="text-page-title" data-testid="limits-heading">{{ t('limits.title') }}</h1>
            <p class="text-[var(--color-text-muted)] text-pretty">{{ t('limits.subtitle') }}</p>
        </header>

        <LoadingState v-if="loading" label-key="limits.loading" />

        <PermissionDeniedState v-else-if="denied" />

        <ErrorState v-else-if="loadError !== null" :message-key="loadError" @retry="load" />

        <form v-else class="flex flex-col gap-6" data-testid="limits-save" @submit.prevent="save">
            <div class="grid gap-4 md:grid-cols-2">
                <label
                    v-for="row in rows"
                    :key="row.key"
                    class="flex flex-col gap-1.5"
                    :data-limit-key="row.key"
                >
                    <span class="text-form-label text-[var(--color-text)]">{{ labelFor(row.key) }}</span>

                    <div class="flex items-center gap-2">
                        <!-- type="text" on every one of them. The value is
                             stored and validated as a string; value_type picks
                             the keypad, not the binding. -->
                        <input
                            v-model="row.value"
                            type="text"
                            :inputmode="row.valueType === 'integer' ? 'numeric' : undefined"
                            :aria-invalid="row.error !== null"
                            :aria-describedby="row.error === null ? undefined : `error-${row.key}`"
                            class="field min-h-11 w-full rounded-lg border px-3 tabular-nums text-[var(--color-text)]"
                            :class="row.error === null
                                ? 'border-[var(--color-border-strong)]'
                                : 'border-[var(--color-danger)]'"
                        >

                        <!-- The unit §13 needs on a form that mixes days, hours
                             and megabytes. Absent for a time of day, where the
                             value is the reading. -->
                        <span
                            v-if="unitLabel(row.unit) !== null"
                            class="shrink-0 text-[var(--color-text-muted)]"
                            data-testid="limits-unit"
                        >{{ unitLabel(row.unit) }}</span>
                    </div>

                    <!-- The server's sentence, localised by the server for this
                         request. §9.5: the message carries the meaning, the red
                         only reinforces it. -->
                    <span
                        v-if="row.error !== null"
                        :id="`error-${row.key}`"
                        class="text-table text-[var(--color-danger)]"
                    >{{ row.error }}</span>
                </label>
            </div>

            <p v-if="saveError !== null" class="text-[var(--color-danger)]" role="alert">
                {{ t(saveError) }}
            </p>

            <p
                v-if="saved"
                class="text-[var(--color-success)]"
                role="status"
                data-testid="limits-saved"
            >{{ t('limits.saved') }}</p>

            <div class="flex">
                <button
                    type="submit"
                    class="action inline-flex min-h-11 items-center justify-center rounded-lg bg-[var(--color-primary)] px-5 text-[var(--color-on-primary)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)] disabled:opacity-60"
                    :disabled="saving"
                >
                    {{ saving ? t('action.saving') : t('action.save') }}
                </button>
            </div>
        </form>
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

@media (prefers-reduced-motion: reduce) {
    .action {
        transition: none;
    }
}
</style>
