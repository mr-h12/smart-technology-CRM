<script setup lang="ts">
/**
 * Flow 3's decision — the `pending` badge, and Approve / Reject (Module 5,
 * Point 6.4).
 *
 * ── One permission for both directions, because §3.4 seeds one ─────────────
 *
 * There is no `deal.reject` row: `deal.approve` carries both routes, on
 * `customer.archive`'s precedent for archive/restore. So one computed draws
 * both buttons, and a role that may approve may reject.
 *
 * ⚠️ §3.4 grants it to the **Manager** (`All`) and the **Team Leader**
 * (`Team`) only — and `Team` resolves to no rows at all (Point 2.1). So the
 * Team Leader, the very role the acceptance criterion names, can hold this
 * permission, see the buttons, and reach no deal to press them on. That is
 * backend debt, not a defect here, and the module's test list says so.
 *
 * ── The badge is a state, not a colour ─────────────────────────────────────
 *
 * §6.4: pending is a **warning**, never colour alone — and rejected is
 * **danger** with its "mandatory reason/action clear". Each badge is the shared
 * status chip (A-55): the word carries the meaning in its tone, with no emoji,
 * and the reason is rendered beside the rejected one rather than hidden behind it.
 *
 * ── Null is not pending ────────────────────────────────────────────────────
 *
 * Flow 1 leaves `approval_status` null: a deal entered by a Manager or Team
 * Leader was never submitted for approval, which is a different fact from
 * "waiting for one". No badge is drawn at all in that case — a "pending" chip
 * would invent a queue nobody is in.
 *
 * ── Re-deciding is a 409, and the screen says which ────────────────────────
 *
 * `ReviewDealApproval` refuses a second decision and a decision on a
 * never-submitted deal with `409 state_transition_invalid`. The dialog surfaces
 * that inline rather than swallowing it: the row in hand is stale, and the
 * honest instruction is to reload.
 */
import { computed, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import ConfirmDialog from '@/components/users/ConfirmDialog.vue';
import { approveDeal, rejectDeal, type Deal } from '@/services/deals';
import { useAuth } from '@/stores/auth';

const props = defineProps<{ deal: Deal }>();
const emit = defineEmits<{ decided: [Deal] }>();

const { t } = useI18n();
const auth = useAuth();

const rejecting = ref(false);
/** A-09: Approve names the deal and asks before anything is written. */
const confirming = ref(false);
/** A-29: one control per row, so the reason's id is its own. */
const reasonId = useId();
const reason = ref('');
const busy = ref(false);
const errorKey = ref<string | null>(null);
const reasonError = ref<string | null>(null);

/** §3.4 seeds one row for both directions — there is no `deal.reject`. */
const canDecide = computed(() => auth.hasPermission('deal.approve'));

/** Only a `pending` deal can be decided; null was never submitted. */
const isPending = computed(() => props.deal.approval_status === 'pending');
const isRejected = computed(() => props.deal.approval_status === 'rejected');
const isApproved = computed(() => props.deal.approval_status === 'approved');

function refusalKey(error: unknown): string {
    if (!(error instanceof ApiError)) {
        return 'deals.approval.unreachable';
    }

    if (error.status === 403) {
        return 'deals.approval.forbidden';
    }

    // The 409 this module's own exception raises: the row in hand is stale.
    return error.status === 409 ? 'deals.approval.alreadyDecided' : 'deals.approval.rejectedByServer';
}

async function approve(): Promise<void> {
    errorKey.value = null;
    busy.value = true;

    try {
        emit('decided', await approveDeal(props.deal.id));
    } catch (error) {
        errorKey.value = refusalKey(error);
    } finally {
        busy.value = false;
        confirming.value = false;
    }
}

async function submitRejection(): Promise<void> {
    errorKey.value = null;
    reasonError.value = null;

    // §6.6: a rejection "collects a mandatory reason **before** submission".
    // `RejectDealRequest` is `required` + `regex:/\S/`, so whitespace is not a
    // reason — the same check, made where the person can still fix it.
    if (reason.value.trim() === '') {
        reasonError.value = t('deals.approval.reasonRequired');

        return;
    }

    busy.value = true;

    try {
        const decided = await rejectDeal(props.deal.id, reason.value);

        rejecting.value = false;
        reason.value = '';
        emit('decided', decided);
    } catch (error) {
        const sentence = error instanceof ApiError ? error.messageFor('reason') : null;

        if (sentence !== null) {
            reasonError.value = sentence;
        } else {
            errorKey.value = refusalKey(error);
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="flex flex-col gap-2" data-testid="deal-approval">
        <!-- §6.4: the word, never colour alone; A-55: the shared chip, no emoji. -->
        <p v-if="isPending" class="status-chip status-chip--warning w-fit whitespace-nowrap rounded-full px-2 py-0.5" data-testid="deal-approval-badge-pending">{{ t('deals.approval.pending') }}</p>
        <p v-else-if="isRejected" class="status-chip status-chip--danger w-fit whitespace-nowrap rounded-full px-2 py-0.5" data-testid="deal-approval-badge-rejected">{{ t('deals.approval.rejected') }}</p>
        <p v-else-if="isApproved" class="status-chip status-chip--success w-fit whitespace-nowrap rounded-full px-2 py-0.5" data-testid="deal-approval-badge-approved">{{ t('deals.approval.approved') }}</p>

        <!-- The reason is shown to whoever can see the deal — §4.3 makes it
             mandatory on rejection precisely so the employee can read it. -->
        <p
            v-if="isRejected && deal.rejection_reason !== null"
            class="text-[var(--color-danger)]"
            data-testid="deal-approval-reason"
        >
            {{ t('deals.approval.reasonLabel', { reason: deal.rejection_reason }) }}
        </p>

        <p v-if="errorKey !== null" class="form-alert rounded-lg p-3" role="alert" data-testid="deal-approval-error">
            {{ t(errorKey) }}
        </p>

        <div v-if="canDecide && isPending && !rejecting" class="flex flex-wrap gap-2">
            <button
                type="button"
                class="create-action min-h-11 rounded-lg px-4 disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="busy"
                data-testid="deal-approve"
                @click="confirming = true"
            >
                {{ t('deals.approval.approveAction') }}
            </button>
            <!-- A-33: Reject is row Danger, text only; the filled Danger is its submit. -->
            <button
                type="button"
                class="danger-text min-h-11 rounded-lg px-4"
                :disabled="busy"
                data-testid="deal-reject"
                @click="rejecting = true"
            >
                {{ t('deals.approval.rejectAction') }}
            </button>
        </div>

        <!-- §6.6: "collect mandatory reasons before submission". -->
        <form v-if="rejecting" class="flex flex-col gap-2" data-testid="deal-reject-form" @submit.prevent="submitRejection()">
            <label class="flex flex-col gap-1.5" :for="reasonId">
                <span>
                    {{ t('deals.approval.reasonPrompt') }}
                    <span class="text-[var(--color-danger)]">{{ t('form.required') }}</span>
                </span>
                <textarea
                    :id="reasonId"
                    v-model="reason"
                    rows="3"
                    :disabled="busy"
                    :aria-invalid="reasonError !== null"
                    class="form-field rounded-lg px-3 py-2"
                    data-testid="deal-reject-reason"
                ></textarea>
                <span v-if="reasonError !== null" class="text-[var(--color-danger)]" data-testid="deal-reject-reason-error">
                    {{ reasonError }}
                </span>
            </label>

            <div class="flex flex-wrap gap-2">
                <button
                    type="submit"
                    class="danger-action min-h-11 rounded-lg px-4 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="busy"
                    data-testid="deal-reject-submit"
                >
                    {{ t('deals.approval.rejectAction') }}
                </button>
                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-4"
                    :disabled="busy"
                    data-testid="deal-reject-cancel"
                    @click="rejecting = false"
                >
                    {{ t('action.cancel') }}
                </button>
            </div>
        </form>

        <ConfirmDialog
            :open="confirming"
            title-key="deals.approval.confirm.title"
            message-key="deals.approval.confirm.message"
            confirm-key="deals.approval.approveAction"
            :subject="deal.code"
            :busy="busy"
            @confirm="approve"
            @cancel="confirming = false"
        />
    </div>
</template>

<style scoped>
.form-field {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.form-alert {
    background-color: var(--color-surface-muted);
    border: 1px solid var(--color-border-strong);
}
</style>
