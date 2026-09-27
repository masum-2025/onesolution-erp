<script setup>
import { computed, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { AlertTriangle, BadgeCheck, CircleDashed, FlaskConical, Info, Package, Sparkles } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import CheckoutDialog from './CheckoutDialog.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { daysUntil, newOpId, standingTone, yearlySaving } from '@/lib/billing';
import { confirmAction } from '@/lib/dialogs';
import { currentOrganization, loadMe } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * A personal workspace buys and pays for its own plan (Phase 5C-2): where it
 * stands, what it owes, the plans it can buy, a free trial, and moving to
 * the free plan. The server decides everything; this only offers what the
 * state allows and explains why not.
 */
const emit = defineEmits(['changed']);

const org = currentOrganization();
const base = `/api/organizations/${org.id}/billing`;
const state = useResource(() => api(`${base}/self-serve`).then((response) => response.data));
const data = computed(() => state.data.value);

const period = ref('monthly');
const checkout = ref(null); // { plan }
const busy = ref(null); // 'trial' | 'free' | 'keep' | invoice id

const trialDays = computed(() => daysUntil(data.value?.trial?.ends_at));
const canBuy = computed(() => data.value?.can_manage && data.value.verified && data.value.can_pay_online);
// During a paid period (not a trial) the plan changes only when it ends.
const locked = computed(() => data.value?.status === 'active' && !!data.value.paid_through);
const owes = computed(() => (data.value?.open_invoices ?? []).length > 0);
const hasYearly = computed(() => (data.value?.plans ?? []).some((plan) => plan.prices.yearly !== undefined));

function isCurrent(plan) {
    return plan.key === data.value.plan.key && (plan.key === data.value.free_plan_key || data.value.period === period.value);
}

function priceOf(plan) {
    return plan.prices[period.value] ?? null;
}

async function refresh(response, message) {
    state.data.value = response.data;
    if (message ?? response.message) toast.success(message ?? response.message);
    // Plan and mode changes affect the menu and the read-only banner.
    await loadMe();
    emit('changed');
}

async function act(key, run) {
    busy.value = key;
    try {
        await run();
    } catch (error) {
        toast.error(error.message);
        if (error.code === 'op_reused') state.reload();
    } finally {
        busy.value = null;
    }
}

function startTrial() {
    act('trial', async () => refresh(await api(`${base}/trial`, { method: 'POST' })));
}

async function toFree() {
    const text = owes.value
        ? t('billing.self.to_free_text_invoice')
        : locked.value
          ? t('billing.self.to_free_text_later')
          : t('billing.self.to_free_text_now');
    const confirmed = await confirmAction({
        title: t('billing.self.to_free_title'),
        message: text,
        confirmLabel: t('billing.self.to_free_confirm'),
        danger: !locked.value || owes.value,
    });
    if (!confirmed) return;

    act('free', async () => refresh(await api(`${base}/free`, { method: 'POST', body: { confirm: true } })));
}

function keepPlan() {
    act('keep', async () => refresh(await api(`${base}/keep-plan`, { method: 'POST' })));
}

function payInvoice(invoice) {
    act(invoice.id, async () => {
        const response = await api(`${base}/invoices/${invoice.id}/pay`, { method: 'POST', body: { op_id: newOpId() } });
        if (response.data.checkout_url) window.location.assign(response.data.checkout_url);
        else toast.error(t('billing.payment.failed_text'));
    });
}
</script>

<template>
    <div class="mb-6 space-y-5">
        <SkeletonRows v-if="state.loading.value && !data" :rows="3" />
        <ErrorState v-else-if="state.error.value" :error="state.error.value" @retry="state.reload()" />

        <template v-else-if="data">
            <!-- Where the account stands -->
            <section class="card p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-text">
                            <Package class="size-5" aria-hidden="true" />
                        </div>
                        <div class="min-w-0">
                            <p class="text-[13px] font-medium text-fg-2">{{ t('billing.client.plan') }}</p>
                            <p class="truncate text-[18px] font-semibold text-fg">{{ data.plan.name }}</p>
                        </div>
                    </div>
                    <AppBadge :tone="standingTone(data.status)">{{ t(`billing.self.standing.${data.status}`) }}</AppBadge>
                </div>

                <div class="mt-4 space-y-2 text-[13.5px] leading-relaxed text-fg-2">
                    <p v-if="data.status === 'free'">{{ t('billing.self.free_line') }}</p>
                    <p v-else-if="data.status === 'trial'">
                        {{ t('billing.self.trial_line', { count: trialDays, days: formatNumber(trialDays), date: formatDate(data.trial.ends_at) }) }}
                    </p>
                    <p v-else-if="data.status === 'active' && data.moves_to_free_on">{{ t('billing.self.moves_line', { date: formatDate(data.moves_to_free_on) }) }}</p>
                    <p v-else-if="data.status === 'active' && data.paid_through">{{ t('billing.self.paid_line', { date: formatDate(data.paid_through) }) }}</p>
                    <p v-else-if="data.status === 'past_due'" class="flex gap-2 text-warn">
                        <AlertTriangle class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <span>{{ t('billing.self.past_due_line', { date: formatDate(data.read_only_from) }) }}</span>
                    </p>
                    <p v-else-if="data.status === 'read_only'" class="flex gap-2 text-bad">
                        <AlertTriangle class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <span>{{ t('billing.self.read_only_line') }}</span>
                    </p>
                </div>

                <div v-if="data.can_manage" class="mt-4 flex flex-wrap gap-2">
                    <AppButton v-if="data.moves_to_free_on" variant="primary" size="sm" :loading="busy === 'keep'" @click="keepPlan">
                        {{ t('billing.self.keep_plan') }}
                    </AppButton>
                    <AppButton
                        v-else-if="data.plan.key !== data.free_plan_key || owes"
                        variant="ghost"
                        size="sm"
                        :loading="busy === 'free'"
                        @click="toFree"
                    >
                        {{ t('billing.self.to_free') }}
                    </AppButton>
                </div>
            </section>

            <!-- Why buying may not be possible here -->
            <div v-if="data.can_manage && !data.verified" class="flex flex-wrap items-center gap-3 rounded-xl border border-warn/25 bg-warn-soft px-4 py-3 text-[13px] text-fg-2" role="status">
                <Info class="size-4 shrink-0 text-warn" aria-hidden="true" />
                <span class="flex-1">{{ t('billing.self.unverified') }}</span>
                <AppButton size="sm" to="/account">{{ t('billing.self.unverified_action') }}</AppButton>
            </div>
            <div v-else-if="data.can_manage && !data.can_pay_online" class="flex gap-3 rounded-xl border border-line bg-subtle px-4 py-3 text-[13px] text-fg-2" role="status">
                <Info class="size-4 shrink-0 text-muted" aria-hidden="true" />
                <span>{{ t('billing.self.no_online') }}</span>
            </div>
            <div v-if="data.can_pay_online && data.test_payments" class="flex gap-3 rounded-xl border border-brand/20 bg-brand-soft px-4 py-3 text-[13px] text-fg-2" role="note">
                <FlaskConical class="size-4 shrink-0 text-brand-text" aria-hidden="true" />
                <span>{{ t('billing.self.test_mode') }}</span>
            </div>
            <div v-if="data.pending_payment" class="flex flex-wrap items-center gap-3 rounded-xl border border-line bg-surface px-4 py-3 text-[13px] text-fg-2" role="status">
                <CircleDashed class="size-4 shrink-0 animate-spin text-muted" aria-hidden="true" />
                <span class="flex-1">{{ t('billing.self.pending_payment') }}</span>
                <AppButton size="sm" :to="`/billing/payments/${data.pending_payment.id}`">{{ t('billing.self.pending_action') }}</AppButton>
            </div>

            <!-- What is owed -->
            <section v-if="owes" class="card">
                <h2 class="border-b border-line px-5 py-3.5 text-[14px] font-semibold text-fg">{{ t('billing.self.open_invoices') }}</h2>
                <ul class="divide-y divide-line">
                    <li v-for="invoice in data.open_invoices" :key="invoice.id" class="flex flex-wrap items-center gap-3 px-5 py-3.5">
                        <div class="min-w-0 flex-1">
                            <RouterLink :to="`/billing/invoices/${invoice.id}`" class="tabular text-[13.5px] font-medium text-fg hover:underline" dir="ltr">
                                {{ invoice.number }}
                            </RouterLink>
                            <p class="mt-0.5 text-[12.5px] text-muted">
                                {{ formatDate(invoice.period_start) }} – {{ formatDate(invoice.period_end) }} ·
                                {{ t('billing.list.due', { date: formatDate(invoice.due_at) }) }}
                            </p>
                        </div>
                        <AppButton v-if="canBuy" variant="primary" size="sm" :loading="busy === invoice.id" @click="payInvoice(invoice)">
                            {{ t('billing.self.pay', { amount: formatMoney({ amount: invoice.total_minor, currency: invoice.currency }) }) }}
                        </AppButton>
                    </li>
                </ul>
                <p v-if="data.can_manage" class="border-t border-line px-5 py-3 text-[12.5px] text-muted">{{ t('billing.self.pay_or_free') }}</p>
            </section>

            <!-- A free trial -->
            <section v-if="data.trial_offer && data.can_manage && data.verified" class="card flex flex-col gap-4 p-5 sm:flex-row sm:items-center">
                <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-text">
                    <Sparkles class="size-5" aria-hidden="true" />
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-[14.5px] font-semibold text-fg">
                        {{ t('billing.self.trial_title', { plan: data.trial_offer.plan_name, days: formatNumber(data.trial_offer.days) }) }}
                    </p>
                    <p class="mt-1 text-[13px] leading-relaxed text-muted">{{ t('billing.self.trial_text') }}</p>
                </div>
                <AppButton variant="primary" :loading="busy === 'trial'" @click="startTrial">{{ t('billing.self.trial_start') }}</AppButton>
            </section>

            <!-- Plans -->
            <section v-if="data.plans.length">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-[15px] font-semibold text-fg">{{ t('billing.self.plans') }}</h2>
                    <AppSegmented
                        v-if="hasYearly"
                        v-model="period"
                        size="sm"
                        :label="t('billing.self.period_label')"
                        :options="[
                            { value: 'monthly', label: t('billing.periods.monthly') },
                            { value: 'yearly', label: t('billing.periods.yearly') },
                        ]"
                    />
                </div>
                <p v-if="locked && !data.moves_to_free_on" class="mb-3 text-[12.5px] text-muted">{{ t('billing.self.change_later', { date: formatDate(data.paid_through) }) }}</p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <article v-for="plan in data.plans" :key="plan.key" class="card flex flex-col p-5" :class="isCurrent(plan) ? 'ring-2 ring-brand/40' : ''">
                        <div class="flex items-start justify-between gap-3">
                            <h3 class="text-[15px] font-semibold text-fg">{{ plan.name }}</h3>
                            <AppBadge v-if="isCurrent(plan)" tone="brand" :icon="BadgeCheck">{{ t('billing.self.current') }}</AppBadge>
                        </div>
                        <p class="mt-1 flex-1 text-[13px] leading-relaxed text-muted">{{ plan.description }}</p>
                        <p class="tabular mt-4 text-[20px] font-semibold text-fg">
                            <template v-if="!priceOf(plan)">{{ t('billing.self.free_price') }}</template>
                            <template v-else>
                                {{ formatMoney({ amount: priceOf(plan), currency: data.currency }) }}
                                <span class="text-[13px] font-normal text-muted">{{ t(`billing.per.${period}`) }}</span>
                            </template>
                        </p>
                        <p v-if="period === 'yearly' && yearlySaving(plan.prices)" class="mt-1 text-[12.5px] font-medium text-ok">
                            {{ t('billing.self.save', { amount: formatMoney({ amount: yearlySaving(plan.prices), currency: data.currency }) }) }}
                        </p>
                        <AppButton
                            v-if="priceOf(plan) && !isCurrent(plan) && canBuy && !owes && !locked"
                            class="mt-4"
                            variant="primary"
                            block
                            @click="checkout = { plan }"
                        >
                            {{ t('billing.self.choose') }}
                        </AppButton>
                        <AppButton
                            v-else-if="priceOf(plan) && isCurrent(plan) && data.status === 'trial' && canBuy"
                            class="mt-4"
                            variant="primary"
                            block
                            @click="checkout = { plan }"
                        >
                            {{ t('billing.self.choose') }}
                        </AppButton>
                    </article>
                </div>
            </section>
        </template>

        <CheckoutDialog
            v-if="checkout"
            :base="base"
            :plan="checkout.plan"
            :period="period"
            :currency="data?.currency"
            @close="checkout = null"
        />
    </div>
</template>
