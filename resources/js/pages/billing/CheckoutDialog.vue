<script setup>
import { computed, onMounted, ref } from 'vue';
import { CreditCard, ExternalLink } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { newOpId } from '@/lib/billing';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Buying a plan: the server's price first (with tax and dates), then the
 * gateway's hosted page. One op_id per dialog, so a double click or a retry
 * after a lost answer opens the same payment instead of a second one.
 */
const props = defineProps({
    base: { type: String, required: true },
    plan: { type: Object, required: true },
    period: { type: String, required: true },
    currency: { type: String, default: null },
});
const emit = defineEmits(['close']);

const quote = ref(null);
const error = ref(null);
const loading = ref(true);
const paying = ref(false);
const opId = newOpId();

const money = (amount) => formatMoney({ amount, currency: quote.value.currency });
const taxRate = computed(() => (quote.value ? formatNumber(quote.value.tax_rate_bp / 100) : ''));

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const response = await api(`${props.base}/quote`, { method: 'POST', body: { plan_key: props.plan.key, period: props.period } });
        quote.value = response.data;
    } catch (caught) {
        error.value = caught;
    } finally {
        loading.value = false;
    }
}

async function pay() {
    paying.value = true;
    try {
        const response = await api(`${props.base}/checkout`, {
            method: 'POST',
            body: { plan_key: props.plan.key, period: props.period, op_id: opId },
        });
        if (response.data.checkout_url) {
            window.location.assign(response.data.checkout_url);
            return; // keep the button busy while the browser leaves
        }
        toast.error(t('billing.payment.failed_text'));
    } catch (caught) {
        toast.error(caught.message);
    }
    paying.value = false;
}

onMounted(load);
</script>

<template>
    <AppDialog open :title="t('billing.checkout.title', { plan: plan.name })" :description="t('billing.checkout.text')" :icon="CreditCard" @close="emit('close')">
        <SkeletonRows v-if="loading" :rows="3" />
        <ErrorState v-else-if="error" :error="error" compact @retry="load" />

        <dl v-else-if="quote" class="divide-y divide-line rounded-xl border border-line text-[13.5px]">
            <div class="flex justify-between gap-4 px-4 py-2.5">
                <dt class="text-muted">{{ t('billing.checkout.plan') }}</dt>
                <dd class="font-medium text-fg">{{ plan.name }} · {{ t(`billing.periods.${quote.period}`) }}</dd>
            </div>
            <div class="flex justify-between gap-4 px-4 py-2.5">
                <dt class="text-muted">{{ t('billing.checkout.period') }}</dt>
                <dd class="text-fg">{{ t('billing.checkout.dates', { from: formatDate(quote.starts_on), to: formatDate(quote.ends_on) }) }}</dd>
            </div>
            <div class="flex justify-between gap-4 px-4 py-2.5">
                <dt class="text-muted">{{ t('billing.checkout.subtotal') }}</dt>
                <dd class="tabular text-fg">{{ money(quote.subtotal_minor) }}</dd>
            </div>
            <div v-if="quote.tax_rate_bp > 0" class="flex justify-between gap-4 px-4 py-2.5">
                <dt class="text-muted">{{ t('billing.checkout.tax', { rate: taxRate }) }}</dt>
                <dd class="tabular text-fg">{{ money(quote.tax_minor) }}</dd>
            </div>
            <div class="flex justify-between gap-4 bg-subtle/60 px-4 py-3">
                <dt class="font-semibold text-fg">{{ t('billing.checkout.total') }}</dt>
                <dd class="tabular text-[15px] font-semibold text-fg">{{ money(quote.total_minor) }}</dd>
            </div>
        </dl>

        <p v-if="quote" class="mt-3 text-[12.5px] leading-relaxed text-muted">{{ t('billing.checkout.leave_note') }}</p>

        <template #footer>
            <AppButton @click="emit('close')">{{ t('core.actions.cancel') }}</AppButton>
            <AppButton variant="primary" :icon-end="ExternalLink" :disabled="!quote" :loading="paying" @click="pay">
                {{ paying ? t('billing.checkout.redirecting') : quote ? t('billing.checkout.pay', { amount: money(quote.total_minor) }) : t('billing.checkout.loading') }}
            </AppButton>
        </template>
    </AppDialog>
</template>
