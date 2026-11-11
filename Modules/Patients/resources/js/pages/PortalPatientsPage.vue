<script setup>
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { FileText, FlaskConical, HeartPulse, History, ReceiptText } from 'lucide-vue-next';
import { AppButton } from '@/ui';
import { EmptyState, PageFrame } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { portalPatients } from '../api';

/**
 * The portal: a patient (or a parent) sees their own record and history,
 * nothing else.
 */
const router = useRouter();
// The bills page exists only while Patient billing is on for this organization.
const hasBills = router.hasRoute('patient-billing-portal');
const hasLab = router.hasRoute('diagnostics-portal');
const hasPrescriptions = router.hasRoute('outpatient-portal');
const view = useResource(() => portalPatients());
const data = computed(() => view.data.value?.data ?? null);
const patients = computed(() => data.value?.patients ?? []);
</script>

<template>
    <PageFrame :title="t('patients.portal.title')" :description="data ? t('patients.portal.text', { org: data.organization }) : ''" :resource="view" width="md"
               :empty="{ when: !!data && !patients.length, icon: HeartPulse, title: t('patients.portal.empty'), text: t('patients.portal.empty_text') }">
        <template v-if="(hasBills || hasLab || hasPrescriptions) && patients.length" #actions>
            <AppButton v-if="hasPrescriptions" :icon="FileText" @click="router.push({ name: 'outpatient-portal' })">{{ t('patients.portal.prescriptions') }}</AppButton>
            <AppButton v-if="hasLab" :icon="FlaskConical" @click="router.push({ name: 'diagnostics-portal' })">{{ t('patients.portal.lab') }}</AppButton>
            <AppButton v-if="hasBills" :icon="ReceiptText" @click="router.push({ name: 'patient-billing-portal' })">{{ t('patients.portal.bills') }}</AppButton>
        </template>
        <section v-for="patient in patients" :key="patient.id" class="card mb-4">
            <header class="border-b border-line px-5 py-3">
                <h2 class="text-[15px] font-semibold">{{ patient.name }}</h2>
                <p class="text-[12.5px] text-muted"><span class="font-mono" dir="ltr">{{ patient.mrn }}</span> · {{ t(`patients.sex.${patient.sex}`) }} · {{ t('patients.common.years', { count: patient.age ?? '—' }) }}<template v-if="patient.blood_group"> · <span dir="ltr">{{ patient.blood_group }}</span></template></p>
            </header>
            <EmptyState v-if="!patient.history.length" :icon="History" :title="t('patients.patient.history_empty')" compact />
            <ol v-else class="divide-y divide-line">
                <li v-for="entry in patient.history" :key="entry.id" class="flex gap-3 px-5 py-3 text-[13.5px]">
                    <span class="w-28 shrink-0 text-[12px] text-muted tabular">{{ formatDateTime(entry.happened_at) }}</span>
                    <span class="min-w-0 flex-1">{{ entry.title }}</span>
                </li>
            </ol>
        </section>
    </PageFrame>
</template>
