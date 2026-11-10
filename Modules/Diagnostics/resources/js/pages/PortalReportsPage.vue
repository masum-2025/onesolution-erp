<script setup>
import { computed } from 'vue';
import { FlaskConical } from 'lucide-vue-next';
import { PageFrame } from '@/ui';
import AppBadge from '@/components/AppBadge.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatDateTime } from '@/lib/format';
import { t } from '@/lib/i18n';
import { flagTones, portalReports, rangeText } from '../api';

/**
 * The portal: a patient's (or a parent's) own verified lab reports.
 */
const view = useResource(() => portalReports());
const data = computed(() => view.data.value?.data ?? null);
const reports = computed(() => data.value?.reports ?? []);
</script>

<template>
    <PageFrame :title="t('diagnostics.portal.title')" :description="data ? t('diagnostics.portal.text', { org: data.organization }) : ''" :resource="view" width="md"
               :empty="{ when: !!data && !reports.length, icon: FlaskConical, title: t('diagnostics.portal.empty'), text: t('diagnostics.portal.empty_text') }">
        <section v-for="report in reports" :key="report.id" class="card mb-4">
            <header class="border-b border-line px-5 py-3">
                <p class="font-mono text-[14px] font-semibold" dir="ltr">{{ report.number }}</p>
                <p class="text-[12.5px] text-muted">{{ report.patient?.name }} · {{ formatDate(report.created_at) }}</p>
            </header>
            <div v-for="line in report.tests.filter((item) => item.amendment > 0)" :key="line.id" class="border-b border-line px-5 py-3 last:border-0">
                <p class="mb-1 text-[13.5px] font-semibold">{{ line.name }}</p>
                <ul class="grid gap-1 text-[13px]">
                    <li v-for="parameter in line.parameters" :key="parameter.id" class="flex flex-wrap items-center justify-between gap-2">
                        <span>{{ parameter.name }}</span>
                        <span class="flex items-center gap-2 tabular"><strong>{{ parameter.value ?? '—' }}</strong> {{ parameter.unit }}<AppBadge v-if="parameter.flag" :tone="flagTones[parameter.flag]">{{ t(`diagnostics.flag.${parameter.flag}`) }}</AppBadge></span>
                        <span v-if="rangeText(parameter.range)" class="w-full text-[11.5px] text-muted">{{ t('diagnostics.order.normal', { range: rangeText(parameter.range) }) }}</span>
                    </li>
                </ul>
                <p class="mt-1 text-[11.5px] text-muted">{{ t('diagnostics.report.signed', { name: line.verified_by_name ?? '—', time: formatDateTime(line.verified_at) }) }}</p>
            </div>
        </section>
    </PageFrame>
</template>
