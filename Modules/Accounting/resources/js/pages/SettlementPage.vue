<script setup>
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Ban, BookOpen, Check, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatMoney } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { accountingApi } from '../api';
import { documentTone } from '../lib';
import AllocationPicker from '../components/AllocationPicker.vue';

/**
 * One record of money received or paid: the documents it paid, what is left
 * as an advance, and the steps the reader may take (approve, reject, void,
 * set the advance against documents).
 */
const books = accountingApi(currentOrganization().id);
const route = useRoute();
const entry = useResource(() => books.settlement(route.params.id));
const record = computed(() => entry.data.value?.data ?? null);
const money = (amount) => formatMoney({ amount, currency: record.value?.currency });
const busy = ref(null);

async function step(name, body = {}) {
    busy.value = name;
    try {
        const { data } = await books.settlementStep(record.value.id, name, { base_version: record.value.version, ...body });
        toast.success(t(`accounting.settlements.done.${name}`));
        entry.data.value = { data };
        return true;
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('accounting.common.conflict'));
            entry.reload();
            return true;
        }
        if (error.errors) return error.errors;
        toast.error(error.message);
        return true;
    } finally {
        busy.value = null;
    }
}

async function approve() {
    const confirmed = await confirmAction({ title: t('accounting.journal.approve_title'), message: `${record.value.party_name} · ${money(record.value.amount_minor)}`, confirmLabel: t('accounting.journal.steps.approve') });
    if (confirmed) step('approve');
}

async function withReason(name) {
    const confirmed = await confirmAction({
        title: name === 'void' ? t('accounting.settlements.void_title', { number: record.value.number }) : t('accounting.journal.reject_title'),
        message: name === 'void' ? t('accounting.settlements.void_text') : t('accounting.journal.reject_text'),
        confirmLabel: t(name === 'void' ? 'accounting.journal.steps.void' : 'accounting.journal.steps.reject'),
        reason: 'required',
        danger: true,
    });
    if (confirmed) step(name, { reason: confirmed.reason });
}

const allocating = ref(false);
const openDocuments = ref([]);
const shares = ref({});
const picker = ref(null);
const allocateErrors = ref({});
async function openAllocate() {
    openDocuments.value = (await books.documents({ party_id: record.value.party_id, type: record.value.type === 'receipt' ? 'invoice' : 'bill', status: 'open', per_page: 100 })).data;
    shares.value = {};
    allocateErrors.value = {};
    allocating.value = true;
}
async function allocate() {
    const result = await step('allocate', { allocations: picker.value.payload() });
    if (result === true) allocating.value = false;
    else allocateErrors.value = result;
}
</script>

<template>
    <div>
        <PageHeader :title="record ? `${t(`accounting.kinds.${record.type}`)} ${record.number ?? ''}` : ''" :description="record?.party_name ?? ''">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: record?.type === 'payment' ? 'accounting-payments' : 'accounting-receipts' }" :icon="ArrowLeft">{{ t('accounting.common.back') }}</AppButton>
            </template>
        </PageHeader>

        <section v-if="entry.loading.value && !record" class="card"><SkeletonRows :rows="5" /></section>
        <section v-else-if="entry.error.value" class="card"><ErrorState compact :error="entry.error.value" @retry="entry.reload()" /></section>

        <div v-else-if="record" class="grid gap-5">
            <section class="card grid gap-4 p-5 sm:grid-cols-4">
                <div>
                    <div class="text-[12px] text-muted">{{ t('accounting.journal.status') }}</div>
                    <AppBadge :tone="record.status === 'posted' ? 'ok' : documentTone(record.status)" dot class="mt-1">{{ t(`accounting.statuses.${record.status}`) }}</AppBadge>
                </div>
                <div>
                    <div class="text-[12px] text-muted">{{ t('accounting.settlements.date') }}</div>
                    <div class="mt-1 text-[14px] font-medium">{{ formatDate(record.settled_on) }}</div>
                </div>
                <div>
                    <div class="text-[12px] text-muted">{{ t('accounting.settlements.amount') }}</div>
                    <div class="tabular mt-1 text-[16px] font-semibold">{{ money(record.amount_minor) }}</div>
                </div>
                <div v-if="record.status === 'posted'">
                    <div class="text-[12px] text-muted">{{ t('accounting.settlements.unallocated') }}</div>
                    <div class="tabular mt-1 text-[14px] font-medium">{{ money(record.unallocated_minor) }}</div>
                </div>
                <p v-if="record.reference || record.memo" class="text-[13px] text-muted sm:col-span-4">{{ [record.reference, record.memo].filter(Boolean).join(' · ') }}</p>
                <p v-if="record.reject_reason" class="rounded-lg bg-bad-soft px-3 py-2 text-[13px] text-bad sm:col-span-4">{{ t('accounting.journal.rejected', { reason: record.reject_reason }) }}</p>
                <p v-if="record.void_reason" class="rounded-lg bg-subtle px-3 py-2 text-[13px] text-muted sm:col-span-4">{{ record.void_reason }}</p>
            </section>

            <section class="card">
                <header class="border-b border-line px-5 py-3 text-[14px] font-semibold">{{ t('accounting.settlements.allocations') }}</header>
                <p v-if="!record.allocations.length" class="px-5 py-4 text-[13px] text-muted">{{ t('accounting.documents.no_payments') }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="allocation in record.allocations" :key="allocation.document_id + allocation.allocated_on" class="flex items-center justify-between px-5 py-2.5 text-[13.5px]">
                        <RouterLink class="font-medium text-brand-strong hover:underline" :to="{ name: 'accounting-document', params: { id: allocation.document_id } }">
                            <span class="font-mono" dir="ltr">{{ allocation.document_number }}</span>
                        </RouterLink>
                        <span class="tabular">{{ money(allocation.amount_minor) }}</span>
                    </li>
                </ul>
            </section>

            <div class="flex flex-wrap justify-end gap-2">
                <AppButton v-if="record.journal_id" variant="ghost" :icon="BookOpen" :to="{ name: 'accounting-journal', params: { id: record.journal_id } }">{{ t('accounting.documents.journal') }}</AppButton>
                <AppButton v-if="record.can.reject" variant="danger-soft" :icon="X" @click="withReason('reject')">{{ t('accounting.journal.steps.reject') }}</AppButton>
                <AppButton v-if="record.can.approve" variant="primary" :icon="Check" :loading="busy === 'approve'" @click="approve">{{ t('accounting.journal.steps.approve') }}</AppButton>
                <AppButton v-if="record.can.void" variant="danger-soft" :icon="Ban" @click="withReason('void')">{{ t('accounting.journal.steps.void') }}</AppButton>
                <AppButton v-if="record.can.allocate" variant="primary" @click="openAllocate">{{ t('accounting.settlements.allocate') }}</AppButton>
            </div>
        </div>

        <AppDialog :open="allocating" :title="t('accounting.settlements.allocate_title')" size="lg" @close="allocating = false">
            <AllocationPicker ref="picker" v-model="shares" :documents="openDocuments" :available="record?.unallocated_minor ?? 0" :currency="record?.currency ?? 'BDT'" :errors="allocateErrors" />
            <template #footer>
                <AppButton variant="ghost" @click="allocating = false">{{ t('accounting.common.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="busy === 'allocate'" @click="allocate">{{ t('accounting.settlements.allocate') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
