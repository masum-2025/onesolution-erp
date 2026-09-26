<script setup>
import { computed, ref, watch } from 'vue';
import { ArrowRightLeft, Check, CircleAlert, PowerOff, Sparkles } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import ErrorState from '@/components/ErrorState.vue';
import { api } from '@/lib/http';
import { formatMoney, formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Move a client subscription to another plan (ours, or one of the partner's
 * own) and set the currency and period it is billed in. Choosing shows the
 * effect first (modules that stop, limits already exceeded); saving needs a
 * reason for the audit log.
 */
const props = defineProps({
    open: Boolean,
    client: { type: Object, default: null }, // a top organization from /api/partner/organizations
});

const emit = defineEmits(['close', 'changed']);

const subscription = ref(null);
const loadError = ref(null);
const chosen = ref(null);
const currency = ref('');
const period = ref('monthly');
const preview = ref(null);
const previewing = ref(false);
const previewError = ref(null);
const reason = ref('');
const reasonError = ref(null);
const saving = ref(false);

const options = computed(() => {
    const data = subscription.value;
    if (!data) return [];
    return [
        ...data.base_plans.map((plan) => ({ id: `plan:${plan.key}`, plan: plan.key, partnerPlanId: null, name: plan.name, prices: plan.list_prices, own: false })),
        ...data.partner_plans
            .filter((plan) => plan.status === 'active' || plan.id === data.partner_plan_id)
            .map((plan) => ({ id: `pp:${plan.id}`, plan: plan.base_plan_key, partnerPlanId: plan.id, name: plan.name, prices: plan.prices, own: true })),
    ];
});

const currentId = computed(() => {
    const data = subscription.value;
    if (!data) return null;
    return data.partner_plan_id ? `pp:${data.partner_plan_id}` : `plan:${data.plan_key}`;
});
const option = computed(() => options.value.find((item) => item.id === chosen.value) ?? null);
const currencies = computed(() => [...new Set([subscription.value?.currency, ...(option.value?.prices ?? []).map((price) => price.currency)].filter(Boolean))].sort());
const price = (item, cur = currency.value, per = period.value) => item?.prices.find((entry) => entry.currency === cur && entry.period === per)?.amount_minor ?? null;
const chosenPrice = computed(() => price(option.value));
// Wholesale partners bill their clients themselves: a price is only for their own records.
const needsPrice = computed(() => subscription.value?.billing_mode !== 'wholesale');
const unchanged = computed(
    () => chosen.value === currentId.value && currency.value === subscription.value?.currency && period.value === subscription.value?.period,
);

watch(
    () => props.open,
    async (open) => {
        if (!open) return;
        subscription.value = null;
        loadError.value = null;
        preview.value = null;
        reason.value = '';
        reasonError.value = null;
        try {
            subscription.value = (await api(`/api/partner/clients/${props.client.id}/subscription`)).data;
            chosen.value = currentId.value;
            currency.value = subscription.value.currency;
            period.value = subscription.value.period;
        } catch (error) {
            loadError.value = error;
        }
    },
);

function choice() {
    const item = option.value;
    return item.partnerPlanId ? { partner_plan_id: item.partnerPlanId, currency: currency.value, period: period.value } : { plan: item.plan, currency: currency.value, period: period.value };
}

watch([chosen, currency, period], async () => {
    preview.value = null;
    previewError.value = null;
    if (!option.value || unchanged.value || (needsPrice.value && chosenPrice.value === null)) return;
    previewing.value = true;
    try {
        preview.value = (await api(`/api/partner/organizations/${props.client.id}/plan-preview`, { query: choice() })).data;
    } catch (error) {
        previewError.value = error;
    } finally {
        previewing.value = false;
    }
});

async function save() {
    reasonError.value = reason.value.trim().length < 5 ? t('core.confirm.reason_short') : null;
    if (reasonError.value || !option.value || unchanged.value) return;

    saving.value = true;
    try {
        const response = await api(`/api/partner/organizations/${props.client.id}/plan`, { method: 'PUT', body: { ...choice(), reason: reason.value.trim() } });
        toast.success(response.message);
        emit('changed');
    } catch (error) {
        reasonError.value = error.field('reason');
        if (!reasonError.value) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

function limitLine(item) {
    return t('packaging.change.over_limit', { limit: t(`packaging.limits.${item.limit}`), used: formatNumber(item.used), max: formatNumber(item.max) });
}
</script>

<template>
    <AppDialog
        :open="open"
        size="lg"
        :title="t('packaging.change.title', { name: client?.display_name ?? '' })"
        :description="t('packaging.change.text')"
        :icon="ArrowRightLeft"
        @close="emit('close')"
    >
        <ErrorState v-if="loadError" compact :error="loadError" />
        <div v-else-if="!subscription" class="space-y-3" role="status" :aria-label="t('core.states.loading')">
            <div v-for="n in 3" :key="n" class="skeleton h-12 w-full" />
        </div>

        <form v-else id="plan-change-form" class="space-y-4" novalidate @submit.prevent="save">
            <div role="radiogroup" :aria-label="t('packaging.change.choose')" class="grid gap-2 sm:grid-cols-3">
                <button
                    v-for="item in options"
                    :key="item.id"
                    type="button"
                    role="radio"
                    :aria-checked="chosen === item.id"
                    class="flex flex-col items-start gap-1 rounded-xl border p-3 text-start transition"
                    :class="chosen === item.id ? 'border-brand bg-brand-soft/50 ring-1 ring-brand/30' : 'border-line bg-surface hover:border-line-strong'"
                    @click="chosen = item.id"
                >
                    <span class="flex w-full items-center justify-between gap-2">
                        <span class="min-w-0 truncate text-[13.5px] font-semibold text-fg">{{ item.name }}</span>
                        <Check v-if="chosen === item.id" class="size-4 shrink-0 text-brand-text" aria-hidden="true" />
                    </span>
                    <span class="tabular text-[13px] text-fg-2">
                        {{ price(item) === null ? '—' : formatMoney({ amount: price(item), currency }) }}
                        <span class="text-[11.5px] text-muted">{{ t(`billing.per.${period}`) }}</span>
                    </span>
                    <span class="flex flex-wrap gap-1">
                        <AppBadge v-if="item.own" tone="brand">{{ t('billing.change.your_plan') }}</AppBadge>
                        <AppBadge v-if="item.id === currentId" tone="outline">{{ t('packaging.change.current') }}</AppBadge>
                    </span>
                </button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <AppField :label="t('billing.change.currency')">
                    <template #default="{ id }">
                        <select :id="id" v-model="currency" class="field-input">
                            <option v-for="code in currencies" :key="code" :value="code">{{ code }}</option>
                        </select>
                    </template>
                </AppField>
                <AppField :label="t('billing.change.period')">
                    <template #default="{ id }">
                        <select :id="id" v-model="period" class="field-input">
                            <option value="monthly">{{ t('billing.periods.monthly') }}</option>
                            <option value="yearly">{{ t('billing.periods.yearly') }}</option>
                        </select>
                    </template>
                </AppField>
            </div>

            <p v-if="needsPrice && option && chosenPrice === null" class="flex items-start gap-2 rounded-xl bg-warn-soft p-3 text-[13px] text-fg-2" role="alert">
                <CircleAlert class="mt-0.5 size-4 shrink-0 text-warn" aria-hidden="true" />
                {{ t('billing.change.no_price', { currency, period: t(`billing.periods.${period}`) }) }}
            </p>

            <div v-if="previewing" class="space-y-2" role="status" :aria-label="t('core.states.loading')"><div class="skeleton h-4 w-2/3" /><div class="skeleton h-4 w-1/2" /></div>
            <ErrorState v-else-if="previewError" compact :error="previewError" />
            <div v-else-if="preview" class="space-y-2.5 rounded-xl border border-line p-3.5 text-[13px]">
                <p class="font-medium text-fg">{{ t('packaging.change.effect') }}</p>
                <p v-if="preview.modules_off.length" class="flex items-start gap-2 text-bad">
                    <PowerOff class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>{{ t('packaging.change.modules_off', { list: preview.modules_off.map((module) => module.name).join(', ') }) }}</span>
                </p>
                <p v-if="preview.modules_on.length" class="flex items-start gap-2 text-ok">
                    <Sparkles class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>{{ t('packaging.change.modules_on', { list: preview.modules_on.map((module) => module.name).join(', ') }) }}</span>
                </p>
                <p v-for="item in preview.over_limits" :key="item.limit" class="flex items-start gap-2 text-warn">
                    <CircleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <span>{{ limitLine(item) }}</span>
                </p>
                <p v-if="!preview.modules_off.length && !preview.modules_on.length && !preview.over_limits.length" class="text-muted">{{ t('packaging.change.no_effect') }}</p>
                <p v-if="preview.modules_off.length" class="text-[12px] text-muted">{{ t('packaging.change.data_kept') }}</p>
            </div>

            <AppField :label="t('core.confirm.reason')" :hint="t('core.confirm.reason_hint')" :error="reasonError">
                <template #default="{ id, invalid, describedby }">
                    <input
                        :id="id"
                        v-model="reason"
                        class="field-input"
                        maxlength="500"
                        :placeholder="t('packaging.change.reason_placeholder')"
                        :aria-invalid="invalid || undefined"
                        :aria-describedby="describedby"
                    />
                </template>
            </AppField>
        </form>

        <template #footer>
            <AppButton @click="emit('close')">{{ t('core.actions.cancel') }}</AppButton>
            <AppButton
                type="submit"
                form="plan-change-form"
                :variant="preview?.modules_off.length ? 'danger' : 'primary'"
                :loading="saving"
                :disabled="!subscription?.can_change || !option || unchanged || previewing || !!previewError || (needsPrice && chosenPrice === null)"
            >
                {{ t('packaging.change.submit') }}
            </AppButton>
        </template>
    </AppDialog>
</template>
