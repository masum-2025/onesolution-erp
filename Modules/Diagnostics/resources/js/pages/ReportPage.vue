<script setup>
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, FlaskConical, Printer } from 'lucide-vue-next';
import { AppButton, PageFrame } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDate, formatDateTime } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { labApi, rangeText } from '../api';

/**
 * The lab report on its own paper: the company and branch, the patient, the
 * referring doctor, each verified test with its values, units, normal ranges
 * and flags, and who signed. Save as PDF from the print window.
 */
const lab = labApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const view = useResource(() => lab.report(route.params.id));
const report = computed(() => view.data.value?.data ?? null);
const tests = computed(() => (report.value?.tests ?? []).filter((line) => line.amendment > 0));
const printReport = () => window.print();
</script>

<template>
    <PageFrame :title="report ? t('diagnostics.report.title', { number: report.number }) : ''" :resource="view" :not-found="{ icon: FlaskConical, title: t('diagnostics.order.not_found') }" width="lg" own-paper>
        <template #before>
            <AppButton variant="ghost" size="sm" :icon="ArrowLeft" class="print:hidden" @click="router.push({ name: 'diagnostics-order', params: { id: route.params.id } })">{{ t('diagnostics.labels.back') }}</AppButton>
        </template>
        <template v-if="report" #actions>
            <AppButton variant="primary" :icon="Printer" @click="printReport">{{ t('diagnostics.report.print') }}</AppButton>
        </template>
        <article v-if="report" class="mx-auto max-w-[190mm] rounded-xl bg-white p-6 text-[10pt] text-black ring-1 ring-line print:p-0 print:ring-0">
            <header class="mb-4 border-b border-black pb-3 text-center">
                <p class="text-[15pt] font-bold">{{ report.organization }}</p>
                <p v-if="report.branch && report.branch !== report.organization">{{ report.branch }}</p>
                <p class="mt-1 font-semibold">{{ t('diagnostics.report.heading') }}</p>
            </header>
            <dl class="mb-4 grid grid-cols-2 gap-x-6 gap-y-1">
                <div><dt class="inline text-gray-600">{{ t('diagnostics.report.patient') }}:</dt> <dd class="inline font-semibold">{{ report.patient?.name }}</dd></div>
                <div><dt class="inline text-gray-600">{{ t('diagnostics.report.number') }}:</dt> <dd class="inline font-mono" dir="ltr">{{ report.number }}</dd></div>
                <div><dt class="inline text-gray-600">{{ t('diagnostics.report.mrn') }}:</dt> <dd class="inline font-mono" dir="ltr">{{ report.patient?.mrn }}</dd></div>
                <div><dt class="inline text-gray-600">{{ t('diagnostics.report.date') }}:</dt> <dd class="inline">{{ formatDate(report.created_at) }}</dd></div>
                <div><dt class="inline text-gray-600">{{ t('diagnostics.report.age_sex') }}:</dt> <dd class="inline">{{ t('diagnostics.common.years', { count: report.patient?.age ?? '—' }) }} · {{ t(`diagnostics.sex.${report.patient?.sex}`) }}</dd></div>
                <div v-if="report.referrer"><dt class="inline text-gray-600">{{ t('diagnostics.report.referred_by') }}:</dt> <dd class="inline">{{ report.referrer.name }}</dd></div>
            </dl>
            <section v-for="line in tests" :key="line.id" class="mb-5 break-inside-avoid">
                <h2 class="mb-1 border-b border-gray-400 text-[11pt] font-bold">{{ line.name }}<span v-if="line.amended" class="ms-2 text-[8.5pt] font-normal">({{ t('diagnostics.report.amended') }})</span></h2>
                <table class="w-full">
                    <thead><tr class="text-[8.5pt] text-gray-600"><th class="py-0.5 text-start font-medium">{{ t('diagnostics.report.test') }}</th><th class="py-0.5 text-start font-medium">{{ t('diagnostics.report.result') }}</th><th class="py-0.5 text-start font-medium">{{ t('diagnostics.report.unit') }}</th><th class="py-0.5 text-start font-medium">{{ t('diagnostics.report.normal') }}</th></tr></thead>
                    <tbody>
                        <tr v-for="parameter in line.parameters" :key="parameter.id">
                            <td class="py-0.5">{{ parameter.name }}</td>
                            <td class="py-0.5 tabular" :class="parameter.flag && 'font-bold'">{{ parameter.value ?? '—' }}<span v-if="parameter.flag" class="ms-1 text-[8.5pt]">({{ t(`diagnostics.flag_short.${parameter.flag}`) }})</span></td>
                            <td class="py-0.5">{{ parameter.unit }}</td>
                            <td class="py-0.5 tabular">{{ rangeText(parameter.range) }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="mt-1 text-end text-[8.5pt]">{{ t('diagnostics.report.signed', { name: line.verified_by_name ?? '—', time: formatDateTime(line.verified_at) }) }}</p>
            </section>
            <p v-if="!tests.length" class="text-gray-600">{{ t('diagnostics.report.nothing') }}</p>
            <footer class="mt-6 border-t border-gray-400 pt-2 text-[8pt] text-gray-600">{{ t('diagnostics.report.footer') }}</footer>
        </article>
    </PageFrame>
</template>
