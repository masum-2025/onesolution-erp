<script setup>
import { computed, ref, watch } from 'vue';
import { ArrowRightLeft, Check, CircleAlert, PowerOff, Sparkles } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import ErrorState from '@/components/ErrorState.vue';
import { api } from '@/lib/http';
import { loadPlans, planPrice } from '@/lib/packaging';
import { formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Move a client subscription to another plan. Choosing a plan shows its
 * effect first (modules that stop, limits already exceeded); saving needs a
 * reason for the audit log.
 */
const props = defineProps({
    open: Boolean,
    client: { type: Object, default: null }, // a top organization from /api/partner/organizations
});

const emit = defineEmits(['close', 'changed']);

const plans = ref([]);
const plansError = ref(null);
const chosen = ref(null);
const preview = ref(null);
const previewing = ref(false);
const previewError = ref(null);
const reason = ref('');
const reasonError = ref(null);
const saving = ref(false);

const current = computed(() => props.client?.subscription_plan ?? null);

watch(
    () => props.open,
    async (open) => {
        if (!open) return;
        chosen.value = null;
        preview.value = null;
        reason.value = '';
        reasonError.value = null;
        plansError.value = null;
        try {
            plans.value = await loadPlans();
        } catch (error) {
            plansError.value = error;
        }
    },
);

watch(chosen, async (plan) => {
    preview.value = null;
    previewError.value = null;
    if (!plan) return;
    previewing.value = true;
    try {
        preview.value = (await api(`/api/partner/organizations/${props.client.id}/plan-preview`, { query: { plan } })).data;
    } catch (error) {
        previewError.value = error;
    } finally {
        previewing.value = false;
    }
});

async function save() {
    reasonError.value = reason.value.trim().length < 5 ? t('core.confirm.reason_short') : null;
    if (reasonError.value || !chosen.value) return;

    saving.value = true;
    try {
        const response = await api(`/api/partner/organizations/${props.client.id}/plan`, {
            method: 'PUT',
            body: { plan: chosen.value, reason: reason.value.trim() },
        });
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
        <ErrorState v-if="plansError" compact :error="plansError" />

        <form v-else id="plan-change-form" class="space-y-4" novalidate @submit.prevent="save">
            <div role="radiogroup" :aria-label="t('packaging.change.choose')" class="grid gap-2 sm:grid-cols-3">
                <button
                    v-for="plan in plans"
                    :key="plan.key"
                    type="button"
                    role="radio"
                    :aria-checked="chosen === plan.key"
                    :disabled="plan.key === current"
                    class="flex flex-col items-start gap-1 rounded-xl border p-3 text-start transition disabled:cursor-default"
                    :class="chosen === plan.key ? 'border-brand bg-brand-soft/50 ring-1 ring-brand/30' : plan.key === current ? 'border-line bg-subtle' : 'border-line bg-surface hover:border-line-strong'"
                    @click="chosen = plan.key"
                >
                    <span class="flex w-full items-center justify-between gap-2">
                        <span class="text-[13.5px] font-semibold text-fg">{{ plan.name }}</span>
                        <Check v-if="chosen === plan.key" class="size-4 text-brand-text" aria-hidden="true" />
                    </span>
                    <span class="tabular text-[13px] text-fg-2">{{ planPrice(plan, client?.currency_code) ?? '—' }} <span class="text-[11.5px] text-muted">{{ t('packaging.per_month') }}</span></span>
                    <span v-if="plan.key === current" class="text-[11.5px] font-medium text-brand-text">{{ t('packaging.change.current') }}</span>
                </button>
            </div>

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
                :disabled="!chosen || previewing || !!previewError"
            >
                {{ t('packaging.change.submit') }}
            </AppButton>
        </template>
    </AppDialog>
</template>
