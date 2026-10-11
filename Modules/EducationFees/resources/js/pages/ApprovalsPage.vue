<script setup>
import { computed, ref } from 'vue';
import { BadgePercent, Ban, Check, Inbox, Undo2, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization, session } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { feesApi } from '../api';
import { useFeesSetup } from '../setup';
import { percentText } from '../lib';
import { day } from '../labels';
import ReasonDialog from '../components/ReasonDialog.vue';

/**
 * What waits for a second person: discounts (education_fees.approve),
 * receipt voids and refunds (education_fees.void). Never one's own request
 * or a receipt one took: the server refuses it, the screen says so first.
 */
const api = feesApi(currentOrganization().id);
const setup = useFeesSetup();
const me = computed(() => session.me?.user?.id);

const discounts = useResource(() => api.concessions({ status: 'pending' }));
const voids = useResource(() => api.voids('pending'));
const refunds = useResource(() => api.refunds({ status: 'pending' }));
const groups = computed(() => [
    { key: 'discounts', icon: BadgePercent, resource: discounts, can: setup.can('approve') },
    { key: 'voids', icon: Ban, resource: voids, can: setup.can('void') },
    { key: 'refunds', icon: Undo2, resource: refunds, can: setup.can('void') },
]);

function title(key, row) {
    if (key === 'discounts') {
        const off = row.mode === 'percent' ? t('education_fees.discount.off_percent', { percent: percentText(row.percent_bp) }) : t('education_fees.discount.off_fixed', { amount: setup.money(row.amount_minor) });
        return `${row.student?.name ?? '—'} · ${off} ${row.head_id ? t('education_fees.discount.on_head', { head: setup.headName(row.head_id) }) : t('education_fees.discount.on_all')}`;
    }
    if (key === 'voids') return `${row.student?.name ?? '—'} · ${row.receipt?.number ?? ''} · ${setup.money(row.receipt?.amount_minor)}`;
    return `${row.student?.name ?? '—'} · ${setup.money(row.amount_minor)} · ${t(`education_fees.methods.${row.method}`)}`;
}
function subtitle(key, row, people) {
    const parts = [row.reason, t('education_fees.approvals.asked_by', { name: people[row.requested_by] ?? '—' })];
    if (key === 'voids' && row.receipt) parts.push(t('education_fees.approvals.taken_by', { name: people[row.receipt.collected_by] ?? t('education_fees.receipt.online') }), day(row.receipt.received_on));
    return parts.filter(Boolean).join(' · ');
}
function own(key, row) {
    return row.requested_by === me.value || (key === 'voids' && row.receipt?.collected_by === me.value);
}

const busy = ref(null);
const refusing = ref(null);
async function decide(key, row, approve, note = null) {
    busy.value = row.id;
    try {
        const body = { base_version: row.version, ...(note ? { note } : {}) };
        if (key === 'discounts') await api.decideConcession(row.id, approve, body);
        else if (key === 'voids') await api.decideVoid(row.id, approve, body);
        else await api.decideRefund(row.id, approve, body);
        toast.success(t(approve ? 'education_fees.approvals.approved' : 'education_fees.approvals.rejected'));
        refusing.value = null;
        groups.value.find((group) => group.key === key).resource.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('education_fees.approvals.title')" :description="t('education_fees.approvals.text')" />

        <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
            <section v-for="group in groups" :key="group.key" class="card h-fit min-w-0">
                <header class="flex items-center gap-2 border-b border-line px-5 py-3">
                    <component :is="group.icon" class="size-4 text-muted" aria-hidden="true" />
                    <h2 class="flex-1 text-[13.5px] font-semibold text-fg">{{ t(`education_fees.approvals.${group.key}`) }}</h2>
                    <span v-if="group.resource.data.value?.data.length" class="tabular rounded-full bg-warn-soft px-2 py-0.5 text-[12px] font-semibold text-fg">{{ group.resource.data.value.data.length }}</span>
                </header>
                <SkeletonRows v-if="group.resource.loading.value && !group.resource.data.value" :rows="3" />
                <ErrorState v-else-if="group.resource.error.value" compact :error="group.resource.error.value" @retry="group.resource.reload()" />
                <div v-else-if="!group.resource.data.value?.data.length" class="flex items-center gap-2 px-5 py-6 text-[13px] text-muted"><Inbox class="size-4" aria-hidden="true" />{{ t('education_fees.approvals.none') }}</div>
                <template v-else>
                    <p v-if="!group.can" class="border-b border-line px-5 py-2 text-[12px] text-muted">{{ t('education_fees.approvals.cannot') }}</p>
                    <ul class="divide-y divide-line">
                        <li v-for="row in group.resource.data.value.data" :key="row.id" class="px-5 py-3">
                            <p class="text-[13.5px] font-medium text-fg">{{ title(group.key, row) }}</p>
                            <p class="mt-0.5 text-[12.5px] text-muted">{{ subtitle(group.key, row, group.resource.data.value.people ?? {}) }}</p>
                            <p v-if="group.can && own(group.key, row)" class="mt-2 text-[12px] text-warn">{{ t('education_fees.approvals.own') }}</p>
                            <div v-else-if="group.can" class="mt-2 flex gap-2">
                                <AppButton size="sm" variant="primary" :icon="Check" :loading="busy === row.id" @click="decide(group.key, row, true)">{{ t('education_fees.approvals.approve') }}</AppButton>
                                <AppButton size="sm" variant="danger-soft" :icon="X" @click="refusing = { key: group.key, row }">{{ t('education_fees.approvals.reject') }}</AppButton>
                            </div>
                        </li>
                    </ul>
                </template>
            </section>
        </div>

        <ReasonDialog :open="refusing !== null" :title="t('education_fees.approvals.reject_title')" :label="t('education_fees.approvals.reject_reason')"
            :confirm-label="t('education_fees.approvals.reject')" :busy="busy === refusing?.row.id" @close="refusing = null" @submit="(note) => decide(refusing.key, refusing.row, false, note)" />
    </div>
</template>
