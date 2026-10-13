<script setup lang="ts">
/**
 * §3.3's `import (Excel)` row, as a dialog on the Customers screen — and since
 * F-09 · 1.5 `D-85`'s on the Suppliers screen. The caller passes the title and
 * the upload; the counts, the refusal and the incomplete link are the same for
 * both, so they are written once.
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
 * ── The rows that did not import, named (F-20 · 1.5, `D-94`) ───────────────
 *
 * `rejected[]` (the row and the server's sentence, already in the request's
 * language) and `skipped[]` (the duplicates' rows) are each listed and counted
 * by their own length. The screen computes nothing: the old "Not imported"
 * figure, `row_count - imported_count`, mixed the two once duplicates were
 * skipped, and the owner ruled it out (2026-09-27). Every row is drawn, in a
 * list that scrolls, because `D-94` names every row.
 *
 * ⚠️ **A rejected row and an incomplete row are not the same thing.** `D-31`
 * says an import "accepts incomplete data (records flagged incomplete)" — those
 * rows **were** imported and are counted in `imported_count`. A rejected row
 * saved nothing at all. The two overlap in neither direction.
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
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useModalDialog } from '@/components/useModalDialog';
import { ApiError, type ImportBatch } from '@/api';

const props = defineProps<{ open: boolean; title: string; upload: (file: File) => Promise<ImportBatch> }>();

/**
 * `showIncomplete` rather than a `RouterLink`: §10 asks for "a dedicated
 * filter" and Point 4.2 built one, but no filter state reaches the URL (a
 * standing debt), so a link could not carry it. The list owns the filter and
 * is told to apply it.
 */
const emit = defineEmits<{ imported: []; showIncomplete: []; cancel: [] }>();

const { t } = useI18n();
const dialog = ref<HTMLDialogElement | null>(null);

useModalDialog(dialog);

const chosen = ref<File | null>(null);
const batch = ref<ImportBatch | null>(null);
const busy = ref(false);
const errorMessage = ref('');

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
        batch.value = await props.upload(chosen.value);
        emit('imported');
    } catch (error) {
        // §6.1: the server's own sentence, near the field, with the chosen file
        // preserved — re-picking a file the person already picked is the
        // browser's worst dialog.
        batch.value = null;
        errorMessage.value = error instanceof ApiError
            ? error.messageFor('file') ?? error.message
            : t('import.error');
    } finally {
        busy.value = false;
    }
}

/**
 * A-30: an upload in flight is not abandoned by a stray key or click.
 *
 * §6.1: "Escape closes dialogs/menus without discarding silently." There is no
 * entered work to discard — a file selection is one click to remake, and the
 * result is read before it is dismissed.
 */
function cancel(): void {
    if (!busy.value) {
        emit('cancel');
    }
}

watch(() => props.open, (open) => {
    if (open) {
        reset();
    }
});
</script>

<template>
    <dialog
        v-if="open"
        ref="dialog"
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
        aria-labelledby="import-title"
        data-testid="import-modal"
        @cancel.prevent="cancel"
    >
        <div class="dialog-scrim absolute inset-0" @click="cancel" />

        <div class="dialog-panel relative flex max-h-full w-full max-w-lg flex-col gap-4 overflow-y-auto rounded-2xl p-6">
            <h2 id="import-title" class="text-card-title">{{ title }}</h2>

            <!-- §6.3: allowed formats and the configured size limit, stated
                 before anything is chosen. -->
            <p id="import-hint" class="text-[var(--color-text-muted)]">{{ t('import.hint') }}</p>

            <!-- A-24: the browser's own file button speaks the browser's language;
                 this label speaks the page's, and the input stays focusable under it. -->
            <label class="file-pick row-action inline-flex min-h-11 items-center self-start rounded-lg px-4">
                {{ t('files.choose') }}
                <input
                    type="file"
                    accept=".csv,text/csv"
                    class="sr-only"
                    aria-describedby="import-file-name import-hint"
                    data-testid="import-file"
                    @change="onPick"
                />
            </label>

            <p id="import-file-name" class="min-w-0 break-words text-[var(--color-text-muted)]" data-testid="import-file-name">
                {{ chosen === null ? t('files.noFile') : chosen.name }}
            </p>

            <!-- §6.1: server validation near the affected field; A-11: announced. -->
            <p v-if="errorMessage !== ''" class="import-error rounded-lg px-3 py-2" role="alert" data-testid="import-error">
                {{ errorMessage }}
            </p>

            <!-- A-31: present from load and empty; a live region announces what is set into it. -->
            <div role="status" data-testid="import-status">
                <div v-if="batch !== null" class="import-result flex flex-col gap-2 rounded-lg p-3" data-testid="import-result">
                    <h3 class="font-semibold">{{ t('import.resultTitle') }}</h3>

                    <p class="tabular-nums">{{ t('import.rows') }}: {{ batch.row_count }}</p>
                    <p class="tabular-nums">{{ t('import.imported') }}: {{ batch.imported_count }}</p>
                    <!-- `D-31`: these rows were imported. They are not failures. -->
                    <p class="tabular-nums">{{ t('import.incomplete') }}: {{ batch.incomplete_count }}</p>
                    <p class="tabular-nums" data-testid="import-rejected">{{ t('import.rejected') }}: {{ batch.rejected.length }}</p>
                    <ul
                        v-if="batch.rejected.length > 0"
                        class="import-rows max-h-40 overflow-y-auto rounded-lg px-3 py-2 tabular-nums"
                        tabindex="0"
                        :aria-label="t('import.rejected')"
                        data-testid="import-rejected-rows"
                    >
                        <li v-for="line in batch.rejected" :key="line.row">{{ t('import.row', { row: line.row }) }}: {{ line.message }}</li>
                    </ul>

                    <p class="tabular-nums" data-testid="import-skipped">{{ t('import.skipped') }}: {{ batch.skipped.length }}</p>
                    <ul
                        v-if="batch.skipped.length > 0"
                        class="import-rows max-h-40 overflow-y-auto rounded-lg px-3 py-2 tabular-nums"
                        tabindex="0"
                        :aria-label="t('import.skipped')"
                        data-testid="import-skipped-rows"
                    >
                        <li v-for="row in batch.skipped" :key="row">{{ t('import.row', { row }) }}</li>
                    </ul>

                    <!-- §10: "Flagged 'incomplete' with a dedicated filter." Offered
                         only when there is something for it to match. -->
                    <button
                        v-if="batch.incomplete_count > 0"
                        type="button"
                        class="row-action min-h-11 self-start rounded-lg px-3"
                        data-testid="import-show-incomplete"
                        @click="emit('showIncomplete')"
                    >
                        {{ t('import.showIncomplete') }}
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-2">
                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-4"
                    data-testid="import-cancel"
                    @click="emit('cancel')"
                >
                    <!-- A-59: after a result there is nothing left to cancel. -->
                    {{ batch === null ? t('action.cancel') : t('action.close') }}
                </button>

                <!-- §6.1: "Loading controls retain their width and show a text
                     alternative" — hence a word, not a bare spinner. -->
                <button
                    type="button"
                    class="submit-action min-h-11 rounded-lg px-4 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="chosen === null || busy"
                    data-testid="import-submit"
                    @click="submit"
                >
                    {{ busy ? t('import.submitting') : t('import.submit') }}
                </button>
            </div>
        </div>
    </dialog>
</template>

<style scoped>
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

.import-rows {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border);
}
</style>
