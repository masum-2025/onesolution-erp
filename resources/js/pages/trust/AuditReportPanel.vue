<script setup>
import { computed, ref, watch } from 'vue';
import { BarChart3 } from 'lucide-vue-next';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { formatDate, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { lastDays } from '@/lib/auditPeriod';
import { t } from '@/lib/i18n';

/**
 * advanced_audit report (Phase 9-1): how much happened, when, what and by
 * whom, over the last 7, 30 or 90 days of this organization and its units.
 */
const org = currentOrganization();
// Calendar days (no time): shown as written, whatever the viewer's time zone.
const DAY = { dateStyle: 'medium', timeZone: 'UTC' };
const days = ref('30');
const report = useResource(() =>
    api(`/api/organizations/${org.id}/audit-log/report`, { query: lastDays(Number(days.value)) }).then((response) => response.data),
);
watch(days, () => report.reload());

const data = computed(() => report.data.value);
const busiest = computed(() => Math.max(1, ...(data.value?.days ?? []).map((day) => day.count)));
const periods = computed(() => ['7', '30', '90'].map((value) => ({ value, label: t('trust.audit.report.days', { days: formatNumber(Number(value)) }) })));
const tiles = computed(() => [
    ['total', data.value?.total],
    ['people', data.value?.people],
    ['support', data.value?.support],
    ['money', data.value?.money],
]);
</script>

<template>
    <div class="space-y-5">
        <AppSegmented v-model="days" :options="periods" :label="t('trust.audit.report.period')" />

        <section v-if="report.loading.value && !data" class="card"><SkeletonRows :rows="6" /></section>
        <section v-else-if="report.error.value" class="card"><ErrorState compact :error="report.error.value" @retry="report.reload()" /></section>
        <section v-else-if="data && !data.total" class="card">
            <EmptyState :icon="BarChart3" :title="t('trust.audit.report.empty_title')" :text="t('trust.audit.report.empty_text')" compact />
        </section>

        <template v-else-if="data">
            <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div v-for="[key, value] in tiles" :key="key" class="card px-4 py-3">
                    <dt class="text-[12px] text-muted">{{ t(`trust.audit.report.${key}`) }}</dt>
                    <dd class="tabular mt-1 text-[20px] font-semibold text-fg">{{ formatNumber(value ?? 0) }}</dd>
                </div>
            </dl>

            <section class="card px-5 py-4">
                <h2 class="text-[13.5px] font-semibold text-fg">{{ t('trust.audit.report.per_day') }}</h2>
                <ol class="mt-3 space-y-1">
                    <li v-for="day in data.days" :key="day.date" class="flex items-center gap-3 text-[12px]">
                        <span class="w-24 shrink-0 text-muted">{{ formatDate(day.date, DAY) }}</span>
                        <span class="h-2.5 flex-1 overflow-hidden rounded-full bg-subtle" aria-hidden="true">
                            <span class="block h-full rounded-full bg-brand" :style="{ inlineSize: `${(day.count / busiest) * 100}%` }" />
                        </span>
                        <span class="tabular w-10 shrink-0 text-end text-fg-2">{{ formatNumber(day.count) }}</span>
                    </li>
                </ol>
            </section>

            <div class="grid gap-5 lg:grid-cols-2">
                <section class="card px-5 py-4">
                    <h2 class="text-[13.5px] font-semibold text-fg">{{ t('trust.audit.report.top_actions') }}</h2>
                    <ol class="mt-3 divide-y divide-line text-[13px]">
                        <li v-for="item in data.actions" :key="item.action" class="flex justify-between gap-3 py-2">
                            <span class="min-w-0 truncate text-fg-2">{{ item.label }}</span>
                            <span class="tabular text-muted">{{ formatNumber(item.count) }}</span>
                        </li>
                    </ol>
                </section>
                <section class="card px-5 py-4">
                    <h2 class="text-[13.5px] font-semibold text-fg">{{ t('trust.audit.report.top_people') }}</h2>
                    <ol class="mt-3 divide-y divide-line text-[13px]">
                        <li v-for="item in data.actors" :key="item.id" class="flex justify-between gap-3 py-2">
                            <span class="min-w-0 truncate text-fg-2">{{ item.name ?? t('trust.audit.system') }}</span>
                            <span class="tabular text-muted">{{ formatNumber(item.count) }}</span>
                        </li>
                        <li v-if="!data.actors.length" class="py-2 text-muted">{{ t('trust.audit.report.no_people') }}</li>
                    </ol>
                </section>
            </div>
        </template>
    </div>
</template>
