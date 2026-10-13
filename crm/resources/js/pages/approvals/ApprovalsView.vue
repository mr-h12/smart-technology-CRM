<script setup lang="ts">
/**
 * `/approvals` — Module 8 Point 3.1. §8's *Approvals* screen: one page for the
 * Team Leader and the Manager (`D-10`, the same authority), listing every
 * `pending` quotation within the caller's `quotation.approve` reach, grouped
 * by employee — the server's `group_by=employee` label already carries the
 * name (Step 6 Q2), so no row needs a field the API does not have.
 *
 * `D-11`: no automatic escalation. A row past the SLA is still `pending` and
 * still here; it is marked by `sla_exceeded` in **words** (Design System §6.4
 * "never colour alone"), and `days_waiting` is the server's number, never
 * computed here (2.1).
 *
 * Approve asks first in F-42's confirm (A-09), and Return asks its note in a
 * modal dialog (A-22, the owner's Option B). The list row carries no etag, so an action
 * reads the detail first and acts on that token; a `409` on it is 6.5's
 * reload banner (`API-12`) — somebody else wrote first, and their version is
 * read, never overwritten by a retry. Neither is a native confirm() or
 * prompt() (Design System §6.6).
 */
import { nextTick, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import { ApiError } from '@/api';
import EmptyState from '@/components/states/EmptyState.vue';
import ConfirmDialog from '@/components/users/ConfirmDialog.vue';
import { useModalDialog } from '@/components/useModalDialog';
import ErrorState from '@/components/states/ErrorState.vue';
import LoadingState from '@/components/states/LoadingState.vue';
import PermissionDeniedState from '@/components/states/PermissionDeniedState.vue';
import { readCustomer } from '@/services/customers';
import {
    approveQuotation,
    listQuotationGroups,
    readQuotation,
    returnQuotation,
    type QuotationGroup,
    type QuotationSummary,
} from '@/services/quotations';
import { displayDecimals } from '@/domain/displayDecimals';

const { t } = useI18n();

const groups = ref<QuotationGroup[]>([]);
/** Names by id, one read per distinct customer: an approver's page is a handful of rows, not 6.3's 100. */
const customers = ref<Record<string, string>>({});
const loading = ref(true);
const failed = ref(false);
const denied = ref(false);

const busy = ref(false);
const conflict = ref(false);
const actionError = ref('');
/** A-32: the last success, named — the approved row itself leaves the list. */
const statusLine = ref('');
/** The row whose Approve confirm is open, if any (A-09). */
const approving = ref<QuotationSummary | null>(null);
/** The row whose Return note is open, if any. */
const returning = ref<QuotationSummary | null>(null);
const note = ref('');
const noteField = ref<HTMLTextAreaElement | null>(null);
const returnDialog = ref<HTMLDialogElement | null>(null);

useModalDialog(returnDialog);

async function load(): Promise<void> {
    loading.value = true;
    failed.value = false;
    denied.value = false;

    try {
        // ponytail: the first page of employees, as 6.4 reads it; a second
        // page of pending groups is a company with more approvers than this
        // screen was drawn for.
        const result = await listQuotationGroups('employee', { status: 'pending' });
        groups.value = result.groups;
        await loadCustomers();
    } catch (error) {
        // A 403 and a 500 are different answers and get different screens
        // (`SEC-09`): a refusal drawn as an empty list would read as "nothing
        // to approve", which is a lie.
        denied.value = error instanceof ApiError && error.status === 403;
        failed.value = !denied.value;
    } finally {
        loading.value = false;
    }
}

async function loadCustomers(): Promise<void> {
    const ids = new Set(groups.value.flatMap((group) => group.items.map((row) => row.customer_id)));

    await Promise.all([...ids].filter((id) => !(id in customers.value)).map(async (id) => {
        try {
            customers.value[id] = (await readCustomer(id)).name;
        } catch {
            // The id is still shown; the name is a convenience.
        }
    }));
}

function customerName(id: string): string {
    return customers.value[id] ?? id;
}

/**
 * One path for every write, 6.5's: read the detail for the token, act on it,
 * then read the list again — the row is gone or it is not, and only the
 * server knows which.
 */
async function act(id: string, write: (etag: string) => Promise<unknown>, done: string): Promise<void> {
    busy.value = true;
    conflict.value = false;
    actionError.value = '';
    statusLine.value = '';

    try {
        const { quotation } = await readQuotation(id);
        await write(quotation.etag);
        note.value = '';
        statusLine.value = done;
        await load();
    } catch (error) {
        if (error instanceof ApiError && error.status === 409 && error.code === 'concurrency_conflict') {
            conflict.value = true;
        } else {
            // A-43: the server's own sentence, or the cause in words — never a raw "Failed to fetch".
            actionError.value = error instanceof ApiError ? error.message : t('state.error.network');
        }
    } finally {
        busy.value = false;
        // The page's banner says how it ended; a dialog left open would hide it.
        // ponytail: a failed Return drops its note with the dialog; keep it if the owner asks.
        approving.value = null;
        returning.value = null;
    }
}

async function approve(): Promise<void> {
    const row = approving.value;

    if (row !== null) {
        await act(row.id, (etag) => approveQuotation(row.id, etag), t('approvals.approved', { code: row.code }));
    }
}

/** A-22: the dialog opens with its one field focused. */
async function openReturn(row: QuotationSummary): Promise<void> {
    returning.value = row;
    note.value = '';
    actionError.value = '';
    await nextTick();
    noteField.value?.focus();
}

/** A-30: a Return in flight is not walked away from. */
function closeReturn(): void {
    if (!busy.value) {
        returning.value = null;
    }
}

/** 1.2's rule, mirrored for the person and enforced by the server: a note is not blank. */
async function sendReturn(): Promise<void> {
    const row = returning.value;

    if (row === null || note.value.trim() === '') {
        return;
    }

    await act(row.id, (etag) => returnQuotation(row.id, etag, note.value), t('approvals.returned', { code: row.code }));
}

onMounted(load);
</script>

<template>
    <div class="flex flex-col gap-4" data-testid="approvals-view">
        <h1 class="text-page-title">{{ t('approvals.title') }}</h1>

        <!-- A-32: present from load, above every state, so it outlives the row it names. -->
        <p role="status" data-testid="approvals-status">{{ statusLine }}</p>

        <PermissionDeniedState v-if="denied" />
        <LoadingState v-else-if="loading" label-key="approvals.loading" />
        <ErrorState v-else-if="failed" @retry="load" />
        <EmptyState v-else-if="groups.length === 0" title-key="approvals.empty.title" message-key="approvals.empty.message" />

        <template v-else>
            <!-- `API-12`: somebody else wrote first. Read their version; never overwrite it by retrying. -->
            <div
                v-if="conflict"
                class="form-alert flex flex-wrap items-center justify-between gap-3 rounded-lg p-3"
                role="alert"
                data-testid="approvals-conflict"
            >
                <span>{{ t('quotations.detail.conflict') }}</span>
                <button type="button" class="row-action min-h-11 rounded-lg px-3" @click="load">
                    {{ t('quotations.detail.reload') }}
                </button>
            </div>

            <div v-else-if="actionError !== ''" class="form-alert flex flex-wrap items-center justify-between gap-3 rounded-lg p-3" role="alert">
                <span data-testid="approvals-action-error">{{ actionError }}</span>
                <!-- A-43, the owner's Option B: the next step is a button under the sentence. -->
                <button type="button" class="row-action min-h-11 rounded-lg px-3" data-testid="approvals-action-retry" @click="actionError = ''; load()">
                    {{ t('state.retry') }}
                </button>
            </div>

            <!-- A-22 (the owner's Option B): the note is asked in a modal dialog, never by a native prompt() (DS §6.6). -->
            <dialog
                v-if="returning !== null"
                ref="returnDialog"
                class="fixed inset-0 z-50 flex items-center justify-center p-4"
                aria-labelledby="approvals-return-title"
                data-testid="approvals-return-modal"
                @cancel.prevent="closeReturn"
            >
                <div class="dialog-scrim absolute inset-0" @click="closeReturn" />

                <form
                    class="dialog-panel relative flex w-full max-w-md flex-col gap-4 rounded-2xl p-6"
                    data-testid="approvals-return-dialog"
                    @submit.prevent="sendReturn"
                >
                    <h2 id="approvals-return-title" class="text-card-title">{{ t('approvals.return') }} — {{ returning.code }}</h2>
                    <label class="flex flex-col gap-1">
                        <span>{{ t('approvals.returnNote') }}</span>
                        <textarea
                            ref="noteField"
                            v-model="note"
                            required
                            rows="3"
                            maxlength="2000"
                            class="form-field rounded-lg p-3"
                            data-testid="approvals-return-note"
                        ></textarea>
                    </label>
                    <span class="flex justify-end gap-2">
                        <button type="button" class="row-action min-h-11 rounded-lg px-3" :disabled="busy" data-testid="approvals-return-cancel" @click="closeReturn">
                            {{ t('approvals.returnCancel') }}
                        </button>
                        <button type="submit" class="create-action min-h-11 rounded-lg px-3 disabled:opacity-60" :disabled="busy" data-testid="approvals-return-send">
                            {{ busy ? t('action.saving') : t('approvals.returnSend') }}
                        </button>
                    </span>
                </form>
            </dialog>

            <!-- A-09: Approve names the record and what follows before anything is written. -->
            <ConfirmDialog
                :open="approving !== null"
                title-key="approvals.approveConfirm.title"
                message-key="approvals.approveConfirm.message"
                confirm-key="approvals.approve"
                :subject="approving?.code ?? ''"
                :busy="busy"
                @confirm="approve"
                @cancel="approving = null"
            />

            <div class="table-frame overflow-x-auto rounded-xl">
                <table class="card-table w-full text-table" data-testid="approvals-table">
                    <thead>
                        <tr class="table-head">
                            <th scope="col" class="p-3 text-start">{{ t('approvals.column.code') }}</th>
                            <th scope="col" class="p-3 text-start">{{ t('approvals.column.customer') }}</th>
                            <th scope="col" class="p-3 text-end">{{ t('approvals.column.total') }}</th>
                            <th scope="col" class="p-3 text-end">{{ t('approvals.column.daysWaiting') }}</th>
                            <th scope="col" class="p-3 text-start">{{ t('approvals.column.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="group in groups" :key="group.key ?? ''">
                            <tr class="group-row">
                                <th colspan="5" scope="colgroup" class="p-3 text-start font-medium" data-testid="approvals-group-heading">
                                    {{ group.label }}
                                </th>
                            </tr>
                            <tr v-for="row in group.items" :key="row.id" class="table-row" data-testid="approvals-row">
                                <td :data-label="t('approvals.column.code')" class="whitespace-nowrap p-3 tabular-nums" data-testid="approvals-code">
                                    <RouterLink :to="{ name: 'quotation-detail', params: { id: row.id } }" class="underline-offset-2 hover:underline">
                                        {{ row.code }}
                                    </RouterLink>
                                </td>
                                <td :data-label="t('approvals.column.customer')" class="p-3" data-testid="approvals-customer">{{ customerName(row.customer_id) }}</td>
                                <td :data-label="t('approvals.column.total')" class="whitespace-nowrap p-3 text-end tabular-nums" data-testid="approvals-total"><bdi dir="ltr">{{ displayDecimals(row.final_total) }} {{ row.currency }}</bdi></td>
                                <td :data-label="t('approvals.column.daysWaiting')" class="whitespace-nowrap p-3 text-end tabular-nums">
                                    <span data-testid="approvals-days-waiting">{{ row.days_waiting ?? '—' }}</span>
                                    <!-- `D-11` in words, not only in red. -->
                                    <span v-if="row.sla_exceeded === true" class="sla-chip ms-2 inline-block rounded-full px-2 py-0.5" data-testid="approvals-sla-exceeded">
                                        {{ t('approvals.slaExceeded') }}
                                    </span>
                                </td>
                                <td :data-label="t('approvals.column.actions')" class="whitespace-nowrap p-3">
                                    <span class="flex flex-wrap gap-2">
                                        <RouterLink :to="{ name: 'quotation-edit-and-approve', params: { id: row.id } }" class="row-action inline-flex min-h-11 items-center rounded-lg px-3" data-testid="approvals-edit">
                                            {{ t('approvals.edit') }}
                                        </RouterLink>
                                        <button type="button" class="row-action min-h-11 rounded-lg px-3 disabled:opacity-60" :disabled="busy" data-testid="approvals-approve" @click="approving = row">
                                            {{ t('approvals.approve') }}
                                        </button>
                                        <button type="button" class="row-action min-h-11 rounded-lg px-3 disabled:opacity-60" :disabled="busy" data-testid="approvals-return" @click="openReturn(row)">
                                            {{ t('approvals.return') }}
                                        </button>
                                    </span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>

<style scoped>
/* 6.3's and 6.5's page tokens, repeated here as every page does (a shared sheet is a debt row, not this point). */
.table-frame {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border);
}

.table-head {
    background-color: var(--color-surface-muted);
    color: var(--color-text-muted);
}

.group-row {
    background-color: var(--color-surface-muted);
}

.table-row {
    border-block-start: 1px solid var(--color-border);
}

.table-row:hover,
.table-row:focus-within {
    background-color: var(--color-surface-muted);
}

.form-alert {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-border-strong);
}

.form-field {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.sla-chip {
    color: var(--color-danger);
    background-color: color-mix(in srgb, currentColor 14%, transparent);
}
</style>
