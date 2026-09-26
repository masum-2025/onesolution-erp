<script setup>
import { computed, ref, watch } from 'vue';
import { ChevronLeft, ChevronRight, LifeBuoy, ScrollText } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { formatDateTime, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';

/**
 * The organization's own history: every change, and every step of support
 * access by the service provider (marked). Read-only.
 */
const org = currentOrganization();
const filter = ref('all');
const page = ref(1);
const open = ref(null);

const log = useResource(() =>
    api(`/api/organizations/${org.id}/audit-log`, { query: { page: page.value, per_page: 50, filter: filter.value === 'all' ? undefined : filter.value } }),
);

watch(filter, () => {
    page.value = 1;
    log.reload();
});

const entries = computed(() => log.data.value?.data ?? []);
const meta = computed(() => log.data.value?.meta ?? null);
const filters = computed(() => [
    { value: 'all', label: t('trust.audit.filter.all') },
    { value: 'changes', label: t('trust.audit.filter.changes') },
    { value: 'support', label: t('trust.audit.filter.support') },
]);

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

        <div class="mb-5">
            <AppSegmented v-model="filter" :options="filters" :label="t('trust.audit.filter.label')" />
        </div>

        <section class="card">
            <SkeletonRows v-if="log.loading.value && !log.data.value" :rows="8" />
            <ErrorState v-else-if="log.error.value" compact :error="log.error.value" @retry="log.reload()" />
            <EmptyState v-else-if="!entries.length" :icon="ScrollText" :title="t('trust.audit.empty_title')" :text="t('trust.audit.empty_text')" compact />

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
    </div>
</template>
