<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { ShieldCheck } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { formatDate, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { STATES, stateTone } from '../lib';

/**
 * A factory's audit register in the client's portal (a buyer or an auditor
 * watching it): read only, each line with its standing and days left. The
 * server sends only the factories linked to the member, without notes.
 */
const route = useRoute();
const resource = useResource(() => api('/api/portal/compliance', { query: { factory: route.params.id } }).then((response) => response.data));
const data = computed(() => resource.data.value);
const units = computed(() => (data.value?.factories ?? []).flatMap((factory) => factory.units));
const daysText = (days) => (days === null ? t('compliance.days.none') : days < 0 ? t('compliance.days.ago', { count: -days }) : days === 0 ? t('compliance.days.today') : t('compliance.days.left', { count: days }));
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <PageHeader :title="t('compliance.portal_page.title')" :description="data ? t('compliance.portal_page.text', { org: data.organization }) : ''" />
        <SkeletonRows v-if="resource.loading.value && !data" :rows="4" />
        <ErrorState v-else-if="resource.error.value" :error="resource.error.value" @retry="resource.reload()" />
        <template v-else-if="data">
            <section v-for="unit in units" :key="unit.id" class="card mb-5 overflow-hidden">
                <header class="border-b border-line px-5 py-4">
                    <h2 class="text-[15px] font-semibold">{{ unit.name }}</h2>
                    <ul class="mt-2 flex flex-wrap gap-2" :aria-label="t('compliance.portal_page.summary')">
                        <li v-for="state in STATES.filter((key) => unit.summary[key] > 0)" :key="state">
                            <AppBadge :tone="stateTone(state)" dot>{{ t(`compliance.states.${state}`) }}: <span class="tabular">{{ formatNumber(unit.summary[state]) }}</span></AppBadge>
                        </li>
                    </ul>
                </header>

                <p v-if="!unit.lines.length" class="px-5 py-6 text-[13.5px] text-muted">{{ t('compliance.portal_page.no_lines') }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="line in unit.lines" :key="line.id" class="grid grid-cols-1 gap-2 px-5 py-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start">
                        <div class="min-w-0">
                            <div class="text-[14px] font-medium">{{ line.service?.name ?? '—' }}</div>
                            <div class="mt-0.5 text-[12.5px] text-muted">
                                <template v-if="line.audit_type">{{ line.audit_type }}</template>
                                <template v-if="line.audit_type && line.provider"> · </template>
                                <template v-if="line.provider">{{ line.provider }}</template>
                            </div>
                            <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-[12.5px] sm:grid-cols-4">
                                <div>
                                    <dt class="text-faint">{{ t('compliance.fields.last_audit_on') }}</dt>
                                    <dd>{{ line.last_audit_on ? formatDate(line.last_audit_on) : '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-faint">{{ t('compliance.fields.expires_on') }}</dt>
                                    <dd>{{ line.expires_on ? formatDate(line.expires_on) : '—' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-faint">{{ t('compliance.fields.result') }}</dt>
                                    <dd dir="auto">{{ line.result ?? '—' }}</dd>
                                </div>
                                <div v-if="line.certificate_no">
                                    <dt class="text-faint">{{ t('compliance.fields.certificate_no') }}</dt>
                                    <dd class="truncate font-mono" dir="ltr">{{ line.certificate_no }}</dd>
                                </div>
                            </dl>
                        </div>
                        <div class="flex items-center gap-2 sm:flex-col sm:items-end">
                            <AppBadge :tone="stateTone(line.state)" dot>{{ t(`compliance.states.${line.state}`) }}</AppBadge>
                            <span class="text-[12.5px] text-muted">{{ daysText(line.days_left) }}</span>
                        </div>
                    </li>
                </ul>
            </section>
            <EmptyState v-if="!units.length" :icon="ShieldCheck" :title="t('compliance.portal_page.empty_title')" :text="t('compliance.portal_page.empty_text', { org: data.organization })" />
        </template>
    </div>
</template>
