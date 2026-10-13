<script setup lang="ts">
/**
 * §4.4's transition, at the screen (Module 5, Point 6.5).
 *
 * ── The SPA does not own the transition graph ──────────────────────────────
 *
 * `D-67` keeps every business rule on the server, and Design System §7.1 says
 * it twice for this exact control: "The server decides authorization and
 * allowed transition." `DealStatusTransition` is built from §4.4's table and
 * lives in `Deals\Domain`; transcribing it into TypeScript would put the same
 * rule in two places, and the copy would be the one that rots — §4.4 gains a
 * status and the screen keeps offering the old graph.
 *
 * So this control offers **the vocabulary**, not the reachable set, and renders
 * the server's `409 state_transition_invalid` inline when an edge is refused.
 * That is a worse experience than a narrowed list and it is the honest one
 * available today; publishing `allowed_transitions` on `DealPayload` is the
 * better answer and is on the debt register as a backend point.
 *
 * ── `lost` is the one status that carries a reason ─────────────────────────
 *
 * `ChangeDealStatusRequest` makes `reason` **required** when the target is
 * `lost` and **prohibited** on every other status — so the field appears only
 * for `lost`, and the client never sends an empty one, which would 422
 * everywhere else. §4.4's own table says "Lost | Sales (mandatory reason)".
 *
 * ── Two permissions, and only the server can tell which applies ────────────
 *
 * `deal.change_status` carries the route, but `Delivery → Delivery Complete`
 * additionally needs `deal.mark_delivery_complete` (`D-14`'s four roles, a
 * genuinely different set — the Outdoor Supervisor holds one and not the
 * other). Which one applies depends on the **target status**, which is in the
 * body, so `ChangeDealStatus` checks it inside the use case. This control draws
 * on `change_status` alone and lets the server refuse the one edge it must:
 * hiding `delivery_complete` from a role that might hold the second grant would
 * be the SPA guessing at an authorization decision.
 *
 * ── One pick, then the confirm (F-42 · P7, owner 2026-10-14) ───────────────
 *
 * The list is the control: picking a status opens F-42's `ConfirmDialog`, and
 * only its confirm calls the server. A status change is audited and §4.4 may
 * refuse the way back, so the pick asks first. `lost`'s reason rides in the
 * dialog's slot, and a refusal is drawn there too, so the person can read it
 * before cancelling. The deal's own status is greyed out; every other one is
 * still offered and the server still decides the edge.
 */
import { computed, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import ConfirmDialog from '@/components/users/ConfirmDialog.vue';
import { DEAL_STATUSES, changeDealStatus, type Deal } from '@/services/deals';
import { useAuth } from '@/stores/auth';

const props = defineProps<{ deal: Deal }>();
const emit = defineEmits<{ changed: [Deal] }>();

const { t } = useI18n();
const auth = useAuth();

const target = ref('');
const reason = ref('');
const busy = ref(false);
const errorKey = ref<string | null>(null);
const reasonError = ref<string | null>(null);

const statuses = DEAL_STATUSES;

/** A-29: one control per row, so its ids are its own (`SearchCombobox`'s `useId`). */
const reasonId = useId();

const canChange = computed(() => auth.hasPermission('deal.change_status'));

/** §4.4: only `lost` carries a reason, and it is mandatory. */
const needsReason = computed(() => target.value === 'lost');

function refusalKey(error: unknown): string {
    if (!(error instanceof ApiError)) {
        return 'deals.status.unreachable';
    }

    if (error.status === 403) {
        return 'deals.status.forbidden';
    }

    // The server's own graph refused this edge — §4.4 does not draw it.
    return error.status === 409 ? 'deals.status.notAllowed' : 'deals.status.rejectedByServer';
}

/** Cancel, Escape and a success all put the list back on its prompt; the dialog hands focus back to it. */
function reset(): void {
    target.value = '';
    reason.value = '';
    errorKey.value = null;
    reasonError.value = null;
}

async function submit(): Promise<void> {
    errorKey.value = null;
    reasonError.value = null;

    // The same `regex:/\S/` the server applies, made where it can still be fixed.
    if (needsReason.value && reason.value.trim() === '') {
        reasonError.value = t('deals.status.reasonRequired');

        return;
    }

    busy.value = true;

    try {
        // ⚠️ The reason is passed unconditionally, and `changeDealStatus`
        // applies §4.4's rule — `reason` only with `lost`, because the server
        // prohibits it elsewhere. A ternary here was written first and a probe
        // found it **untestable**: the service's own guard already stripped the
        // field, so removing the component's copy reddened nothing. Two places
        // enforcing one rule, and only the service's is provable — so the
        // component keeps none of it, and `deals.spec.ts` pins the rule where
        // it actually lives.
        const changed = await changeDealStatus(props.deal.id, target.value, reason.value);

        reset();
        emit('changed', changed);
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
    <div v-if="canChange" data-testid="deal-status-control">
        <select
            v-model="target"
            :disabled="busy"
            :aria-label="t('deals.status.changeAction')"
            class="form-field min-h-11 rounded-lg px-3 py-2"
            data-testid="deal-status-target"
        >
            <option value="" disabled>{{ t('deals.status.changeAction') }}</option>
            <!-- The whole vocabulary, not a reachable set: §7.1 leaves
                 the allowed transition to the server. -->
            <option v-for="code in statuses" :key="code" :value="code" :disabled="code === deal.status">
                {{ t(`deals.status.${code}`) }}
            </option>
        </select>

        <ConfirmDialog
            :open="target !== ''"
            title-key="deals.status.changeAction"
            message-key="deals.status.confirmMessage"
            confirm-key="deals.status.changeAction"
            :subject="target === '' ? '' : t(`deals.status.${target}`)"
            :busy="busy"
            @confirm="submit()"
            @cancel="reset()"
        >
            <label v-if="needsReason" class="flex flex-col gap-1.5" :for="reasonId">
                <span>
                    {{ t('deals.status.reasonPrompt') }}
                    <span class="text-[var(--color-danger)]">{{ t('form.required') }}</span>
                </span>
                <textarea
                    :id="reasonId"
                    v-model="reason"
                    rows="3"
                    autofocus
                    :disabled="busy"
                    :aria-invalid="reasonError !== null"
                    class="form-field rounded-lg px-3 py-2"
                    data-testid="deal-status-reason"
                ></textarea>
                <span v-if="reasonError !== null" class="text-[var(--color-danger)]" data-testid="deal-status-reason-error">
                    {{ reasonError }}
                </span>
            </label>

            <p v-if="errorKey !== null" class="form-alert rounded-lg p-3" role="alert" data-testid="deal-status-error">
                {{ t(errorKey) }}
            </p>
        </ConfirmDialog>
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
