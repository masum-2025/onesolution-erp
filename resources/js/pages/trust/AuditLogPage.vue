<script setup>
import { computed, defineAsyncComponent, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ChevronLeft, ChevronRight, LifeBuoy, ScrollText, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import AppTabs from '@/components/AppTabs.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { formatDateTime, formatNumber } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { AUDIT_AREAS, auditQuery } from '@/lib/auditPeriod';
import { t } from '@/lib/i18n';

// Only with advanced_audit (Phase 9-1), loaded when opened.
const AuditReportPanel = defineAsyncComponent(() => import('./AuditReportPanel.vue'));
const AuditExportsPanel = defineAsyncComponent(() => import('./AuditExportsPanel.vue'));

/**
 * The organization's own history: every change, and every step of support
 * access by the service provider (marked). Read-only. With advanced_audit:
 * reports and CSV exports.
 */
const org = currentOrganization();
const route = useRoute();
const router = useRouter();

const tabs = computed(() => [
    { key: 'log', label: t('trust.audit.tabs.log') },
    ...(can('advanced_audit.view') ? [{ key: 'report', label: t('trust.audit.tabs.report') }] : []),
    ...(can('advanced_audit.export') ? [{ key: 'exports', label: t('trust.audit.tabs.exports') }] : []),
]);
const tab = computed({
    get: () => (tabs.value.some((item) => item.key === route.query.tab) ? route.query.tab : 'log'),
    set: (value) => router.replace({ query: value === 'log' ? {} : { tab: value } }),
});

const filters = reactive({ filter: 'all', from: '', to: '', action: 'all' });
const page = ref(1);
const open = ref(null);
const filtering = computed(() => filters.from || filters.to || filters.action !== 'all');

const log = useResource(() =>
    api(`/api/organizations/${org.id}/audit-log`, { query: auditQuery({ ...filters, page: page.value, per_page: 50 }) }),
);

watch(filters, () => {
    page.value = 1;
    log.reload();
});

const entries = computed(() => log.data.value?.data ?? []);
const meta = computed(() => log.data.value?.meta ?? null);
const kinds = computed(() => [
    { value: 'all', label: t('trust.audit.filter.all') },
    { value: 'changes', label: t('trust.audit.filter.changes') },
    { value: 'support', label: t('trust.audit.filter.support') },
]);

function clearFilters() {
    Object.assign(filters, { from: '', to: '', action: 'all' });
}

function go(to) {
    page.value = to;
    log.reload();
}

function details(entry) {
    return [
        entry.reason && [t('trust.audit.reason'), entry.reason],
        entry.old_values && [t('trust.audit.before'), JSON.stringify(entry.old_values, null, 2)],
        entry.new_values && [t('trust.audit.after'), JSON.stringify(entry.new_values, null, 2)],
    ].filter(Boolean);
}
</script>

<template>
    <div>
        <PageHeader :title="t('trust.audit.title')" :description="t('trust.audit.text')" />

        <div v-if="tabs.length > 1" class="mb-5">
            <AppTabs v-model="tab" :tabs="tabs" :label="t('trust.audit.title')" />
        </div>

        <AuditReportPanel v-if="tab === 'report'" />
        <AuditExportsPanel v-else-if="tab === 'exports'" />

        <template v-else>
            <div class="mb-5 flex flex-col gap-4 lg:flex-row lg:items-end">
                <AppSegmented v-model="filters.filter" :options="kinds" :label="t('trust.audit.filter.label')" />
                <div class="grid flex-1 grid-cols-2 gap-3 sm:grid-cols-3 lg:max-w-xl">
                    <AppField v-slot="{ id }" :label="t('trust.audit.filters.from')">
                        <input :id="id" v-model.lazy="filters.from" type="date" class="field-input" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('trust.audit.filters.to')">
                        <input :id="id" v-model.lazy="filters.to" type="date" class="field-input" :min="filters.from || undefined" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('trust.audit.filters.area')" class="col-span-2 sm:col-span-1">
                        <select :id="id" v-model="filters.action" class="field-input">
                            <option value="all">{{ t('trust.audit.filters.all_areas') }}</option>
                            <option v-for="area in AUDIT_AREAS" :key="area" :value="area">{{ t(`trust.audit.areas.${area}`) }}</option>
                        </select>
                    </AppField>
                </div>
                <AppButton v-if="filtering" variant="ghost" :icon="X" @click="clearFilters">{{ t('trust.audit.filters.clear') }}</AppButton>
            </div>

            <section class="card">
                <SkeletonRows v-if="log.loading.value && !log.data.value" :rows="8" />
                <ErrorState v-else-if="log.error.value" compact :error="log.error.value" @retry="log.reload()" />
                <EmptyState
                    v-else-if="!entries.length"
                    :icon="ScrollText"
                    :title="filtering ? t('trust.audit.filters.none_title') : t('trust.audit.empty_title')"
                    :text="filtering ? t('trust.audit.filters.none_text') : t('trust.audit.empty_text')"
                    compact
                />

                <ol v-else class="divide-y divide-line">
                    <li v-for="entry in entries" :key="entry.id">
                        <button
                            type="button"
                            class="flex w-full items-start gap-3 px-4 py-3 text-start transition hover:bg-subtle/60 sm:px-5"
                            :aria-expanded="open === entry.id"
                            @click="open = open === entry.id ? null : entry.id"
                        >
                            <span class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-lg" :class="entry.support ? 'bg-brand-soft text-brand-text' : 'bg-subtle text-muted'" aria-hidden="true">
                                <LifeBuoy v-if="entry.support" class="size-3.5" />
                                <ScrollText v-else class="size-3.5" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-center gap-2 text-[13.5px] font-medium text-fg">
                                    {{ entry.label }}
                                    <AppBadge v-if="entry.support" tone="brand">{{ t('trust.audit.support_badge') }}</AppBadge>
                                    <AppBadge v-if="entry.new_values?.blocked" tone="bad">{{ t('trust.audit.blocked') }}</AppBadge>
                                </span>
                                <span class="mt-0.5 block text-[12.5px] text-muted">
                                    {{ entry.actor?.name ?? t('trust.audit.system') }} · {{ entry.organization?.name }}
                                    <template v-if="entry.new_values?.path"> · <span class="font-mono" dir="ltr">{{ entry.new_values.method }} {{ entry.new_values.path }}</span></template>
                                </span>
                            </span>
                            <time class="shrink-0 text-[12px] text-faint" :datetime="entry.created_at">{{ formatDateTime(entry.created_at) }}</time>
                        </button>
                        <dl v-if="open === entry.id && details(entry).length" class="space-y-2 bg-subtle/50 px-5 pb-4 ps-14 text-[12.5px]">
                            <div v-for="[label, value] in details(entry)" :key="label">
                                <dt class="font-medium text-fg-2">{{ label }}</dt>
                                <dd class="mt-0.5 overflow-x-auto font-mono whitespace-pre-wrap text-muted" dir="ltr">{{ value }}</dd>
                            </div>
                        </dl>
                    </li>
                </ol>

                <footer v-if="meta && meta.last_page > 1" class="flex items-center justify-between border-t border-line px-5 py-3">
                    <span class="tabular text-[12.5px] text-muted">{{ t('trust.audit.page', { page: formatNumber(meta.current_page), pages: formatNumber(meta.last_page) }) }}</span>
                    <div class="flex gap-2">
                        <AppButton size="sm" :icon="ChevronLeft" :disabled="meta.current_page <= 1" @click="go(meta.current_page - 1)">{{ t('core.actions.previous') }}</AppButton>
                        <AppButton size="sm" :icon-end="ChevronRight" :disabled="meta.current_page >= meta.last_page" @click="go(meta.current_page + 1)">{{ t('core.actions.next') }}</AppButton>
                    </div>
                </footer>
            </section>
        </template>
    </div>
</template>
