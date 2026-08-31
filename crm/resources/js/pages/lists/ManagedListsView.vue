<script setup lang="ts">
/**
 * `DB-05`'s four managed lists — **the screen §13 does not name**.
 *
 * Owner decision of 2026-08-28, reconfirmed 2026-08-29 after S-02 merged
 * §13's screens 4, 5 and 6 into one page: this is a **dedicated route** and not
 * a fourth section of `/settings`. Recorded as a pending `D-xx` in
 * `CHECKLIST.md`; `docs/` is untouched.
 *
 * ── Why it is not a section of the settings page ───────────────────────────
 *
 * Because `/settings` is guarded by `admin.fx_rates` and **this screen is
 * guarded by nothing but a session**. `routes/api.php` sets out the reasoning
 * at length: §3.11 has no row for managed lists, §8 puts Customers on six
 * roles' screens and Catalog on five, and not one of those screens renders
 * without a sector or a unit. Folding this into the settings page would hide
 * the sector list from every role that needs to read it, which is the opposite
 * of the module's acceptance criterion.
 *
 * ── The read is open, the write is the Super Admin's ───────────────────────
 *
 * `GET /managed-lists/{list}` takes authentication alone;
 * `POST /managed-lists/{list}` takes `admin.system_settings`. So the table is
 * drawn for everyone signed in and the add form is drawn only for a holder of
 * that row — not to protect anything (§3.12 rule 1 and `SEC-09`: the API
 * refuses regardless) but so that nobody is offered a control whose every use
 * would come back 403.
 *
 * ── No editing and no deleting, and that is the endpoint's shape ───────────
 *
 * There is no `PATCH` and no `DELETE` behind this screen. `DB-01` forbids
 * physical deletion, and withdrawing a sector customers are already filed under
 * is a decision with consequences that the API deliberately does not offer.
 * Renaming a label is owed and named in `CHECKLIST.md`.
 *
 * ── `position` is the one number here ──────────────────────────────────────
 *
 * It is a sort key rather than an amount, so `DB-07` has nothing to say about
 * it and it travels as a real number. `AddListEntryRequest` requires an integer
 * of at least 1 — the database's CHECK allows 0, and the boundary is the
 * stricter of the two on purpose — so the control is bound as text and parsed
 * once, at the moment of sending, rather than letting a browser hand back
 * `1.5`.
 */
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import type { Pagination } from '@/api';
import EmptyState from '@/components/states/EmptyState.vue';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import { useAuth } from '@/stores/auth';
import { MANAGED_LISTS, addListEntry, listEntries, type ListEntry, type ManagedListName } from '@/services/admin';

const { t } = useI18n();
const auth = useAuth();

/** §3.11's *system settings* row — what `POST /managed-lists/{list}` names. */
const canEdit = computed(() => auth.hasPermission('admin.system_settings'));

/** §4.2's sectors open the screen: the list §8 needs on the most screens. */
const active = ref<ManagedListName>('sectors');

const entries = ref<ListEntry[]>([]);
const pagination = ref<Pagination | null>(null);
const page = ref(1);
const loading = ref(true);
/** A lang-file key, or null. */
const loadError = ref<string | null>(null);

const form = ref({ code: '', label_en: '', label_ar: '', position: '' });
/** The server's own sentences, per field. Never composed here. */
const formErrors = ref<Record<string, string | null>>({ code: null, label_en: null, label_ar: null, position: null });
const adding = ref(false);
const added = ref(false);
const addError = ref<string | null>(null);

async function load(): Promise<void> {
    loading.value = true;
    loadError.value = null;

    try {
        const result = await listEntries(active.value, page.value);

        entries.value = result.items;
        pagination.value = result.pagination;
    } catch {
        // No 403 branch: the read carries no permission, so a refusal here is
        // an expired session — which `api.ts` already answers — or a fault.
        loadError.value = 'lists.error.load';
    } finally {
        loading.value = false;
    }
}

async function choose(list: ManagedListName): Promise<void> {
    if (list === active.value) {
        return;
    }

    active.value = list;
    // Page 1, always. Keeping the page number across a switch lands on an empty
    // page whenever the new list is shorter than the old one.
    page.value = 1;
    resetForm();
    await load();
}

async function goToPage(next: number): Promise<void> {
    page.value = next;
    await load();
}

function resetForm(): void {
    form.value = { code: '', label_en: '', label_ar: '', position: '' };
    formErrors.value = { code: null, label_en: null, label_ar: null, position: null };
    added.value = false;
    addError.value = null;
}

async function add(): Promise<void> {
    added.value = false;
    addError.value = null;
    formErrors.value = { code: null, label_en: null, label_ar: null, position: null };
    adding.value = true;

    try {
        // Parsed here and nowhere else. An empty box becomes `NaN`, which the
        // boundary refuses as "not an integer" — the same answer it gives for
        // `abc`, and a better one than sending `0` on the person's behalf.
        await addListEntry(active.value, {
            code: form.value.code,
            label_en: form.value.label_en,
            label_ar: form.value.label_ar,
            position: Number.parseInt(form.value.position, 10),
        });

        resetForm();
        added.value = true;
        await load();
    } catch (error) {
        if (error instanceof ApiError) {
            let named = false;

            for (const field of ['code', 'label_en', 'label_ar', 'position']) {
                const message = error.messageFor(field);

                formErrors.value[field] = message;
                named = named || message !== null;
            }

            // §6.6: a refusal that named no field still has to be visible.
            addError.value = named ? null : 'lists.error.add';
        } else {
            addError.value = 'lists.error.add';
        }
    } finally {
        adding.value = false;
    }
}

onMounted(load);
</script>

<template>
    <section class="flex w-full flex-col gap-6">
        <div class="flex flex-col gap-1">
            <h1 class="text-page-title" data-testid="lists-heading">{{ t('lists.title') }}</h1>
            <p class="text-[var(--color-text-muted)] text-pretty">{{ t('lists.subtitle') }}</p>
        </div>

        <!-- The four lists, as buttons rather than a select: the set is closed
             by `ManagedList` and four options are quicker to reach than a
             drop-down. Not a field, so no hint under it. -->
        <nav class="flex flex-wrap gap-2" :aria-label="t('lists.chooser')">
            <button
                v-for="list in MANAGED_LISTS"
                :key="list"
                type="button"
                class="chip min-h-11 rounded-lg border px-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)]"
                :class="list === active
                    ? 'chip-selected border-[var(--color-primary)] bg-[var(--color-primary)] text-[var(--color-primary-text)]'
                    : 'chip-idle border-[var(--color-border-strong)] text-[var(--color-text)]'"
                :aria-pressed="list === active"
                :data-list-name="list"
                @click="choose(list)"
            >
                {{ t(`lists.name.${list}`) }}
            </button>
        </nav>

        <!-- `SEC-09`: hiding this is presentation. The API refuses a write from
             anyone without `admin.system_settings` whether it is drawn or not. -->
        <form
            v-if="canEdit"
            class="flex flex-wrap items-start gap-4"
            data-testid="lists-add"
            @submit.prevent="add"
        >
            <label class="flex flex-col gap-1.5">
                <span class="text-form-label text-[var(--color-text)]">{{ t('lists.field.code') }}</span>
                <input
                    v-model="form.code"
                    type="text"
                    maxlength="64"
                    autocapitalize="none"
                    class="field min-h-11 w-48 rounded-lg border px-3 text-[var(--color-text)]"
                    :class="formErrors.code === null
                        ? 'border-[var(--color-border-strong)]'
                        : 'border-[var(--color-danger)]'"
                    :aria-invalid="formErrors.code !== null"
                    :aria-describedby="formErrors.code === null ? 'hint-list-code' : 'hint-list-code error-list-code'"
                    data-testid="lists-code"
                >
                <span
                    id="hint-list-code"
                    class="text-table text-[var(--color-text-muted)] text-pretty"
                    data-testid="list-hint"
                >{{ t('lists.hint.code') }}</span>
                <span
                    v-if="formErrors.code !== null"
                    id="error-list-code"
                    class="text-table text-[var(--color-danger)]"
                >{{ formErrors.code }}</span>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="text-form-label text-[var(--color-text)]">{{ t('lists.field.label_en') }}</span>
                <input
                    v-model="form.label_en"
                    type="text"
                    maxlength="128"
                    lang="en"
                    dir="ltr"
                    class="field min-h-11 w-48 rounded-lg border px-3 text-start text-[var(--color-text)]"
                    :class="formErrors.label_en === null
                        ? 'border-[var(--color-border-strong)]'
                        : 'border-[var(--color-danger)]'"
                    :aria-invalid="formErrors.label_en !== null"
                    :aria-describedby="formErrors.label_en === null ? 'hint-list-label-en' : 'hint-list-label-en error-list-label-en'"
                    data-testid="lists-label-en"
                >
                <span
                    id="hint-list-label-en"
                    class="text-table text-[var(--color-text-muted)] text-pretty"
                    data-testid="list-hint"
                >{{ t('lists.hint.label_en') }}</span>
                <span
                    v-if="formErrors.label_en !== null"
                    id="error-list-label-en"
                    class="text-table text-[var(--color-danger)]"
                >{{ formErrors.label_en }}</span>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="text-form-label text-[var(--color-text)]">{{ t('lists.field.label_ar') }}</span>
                <!-- `dir` is stated on both label boxes rather than inherited:
                     §14.2 has an Arabic word typed on an English page and an
                     English word typed on an Arabic one, and the box has to
                     read the way its own content does. -->
                <input
                    v-model="form.label_ar"
                    type="text"
                    maxlength="128"
                    lang="ar"
                    dir="rtl"
                    class="field min-h-11 w-48 rounded-lg border px-3 text-start text-[var(--color-text)]"
                    :class="formErrors.label_ar === null
                        ? 'border-[var(--color-border-strong)]'
                        : 'border-[var(--color-danger)]'"
                    :aria-invalid="formErrors.label_ar !== null"
                    :aria-describedby="formErrors.label_ar === null ? 'hint-list-label-ar' : 'hint-list-label-ar error-list-label-ar'"
                    data-testid="lists-label-ar"
                >
                <span
                    id="hint-list-label-ar"
                    class="text-table text-[var(--color-text-muted)] text-pretty"
                    data-testid="list-hint"
                >{{ t('lists.hint.label_ar') }}</span>
                <span
                    v-if="formErrors.label_ar !== null"
                    id="error-list-label-ar"
                    class="text-table text-[var(--color-danger)]"
                >{{ formErrors.label_ar }}</span>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="text-form-label text-[var(--color-text)]">{{ t('lists.field.position') }}</span>
                <input
                    v-model="form.position"
                    type="text"
                    inputmode="numeric"
                    class="field min-h-11 w-24 rounded-lg border px-3 tabular-nums text-[var(--color-text)]"
                    :class="formErrors.position === null
                        ? 'border-[var(--color-border-strong)]'
                        : 'border-[var(--color-danger)]'"
                    :aria-invalid="formErrors.position !== null"
                    :aria-describedby="formErrors.position === null ? 'hint-list-position' : 'hint-list-position error-list-position'"
                    data-testid="lists-position"
                >
                <span
                    id="hint-list-position"
                    class="text-table text-[var(--color-text-muted)] text-pretty"
                    data-testid="list-hint"
                >{{ t('lists.hint.position') }}</span>
                <span
                    v-if="formErrors.position !== null"
                    id="error-list-position"
                    class="text-table text-[var(--color-danger)]"
                >{{ formErrors.position }}</span>
            </label>

            <!-- `w-full` puts the action on its own line **below** the four
                 fields rather than beside the last of them, which is where a
                 `flex-wrap` row had been leaving it. §6.3 reads a form top to
                 bottom, and the submit is the end of that reading, not a fifth
                 column of it. The two messages under it already break the line
                 this same way. The button keeps its own width inside. -->
            <div class="w-full">
                <button
                    type="submit"
                    class="primary-action min-h-11 rounded-lg px-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="adding"
                    data-testid="lists-submit"
                >
                    {{ adding ? t('lists.add.submitting') : t('lists.add.submit') }}
                </button>
            </div>

            <p
                v-if="added"
                class="w-full text-[var(--color-success)]"
                role="status"
                data-testid="lists-added"
            >{{ t('lists.add.added') }}</p>

            <p v-if="addError !== null" class="w-full text-[var(--color-danger)]" role="alert">
                {{ t(addError) }}
            </p>
        </form>

        <LoadingState v-if="loading" label-key="lists.loading" />

        <ErrorState v-else-if="loadError !== null" :message-key="loadError" @retry="load" />

        <EmptyState
            v-else-if="entries.length === 0"
            title-key="lists.empty.title"
            message-key="lists.empty.message"
        />

        <div v-else class="table-frame overflow-x-auto rounded-xl">
            <table class="w-full text-table" data-testid="lists-table">
                <thead>
                    <tr class="table-head">
                        <th scope="col" class="p-3 text-start">{{ t('lists.column.code') }}</th>
                        <th scope="col" class="p-3 text-start">{{ t('lists.column.label_en') }}</th>
                        <th scope="col" class="p-3 text-start">{{ t('lists.column.label_ar') }}</th>
                        <th scope="col" class="p-3 text-start">{{ t('lists.column.position') }}</th>
                    </tr>
                </thead>

                <tbody>
                    <tr v-for="entry in entries" :key="entry.code" class="table-row" data-testid="lists-row">
                        <td class="p-3">{{ entry.code }}</td>
                        <td class="p-3" lang="en" dir="ltr">{{ entry.label_en }}</td>
                        <td class="p-3" lang="ar" dir="rtl">{{ entry.label_ar }}</td>
                        <td class="p-3 tabular-nums">{{ entry.position }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="pagination !== null && pagination.total_pages > 1"
            class="flex items-center justify-between gap-3"
            :aria-label="t('lists.pagination.label')"
            data-testid="lists-pagination"
        >
            <button
                type="button"
                class="chip min-h-11 rounded-lg px-3 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="!pagination.has_previous_page || loading"
                data-testid="lists-previous"
                @click="goToPage(pagination.page - 1)"
            >
                {{ t('lists.pagination.previous') }}
            </button>

            <p class="tabular-nums text-[var(--color-text-muted)]">
                {{ t('lists.pagination.position', {
                    page: pagination.page,
                    pages: pagination.total_pages,
                    total: pagination.total,
                }) }}
            </p>

            <button
                type="button"
                class="chip min-h-11 rounded-lg px-3 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="!pagination.has_next_page || loading"
                data-testid="lists-next"
                @click="goToPage(pagination.page + 1)"
            >
                {{ t('lists.pagination.next') }}
            </button>
        </nav>
    </section>
</template>

<style scoped>
.field {
    background-color: var(--color-surface);
}

.chip,
.primary-action {
    touch-action: manipulation;
    transition-property: background-color, color, border-color;
    transition-duration: 160ms;
    transition-timing-function: ease-out;
}

.primary-action {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
}

/* §6.2: "All button variants have default, hover, active, focus, disabled, and
   loading states." The transition above was already declared for these three
   properties and had nothing to transition to. `:not(:disabled)` so the button
   stops answering the pointer once it is submitting — `LoginView`'s shape. */
.primary-action:hover:not(:disabled) {
    background-color: var(--color-primary-hover);
}

.primary-action:active:not(:disabled) {
    background-color: var(--color-primary-active);
}

/* The unselected chip has no fill at rest, so its hover has to supply one:
   §6.2 again, and a chooser whose options do not answer the pointer reads as
   disabled. The selected chip darkens instead, through the same token pair as
   the button, so "more prominent than rest" holds in both states. */
.chip-idle:hover {
    background-color: var(--color-surface-muted);
    border-color: var(--color-primary);
}

.chip-selected:hover {
    background-color: var(--color-primary-hover);
    border-color: var(--color-primary-hover);
}

.chip-selected:active,
.chip-idle:active {
    background-color: var(--color-primary-active);
    border-color: var(--color-primary-active);
    color: var(--color-primary-text);
}

@media (prefers-reduced-motion: reduce) {
    .chip,
    .primary-action {
        transition: none;
    }
}
</style>
