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
 */
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@/api';
import { DEAL_STATUSES, changeDealStatus, type Deal } from '@/services/deals';
import { useAuth } from '@/stores/auth';

const props = defineProps<{ deal: Deal }>();
const emit = defineEmits<{ changed: [Deal] }>();

const { t } = useI18n();
const auth = useAuth();

const open = ref(false);
const target = ref('');
const reason = ref('');
const busy = ref(false);
const errorKey = ref<string | null>(null);
const reasonError = ref<string | null>(null);

const statuses = DEAL_STATUSES;

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

function start(): void {
    open.value = true;
    target.value = '';
    reason.value = '';
    errorKey.value = null;
    reasonError.value = null;
}

function cancel(): void {
    open.value = false;
}

async function submit(): Promise<void> {
    errorKey.value = null;
    reasonError.value = null;

    if (target.value === '') {
        errorKey.value = 'deals.status.targetRequired';

        return;
    }

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

        open.value = false;
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
    <div class="flex flex-col gap-2" data-testid="deal-status-control">
        <button
            v-if="canChange && !open"
            type="button"
            class="row-action min-h-11 rounded-lg px-3 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
            data-testid="deal-status-start"
            @click="start()"
        >
            {{ t('deals.status.changeAction') }}
        </button>

        <form v-if="open" class="flex flex-col gap-2" data-testid="deal-status-form" @submit.prevent="submit()">
            <label class="flex flex-col gap-1.5" for="deal-status-target">
                <span>{{ t('deals.status.targetPrompt') }}</span>
                <select
                    id="deal-status-target"
                    v-model="target"
                    :disabled="busy"
                    class="form-field min-h-11 rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="deal-status-target"
                >
                    <option value="">{{ t('deals.status.targetNone') }}</option>
                    <!-- The whole vocabulary, not a reachable set: §7.1 leaves
                         the allowed transition to the server. -->
                    <option v-for="code in statuses" :key="code" :value="code">{{ t(`deals.status.${code}`) }}</option>
                </select>
            </label>

            <label v-if="needsReason" class="flex flex-col gap-1.5" for="deal-status-reason">
                <span>
                    {{ t('deals.status.reasonPrompt') }}
                    <span class="text-[var(--color-danger)]">{{ t('deals.form.required') }}</span>
                </span>
                <textarea
                    id="deal-status-reason"
                    v-model="reason"
                    rows="3"
                    :disabled="busy"
                    :aria-invalid="reasonError !== null"
                    class="form-field rounded-lg px-3 py-2 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    data-testid="deal-status-reason"
                ></textarea>
                <span v-if="reasonError !== null" class="text-[var(--color-danger)]" data-testid="deal-status-reason-error">
                    {{ reasonError }}
                </span>
            </label>

            <p v-if="errorKey !== null" class="form-alert rounded-lg p-3" role="alert" data-testid="deal-status-error">
                {{ t(errorKey) }}
            </p>

            <div class="flex flex-wrap gap-2">
                <button
                    type="submit"
                    class="create-action min-h-11 rounded-lg px-4 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)] disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="busy"
                    data-testid="deal-status-submit"
                >
                    {{ t('deals.status.changeAction') }}
                </button>
                <button
                    type="button"
                    class="row-action min-h-11 rounded-lg px-4 focus:outline-2 focus:outline-offset-2 focus:outline-[var(--color-focus-ring)]"
                    :disabled="busy"
                    data-testid="deal-status-cancel"
                    @click="cancel()"
                >
                    {{ t('action.cancel') }}
                </button>
            </div>
        </form>
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

.row-action {
    background-color: var(--color-surface);
    border: 1px solid var(--color-border-strong);
    color: var(--color-text);
}

.create-action {
    background-color: var(--color-primary);
    color: var(--color-primary-text);
}
</style>
