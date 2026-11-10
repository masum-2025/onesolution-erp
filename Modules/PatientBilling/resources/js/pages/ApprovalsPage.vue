<script setup>
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { BadgePercent, Check, Undo2, X } from 'lucide-vue-next';
import { AppButton, AppField, AppSegmented, EmptyState, PageFrame } from '@/ui';
import AppBadge from '@/components/AppBadge.vue';
import AppDialog from '@/components/AppDialog.vue';
import { useResource } from '@/lib/useResource';
import { formatDateTime, formatMoney } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { billingApi, money } from '../api';

/**
 * Discounts above the limit, decided by someone other than the person who
 * asked, and the latest refunds for a look.
 */
const pb = billingApi(currentOrganization().id);
const router = useRouter();
const status = ref('pending');
const list = useResource(() => pb.approvals({ status: status.value }));
watch(status, () => list.reload());
const discounts = computed(() => list.data.value?.data?.discounts ?? []);
const refunds = computed(() => list.data.value?.data?.refunds ?? []);
const choices = computed(() => ['pending', 'approved', 'rejected'].map((value) => ({ value, label: t(`patient_billing.approvals.status.${value}`) })));
const tones = { pending: 'warn', approved: 'ok', rejected: 'neutral' };
const pct = (bp) => (bp / 100).toFixed(bp % 100 ? 2 : 0);

const deciding = ref(null);
const note = ref('');
async function decide(approve) {
    try {
        await pb.decide(deciding.value.id, { approve, ...(note.value.trim() ? { note: note.value.trim() } : {}) });
        toast.success(t(approve ? 'patient_billing.approvals.approved' : 'patient_billing.approvals.rejected'));
        deciding.value = null;
        note.value = '';
        list.reload();
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <PageFrame :title="t('patient_billing.approvals.title')" :description="t('patient_billing.approvals.text')" width="lg" :resource="list" guide="patient_billing.guide.approvals">
        <template #top>
            <div class="mb-4 print:hidden"><AppSegmented v-model="status" :options="choices" :label="t('patient_billing.approvals.filter')" /></div>
        </template>
        <section class="card mb-4">
            <h2 class="flex items-center gap-2 border-b border-line px-5 py-3 text-[14px] font-semibold"><BadgePercent class="size-4 text-muted" aria-hidden="true" />{{ t('patient_billing.approvals.discounts') }}</h2>
            <EmptyState v-if="!discounts.length" :icon="BadgePercent" :title="t('patient_billing.approvals.none')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="row in discounts" :key="row.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3">
                    <button type="button" class="min-w-0 flex-1 text-start" @click="router.push({ name: 'patient-billing-bill', params: { id: row.bill_id } })">
                        <span class="block text-[14px] font-medium">{{ formatMoney(money(row.amount_minor, row.currency)) }} ({{ pct(row.percent_bp) }}%) · <span class="font-mono" dir="ltr">{{ row.bill_number }}</span> · {{ row.patient?.name ?? '—' }}</span>
                        <span class="block text-[12.5px] text-muted">{{ row.requested_by_name }} · {{ formatDateTime(row.created_at) }} · {{ row.reason }}</span>
                        <span v-if="row.note" class="block text-[12.5px] text-muted">{{ row.decided_by_name }}: {{ row.note }}</span>
                    </button>
                    <AppBadge :tone="tones[row.status]" dot>{{ t(`patient_billing.approvals.status.${row.status}`) }}</AppBadge>
                    <AppButton v-if="row.status === 'pending' && !row.mine" size="sm" variant="primary" @click="deciding = row">{{ t('patient_billing.approvals.decide') }}</AppButton>
                    <span v-else-if="row.status === 'pending'" class="text-[12px] text-muted">{{ t('patient_billing.approvals.waiting') }}</span>
                </li>
            </ul>
        </section>
        <section class="card">
            <h2 class="flex items-center gap-2 border-b border-line px-5 py-3 text-[14px] font-semibold"><Undo2 class="size-4 text-muted" aria-hidden="true" />{{ t('patient_billing.approvals.refunds') }}</h2>
            <EmptyState v-if="!refunds.length" :icon="Undo2" :title="t('patient_billing.approvals.no_refunds')" compact />
            <ul v-else class="divide-y divide-line text-[13px]">
                <li v-for="row in refunds" :key="row.id" class="flex justify-between gap-3 px-5 py-2.5">
                    <span><span class="font-mono" dir="ltr">{{ row.number }}</span> · <span class="font-mono" dir="ltr">{{ row.bill_number }}</span> · {{ t(`patient_billing.method.${row.method}`) }}<span class="block text-[12px] text-muted">{{ row.received_by_name }} · {{ formatDateTime(row.received_at) }} · {{ row.reason }}</span></span>
                    <span class="tabular font-medium text-bad">− {{ formatMoney(money(row.amount_minor, row.currency)) }}</span>
                </li>
            </ul>
        </section>

        <AppDialog :open="!!deciding" :title="t('patient_billing.approvals.decide')" :description="deciding ? t('patient_billing.approvals.decide_text', { amount: formatMoney(money(deciding.amount_minor, deciding.currency)), number: deciding.bill_number, reason: deciding.reason }) : ''" :icon="BadgePercent" @close="deciding = null">
            <AppField v-slot="{ id }" :label="t('patient_billing.approvals.note')" :hint="t('patient_billing.approvals.note_hint')">
                <input :id="id" v-model="note" class="field-input" maxlength="300" />
            </AppField>
            <template #footer>
                <AppButton variant="ghost" :icon="X" :disabled="!note.trim()" @click="decide(false)">{{ t('patient_billing.approvals.reject') }}</AppButton>
                <AppButton variant="primary" :icon="Check" @click="decide(true)">{{ t('patient_billing.approvals.approve') }}</AppButton>
            </template>
        </AppDialog>
    </PageFrame>
</template>
