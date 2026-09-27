<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { CircleCheck, CircleX, ExternalLink, Hourglass, Loader2, ShieldQuestion } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import ErrorState from '@/components/ErrorState.vue';
import PageHeader from '@/components/PageHeader.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { formatMoney } from '@/lib/format';
import { paymentSettled } from '@/lib/billing';
import { currentOrganization, loadMe } from '@/lib/session';
import { t } from '@/lib/i18n';

/**
 * Where the person lands after the payment page. The server confirms the
 * payment with the gateway (never on this page's word); this screen asks
 * every few seconds until the answer is in, then says clearly what happened.
 */
const POLL_MS = 3000;
const SLOW_MS = 30000;
const GIVE_UP_MS = 180000;

const route = useRoute();
const org = currentOrganization();

const payment = ref(null);
const error = ref(null);
const started = Date.now();
const now = ref(Date.now());
let timer = null;

const slow = computed(() => now.value - started > SLOW_MS);

const VIEWS = {
    pending: { icon: Loader2, tone: 'bg-brand-soft text-brand-text', spin: true },
    succeeded: { icon: CircleCheck, tone: 'bg-ok-soft text-ok' },
    failed: { icon: CircleX, tone: 'bg-bad-soft text-bad' },
    cancelled: { icon: CircleX, tone: 'bg-subtle text-muted' },
    expired: { icon: Hourglass, tone: 'bg-subtle text-muted' },
    review: { icon: ShieldQuestion, tone: 'bg-warn-soft text-warn' },
};
const view = computed(() => VIEWS[payment.value?.status] ?? VIEWS.pending);

async function load() {
    try {
        const response = await api(`/api/organizations/${org.id}/billing/payments/${route.params.id}`);
        const wasPending = payment.value === null || !paymentSettled(payment.value.status);
        payment.value = response.data;
        error.value = null;
        // The plan (and a read-only banner) may have changed: refresh the menu once.
        if (wasPending && response.data.status === 'succeeded') await loadMe();
    } catch (caught) {
        error.value = caught;
    }

    now.value = Date.now();
    const keepAsking = !error.value && payment.value && !paymentSettled(payment.value.status) && now.value - started < GIVE_UP_MS;
    timer = keepAsking ? setTimeout(load, POLL_MS) : null;
}

function backToGateway() {
    window.location.assign(payment.value.checkout_url);
}

onMounted(load);
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="mx-auto max-w-lg">
        <PageHeader :title="t('billing.payment.title')" />

        <SkeletonRows v-if="!payment && !error" :rows="2" />
        <ErrorState v-else-if="error && !payment" :error="error" @retry="load" />

        <section v-else-if="payment" class="card flex flex-col items-center px-6 py-10 text-center" role="status" aria-live="polite">
            <div class="mb-4 grid size-14 place-items-center rounded-2xl" :class="view.tone" aria-hidden="true">
                <component :is="view.icon" class="size-7" :class="view.spin ? 'animate-spin' : ''" />
            </div>

            <h2 class="text-[17px] font-semibold text-fg">{{ t(`billing.payment.${payment.status}_title`) }}</h2>
            <p class="mt-2 max-w-sm text-[13.5px] leading-relaxed text-muted">
                <template v-if="payment.status === 'succeeded'">
                    {{ t('billing.payment.succeeded_text', { amount: formatMoney({ amount: payment.amount_minor, currency: payment.currency }), method: payment.method ?? '—' }) }}
                </template>
                <template v-else>{{ t(`billing.payment.${payment.status}_text`) }}</template>
            </p>
            <p v-if="payment.refund_due" class="mt-2 max-w-sm text-[13px] text-warn">{{ t('billing.payment.refund_text') }}</p>
            <p v-if="payment.status === 'pending' && slow" class="mt-3 max-w-sm text-[13px] text-fg-2">{{ t('billing.payment.pending_slow') }}</p>

            <div class="mt-6 flex flex-wrap justify-center gap-2">
                <AppButton v-if="payment.status === 'succeeded' && payment.invoice" variant="primary" :to="`/billing/invoices/${payment.invoice.id}`">
                    {{ t('billing.payment.receipt') }}
                </AppButton>
                <AppButton
                    v-if="payment.status === 'pending' && slow && payment.checkout_url"
                    :icon-end="ExternalLink"
                    @click="backToGateway"
                >
                    {{ t('billing.payment.back_to_gateway') }}
                </AppButton>
                <AppButton v-if="['failed', 'cancelled', 'expired'].includes(payment.status)" variant="primary" to="/billing">
                    {{ t('billing.payment.try_again') }}
                </AppButton>
                <AppButton to="/billing">{{ t('billing.payment.to_billing') }}</AppButton>
            </div>
        </section>
    </div>
</template>
