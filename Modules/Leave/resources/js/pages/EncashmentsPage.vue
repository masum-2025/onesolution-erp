<script setup>
import { computed, ref, watch } from 'vue';
import { Banknote, Check, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { leaveApi } from '../api';
import { daysText, statusTone } from '../lib';

/**
 * Unused leave paid out. HR asks from a person's balances; someone else
 * approves (it is money) or rejects. Approved days leave the balance and
 * Payroll pays them in the run of the approval day, at a day of pay.
 */
const org = currentOrganization();
const leave = leaveApi(org.id);
const status = ref('pending');
const list = useResource(() => leave.encashments(status.value === 'all' ? undefined : status.value));
const items = computed(() => list.data.value?.data ?? []);
const busy = ref(null);
watch(status, () => list.reload());

async function decide(item, step) {
    const confirmed = await confirmAction({
        title: t(`leave.encash.${step}_title`, { name: item.employee_name }),
        message: t('leave.encash.what', { type: item.type_name, days: daysText(item.halves), year: formatNumber(item.leave_year, { useGrouping: false }) }),
        confirmLabel: t(`leave.requests.${step}`),
        reason: 'optional',
        danger: step === 'reject',
    });
    if (!confirmed) return;
    busy.value = item.id;
    try {
        await leave.decideEncashment(item.id, step, { base_version: item.version, ...(confirmed.reason ? { note: confirmed.reason } : {}) });
        toast.success(t(`leave.encash.${step}_done`));
        list.reload();
    } catch (error) {
        if (['version_conflict', 'decided'].includes(error.code)) list.reload();
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('leave.encash.title')" :description="t('leave.encash.text')">
            <template #actions>
                <AppSegmented
                    v-model="status"
                    size="sm"
                    :label="t('leave.encash.title')"
                    :options="['pending', 'approved', 'rejected', 'all'].map((value) => ({ value, label: value === 'all' ? t('leave.common.all') : t(`leave.status.${value}`) }))"
                />
            </template>
        </PageHeader>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!items.length" :icon="Banknote" :title="t('leave.encash.empty')" :text="t('leave.encash.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="item in items" :key="item.id" class="grid gap-2 px-5 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                    <div class="min-w-0">
                        <p class="text-[14px] font-medium">{{ item.employee_name }} <span class="font-mono text-[12px] font-normal text-muted" dir="ltr">{{ item.employee_code }}</span></p>
                        <p class="text-[13px]">{{ item.type_name }} · {{ t('leave.common.days', { days: daysText(item.halves) }) }} · {{ t('leave.encash.year', { year: formatNumber(item.leave_year, { useGrouping: false }) }) }}</p>
                        <p class="text-[12.5px] text-muted">
                            “{{ item.reason }}”
                            <template v-if="item.pay_on"> · {{ t('leave.encash.paid_with', { date: formatDate(`${item.pay_on}T00:00:00Z`, { month: 'long', year: 'numeric', timeZone: 'UTC' }) }) }}</template>
                            <template v-if="item.decision_note"> · {{ item.decision_note }}</template>
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <AppBadge :tone="statusTone(item.status)">{{ t(`leave.status.${item.status}`) }}</AppBadge>
                        <template v-if="item.can_decide">
                            <AppButton size="sm" variant="primary" :icon="Check" :loading="busy === item.id" @click="decide(item, 'approve')">{{ t('leave.requests.approve') }}</AppButton>
                            <AppButton size="sm" variant="ghost" :icon="X" :loading="busy === item.id" @click="decide(item, 'reject')">{{ t('leave.requests.reject') }}</AppButton>
                        </template>
                        <span v-else-if="item.status === 'pending'" class="text-[12.5px] text-muted">{{ t('leave.requests.waiting_other') }}</span>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
