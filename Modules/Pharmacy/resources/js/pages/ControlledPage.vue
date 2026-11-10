<script setup>
import { computed, ref } from 'vue';
import { BookLock } from 'lucide-vue-next';
import { DateInput, PageFrame } from '@/ui';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import { useResource } from '@/lib/useResource';
import { formatDateTime, formatNumber } from '@/lib/format';
import { textIn } from '@/lib/texts';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { pharmacyApi, pieces } from '../api';

/**
 * The controlled-drug register: every in and out of a controlled medicine,
 * oldest first, with its batch, the balance after, and for whom. Nothing here
 * changes or goes; printed for an inspection. Reading it is recorded.
 */
const ph = pharmacyApi(currentOrganization().id);
const medicines = useResource(() => ph.medicines({ per_page: 100 }));
const controlled = computed(() => (medicines.data.value?.data ?? []).filter((row) => row.is_controlled));
const medicineId = ref('');
const from = ref('');
const to = ref('');
const list = useResource(() => ph.controlled({ medicine_id: medicineId.value || undefined, from: from.value || undefined, to: to.value || undefined }));
const entries = computed(() => list.data.value?.data ?? []);
const exported = computed(() => ({
    module: 'pharmacy',
    page: 'pharmacy.controlled',
    columns: [
        { key: 'recorded_at', label: t('pharmacy.controlled.when'), value: (row) => formatDateTime(row.recorded_at) },
        { key: 'name', label: t('pharmacy.medicines.brand'), value: (row) => textIn(row.name) },
        { key: 'batch_number', label: t('pharmacy.purchases.batch') },
        { key: 'in', label: t('pharmacy.controlled.in'), value: (row) => (row.direction === 'in' ? pieces(row.quantity_milli) : '') },
        { key: 'out', label: t('pharmacy.controlled.out'), value: (row) => (row.direction === 'out' ? pieces(row.quantity_milli) : '') },
        { key: 'balance', label: t('pharmacy.controlled.balance'), value: (row) => pieces(row.balance_after_milli) },
        { key: 'patient_name', label: t('pharmacy.sell.patient_name') },
        { key: 'prescriber_bmdc', label: t('pharmacy.sell.bmdc') },
        { key: 'note', label: t('pharmacy.controlled.note') },
    ],
    rows: entries.value,
}));
</script>

<template>
    <PageFrame :title="t('pharmacy.controlled.title')" :description="t('pharmacy.controlled.text')" width="full" :resource="list" :export="exported" guide="pharmacy.guide.controlled">
        <template #top>
            <section class="card mb-4 flex flex-wrap items-end gap-3 p-4 print:hidden">
                <label class="grid gap-1 text-[12.5px] text-muted">{{ t('pharmacy.controlled.medicine') }}
                    <select v-model="medicineId" class="field-input">
                        <option value="">{{ t('pharmacy.controlled.all') }}</option>
                        <option v-for="row in controlled" :key="row.id" :value="row.id">{{ textIn(row.brand_name) }} {{ row.strength }}</option>
                    </select>
                </label>
                <label class="grid gap-1 text-[12.5px] text-muted">{{ t('pharmacy.controlled.from') }}<DateInput v-model="from" /></label>
                <label class="grid gap-1 text-[12.5px] text-muted">{{ t('pharmacy.controlled.to') }}<DateInput v-model="to" /></label>
                <AppButton variant="primary" @click="list.reload()">{{ t('pharmacy.controlled.show') }}</AppButton>
            </section>
        </template>
        <section class="card overflow-x-auto">
            <EmptyState v-if="!entries.length" :icon="BookLock" :title="t('pharmacy.controlled.empty')" :text="t('pharmacy.controlled.empty_text')" compact />
            <table v-else class="w-full text-[13px]">
                <thead class="text-start text-[12px] text-muted">
                    <tr class="border-b border-line">
                        <th class="px-4 py-2 text-start font-medium">{{ t('pharmacy.controlled.when') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ t('pharmacy.medicines.brand') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ t('pharmacy.purchases.batch') }}</th>
                        <th class="px-4 py-2 text-end font-medium">{{ t('pharmacy.controlled.in') }}</th>
                        <th class="px-4 py-2 text-end font-medium">{{ t('pharmacy.controlled.out') }}</th>
                        <th class="px-4 py-2 text-end font-medium">{{ t('pharmacy.controlled.balance') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ t('pharmacy.controlled.whom') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="row in entries" :key="row.id">
                        <td class="px-4 py-2 whitespace-nowrap">{{ formatDateTime(row.recorded_at) }}</td>
                        <td class="px-4 py-2">{{ textIn(row.name) }}</td>
                        <td class="px-4 py-2 font-mono" dir="ltr">{{ row.batch_number ?? '—' }}</td>
                        <td class="tabular px-4 py-2 text-end">{{ row.direction === 'in' ? formatNumber(pieces(row.quantity_milli)) : '' }}</td>
                        <td class="tabular px-4 py-2 text-end">{{ row.direction === 'out' ? formatNumber(pieces(row.quantity_milli)) : '' }}</td>
                        <td class="tabular px-4 py-2 text-end font-semibold">{{ formatNumber(pieces(row.balance_after_milli)) }}</td>
                        <td class="px-4 py-2">
                            <AppBadge tone="neutral">{{ t(`pharmacy.controlled.sources.${row.source_type}`) }}</AppBadge>
                            <span v-if="row.patient_name" class="ms-1">{{ row.patient_name }}</span>
                            <span v-if="row.prescriber_bmdc" class="ms-1 font-mono text-muted" dir="ltr">BMDC {{ row.prescriber_bmdc }}</span>
                            <span v-if="!row.patient_name && row.note" class="ms-1 text-muted">{{ row.note }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </PageFrame>
</template>
