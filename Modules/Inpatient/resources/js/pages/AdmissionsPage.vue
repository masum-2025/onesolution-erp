<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { BedDouble, ChevronRight, Plus } from 'lucide-vue-next';
import { AppButton, AppSegmented, PageFrame } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDateTime } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { ipdApi } from '../api';

/** Admitted patients (or discharged, cancelled): number, patient, bed, doctor, days so far. A doctor limited to their own sees only theirs. */
const ipd = ipdApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const statuses = ['admitted', 'discharged', 'cancelled'];
const status = ref(statuses.includes(route.query.status) ? route.query.status : 'admitted');
const list = useResource(() => ipd.admissions({ status: status.value }));
watch(status, () => {
    router.replace({ query: status.value === 'admitted' ? {} : { status: status.value } });
    list.reload();
});
const rows = computed(() => list.data.value?.data ?? []);
const can = computed(() => list.data.value?.meta?.can ?? {});
const choices = statuses.map((value) => ({ value, label: t(`inpatient.status.${value}`) }));
const exported = computed(() => ({
    module: 'inpatient', page: 'inpatient.admissions', rows: rows.value,
    columns: [
        { key: 'number', label: t('inpatient.admissions.number') },
        { key: 'patient', label: t('inpatient.admissions.patient'), value: (row) => `${row.patient?.mrn ?? ''} ${row.patient?.name ?? ''}` },
        { key: 'bed', label: t('inpatient.admissions.bed'), value: (row) => (row.bed ? `${row.bed.ward} ${row.bed.code}` : '') },
        { key: 'doctor', label: t('inpatient.admissions.doctor'), value: (row) => row.doctor?.name ?? '' },
        { key: 'admitted_at', label: t('inpatient.admissions.admitted_at'), type: 'date' },
        { key: 'bed_days', label: t('inpatient.admissions.days') },
    ],
}));
</script>

<template>
    <PageFrame :title="t('inpatient.admissions.title')" :description="t('inpatient.admissions.text')" width="lg" :resource="list" :export="exported" guide="inpatient.guide.admissions"
               :empty="{ when: !rows.length, icon: BedDouble, title: t('inpatient.admissions.empty') }">
        <template v-if="can.admit" #actions>
            <AppButton variant="primary" :icon="Plus" @click="router.push({ name: 'inpatient-admit' })">{{ t('inpatient.admit.title') }}</AppButton>
        </template>
        <template #top>
            <AppSegmented v-model="status" :options="choices" :label="t('inpatient.admissions.status')" class="mb-4" />
        </template>
        <ul class="card divide-y divide-line">
            <li v-for="row in rows" :key="row.id">
                <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-start hover:bg-subtle" @click="router.push({ name: 'inpatient-admission', params: { id: row.id } })">
                    <span class="min-w-0 flex-1">
                        <span class="block text-[14px] font-medium"><span class="font-mono" dir="ltr">{{ row.number }}</span> · {{ row.patient?.name ?? '—' }}</span>
                        <span class="block text-[12.5px] text-muted">{{ row.bed?.ward }} {{ row.bed?.code }} · {{ row.doctor?.name }} · {{ formatDateTime(row.admitted_at) }} · {{ t('inpatient.admissions.day_count', { count: row.bed_days }) }}</span>
                    </span>
                    <ChevronRight class="size-4 shrink-0 text-muted rtl:rotate-180" aria-hidden="true" />
                </button>
            </li>
        </ul>
    </PageFrame>
</template>
