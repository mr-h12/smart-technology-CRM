<script setup lang="ts">
/**
 * §3.3's `import (Excel)` row, as a dialog on the Customers screen.
 *
 * ── Why a dialog and not a screen ──────────────────────────────────────────
 *
 * §8 names no *Import* item for any role. Point 4.5b was the cost of a menu
 * offering a destination §8 does not name, and a new route would repeat it in
 * a milder form. Import is an action on the Customers screen, so it lives
 * where that screen is — no route, no nav item.
 *
 * ── CSV, not Excel, and the narrowing is the owner's ───────────────────────
 *
 * §3.3 calls the row "import (Excel)". The owner narrowed the format to CSV on
 * 2026-08-29 — `fgetcsv`, no library — and Point 3.6 shipped the endpoint that
 * way. **The permission keeps the document's name.** The narrowing is about
 * the format and is recorded in `CHECKLIST.md` awaiting a `D-xx`.
 *
 * ── Four numbers, one of which is this screen's own ────────────────────────
 *
 * `ImportBatchPayload` sends three — `row_count`, `imported_count`,
 * `incomplete_count` — and deliberately no failure count, because "a field
 * that can disagree with the two it is derived from is a field that eventually
 * will". So failures are `row_count - imported_count`, computed here.
 *
 * ⚠️ **A failed row and an incomplete row are not the same thing.** `D-31`
 * says an import "accepts incomplete data (records flagged incomplete)" — those
 * rows **were** imported and are counted in `imported_count`. A failure is a
 * row that saved nothing at all (a blank name violates the table's CHECK). The
 * two counts overlap in neither direction, and the labels say so.
 *
 * ── The limit is stated here and enforced nowhere near here ────────────────
 *
 * Design System §6.3 requires a file upload to show "allowed formats,
 * configured size limit". The ceiling is `config('files.max_size_bytes')`
 * (30 MB, `D-71`), which is server-side configuration the SPA has no endpoint
 * for: `GET /system-limits` needs `admin.system_limits` — a permission the
 * Manager who imports does not hold — and its `limits.max_file_size_mb` is
 * unseeded besides.
 *
 * ponytail: the number is a string in the lang file, so §6.3 is satisfied and
 * the label can drift if `FILES_MAX_SIZE_BYTES` is ever set. Recorded as debt.
 * It is a **label, not a check** — the server refuses an oversized file with
 * its own sentence, and that sentence is what is shown.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import { importCustomers, type ImportBatch } from '@/services/customers';

const props = defineProps<{ open: boolean }>();

/**
 * `showIncomplete` rather than a `RouterLink`: §10 asks for "a dedicated
 * filter" and Point 4.2 built one, but no filter state reaches the URL (a
 * standing debt), so a link could not carry it. The list owns the filter and
 * is told to apply it.
 */
const emit = defineEmits<{ imported: []; showIncomplete: []; cancel: [] }>();

const { t } = useI18n();

const chosen = ref<File | null>(null);
const batch = ref<ImportBatch | null>(null);
const busy = ref(false);
const errorMessage = ref('');

/** The count the server does not send, from the two it does. */
const failedCount = computed(() =>
    batch.value === null ? 0 : batch.value.row_count - batch.value.imported_count,
);

function reset(): void {
    chosen.value = null;
    batch.value = null;
    busy.value = false;
    errorMessage.value = '';
}

function onPick(event: Event): void {
    const input = event.target as HTMLInputElement;

    chosen.value = input.files?.[0] ?? null;
    // A new file is a new question: the previous result and the previous
    // refusal both described a different file.
    batch.value = null;
    errorMessage.value = '';
}

async function submit(): Promise<void> {
    if (chosen.value === null || busy.value) {
        return;
    }

    busy.value = true;
    errorMessage.value = '';

    try {
        batch.value = await importCustomers(chosen.value);
        emit('imported');
    } catch (error) {
        // §6.1: the server's own sentence, near the field, with the chosen file
        // preserved — re-picking a file the person already picked is the
        // browser's worst dialog.
        batch.value = null;
        errorMessage.value = error instanceof ApiError
            ? error.messageFor('file') ?? error.message
            : t('customers.import.error');
    } finally {
        busy.value = false;
    }
}

function onKeydown(event: KeyboardEvent): void {
    // §6.1: "Escape closes dialogs/menus without discarding silently." There is
    // no entered work to discard — a file selection is one click to remake, and
    // the result is read before it is dismissed.
    if (event.key === 'Escape' && props.open) {
        emit('cancel');
    }
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));

watch(() => props.open, (open) => {
    if (open) {
        reset();
    }
});
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4" data-testid="customer-import-modal">
        <div class="dialog-scrim absolute inset-0" @click="emit('cancel')" />

        <div
            class="dialog-panel relative flex w-full max-w-lg flex-col gap-4 rounded-2xl p-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="customer-import-title"
        >
            <h2 id="customer-import-title" class="text-card-title">{{ t('customers.import.title') }}</h2>

            <!-- §6.3: allowed formats and the configured size limit, stated
                 before anything is chosen. -->
            <p class="text-[var(--color-text-muted)]">{{ t('customers.import.hint') }}</p>

            <label class="flex flex-col gap-1.5">
                <span class="sr-only">{{ t('customers.import.choose') }}</span>
                <input
                    type="file"
                    accept=".csv,text/csv"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="customer-import-file"
                    @change="onPick"
                />
            </label>

            <p class="text-[var(--color-text-muted)]" data-testid="customer-import-file-name">
                {{ chosen === null ? t('customers.import.noFile') : chosen.name }}
            </p>

            <!-- §6.1: server validation near the affected field. -->
            <p v-if="errorMessage !== ''" class="import-error rounded-lg px-3 py-2" data-testid="customer-import-error">
                {{ errorMessage }}
            </p>

            <div v-if="batch !== null" class="import-result flex flex-col gap-2 rounded-lg p-3" data-testid="customer-import-result">
                <h3 class="font-semibold">{{ t('customers.import.resultTitle') }}</h3>

                <p class="tabular-nums">{{ t('customers.import.rows') }}: {{ batch.row_count }}</p>
                <p class="tabular-nums">{{ t('customers.import.imported') }}: {{ batch.imported_count }}</p>
                <!-- `D-31`: these rows were imported. They are not failures. -->
                <p class="tabular-nums">{{ t('customers.import.incomplete') }}: {{ batch.incomplete_count }}</p>
                <p class="tabular-nums" data-testid="customer-import-failed">
                    {{ t('customers.import.failed') }}: {{ failedCount }}
                </p>

                <!-- §10: "Flagged 'incomplete' with a dedicated filter." Offered
                     only when there is something for it to match. -->
                <button
                    v-if="batch.incomplete_count > 0"
                    type="button"
                    class="row-action min-h-11 self-start rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="customer-import-show-incomplete"
                    @click="emit('showIncomplete')"
                >
                    {{ t('customers.import.showIncomplete') }}
                </button>
            </div>

            <div class="flex flex-wrap justify-end gap-2">
                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-4 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="customer-import-cancel"
                    @click="emit('cancel')"
                >
                    {{ t('action.cancel') }}
                </button>

                <!-- §6.1: "Loading controls retain their width and show a text
                     alternative" — hence a word, not a bare spinner. -->
                <button
                    type="button"
                    class="submit-action min-h-11 rounded-lg px-4 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="chosen === null || busy"
                    data-testid="customer-import-submit"
                    @click="submit"
                >
                    {{ busy ? t('customers.import.submitting') : t('customers.import.submit') }}
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.dialog-scrim {
    background-color: color-mix(in srgb, var(--color-text) 45%, transparent);
}

.dialog-panel {
    background-color: var(--color-surface-raised);
    border: 1px solid var(--color-border);
    box-shadow: var(--shadow-3, var(--shadow-2));
}

.form-field {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.row-action {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.submit-action {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
}

.import-error {
    background-color: color-mix(in srgb, var(--color-danger) 12%, transparent);
    color: var(--color-danger);
}

.import-result {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-border);
}
</style>
