<script setup>
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, FlaskConical, Printer } from 'lucide-vue-next';
import { AppButton, PageFrame } from '@/ui';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { labApi } from '../api';
import ReportPaper from '../components/ReportPaper.vue';

/**
 * The lab report on its own paper (verified values only). Save as PDF from
 * the print window.
 */
const lab = labApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const view = useResource(() => lab.report(route.params.id));
const report = computed(() => view.data.value?.data ?? null);
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
        <ReportPaper v-if="report" :report="report" />
    </PageFrame>
</template>
