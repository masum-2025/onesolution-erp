<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { FolderKanban, LayoutTemplate, ListTodo, Plus, Search } from 'lucide-vue-next';
import { AppBadge, AppButton, EmptyState, ErrorState, PageFrame, SkeletonRows } from '@/ui';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { formatDate, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { projectsApi } from '../api';
import { progress, projectTone } from '../lib';
import ProjectDialog from '../components/ProjectDialog.vue';
import RequestsSection from '../components/RequestsSection.vue';

/**
 * Projects at this unit and below: counts by status (each opens its own),
 * a search, and each project with its client, manager, due day and how
 * many of its tasks are done. The filters stay in the address.
 */
const org = currentOrganization();
const calls = projectsApi(org.id);
const route = useRoute();
const router = useRouter();

const status = ref(route.query.status ?? '');
const q = ref(route.query.q ?? '');
const options = useResource(() => calls.options());
const list = useResource(() => calls.list({ status: status.value || undefined, q: q.value.trim() || undefined }));
const opts = computed(() => options.data.value?.data ?? null);
const projects = computed(() => list.data.value?.data ?? []);
const summary = computed(() => list.data.value?.meta?.summary ?? {});
const STATUSES = ['planned', 'active', 'on_hold', 'done', 'cancelled'];

let timer;
watch([status, q], () => {
    router.replace({ query: { ...(status.value ? { status: status.value } : {}), ...(q.value.trim() ? { q: q.value.trim() } : {}) } });
    clearTimeout(timer);
    timer = setTimeout(() => list.reload(), 250);
});

const opening = ref(false);
function opened(project) {
    opening.value = false;
    router.push({ name: 'project', params: { id: project.id } });
}

// Export and print: the projects the filters show, one row each.
const exported = computed(() => ({
    module: 'projects',
    page: 'projects.list',
    columns: [
        { key: 'code', label: t('projects.export.code') },
        { key: 'name', label: t('projects.export.name') },
        { key: 'client', label: t('projects.export.client'), value: (project) => project.client?.name },
        { key: 'manager', label: t('projects.export.manager'), value: (project) => project.manager?.name },
        { key: 'unit', label: t('projects.export.unit'), value: (project) => project.unit?.name },
        { key: 'status', label: t('projects.export.status'), value: (project) => t(`projects.statuses.${project.status}`) },
        { key: 'due_on', label: t('projects.export.due_on'), type: 'date' },
        { key: 'done', label: t('projects.export.tasks_done'), type: 'number', value: (project) => project.tasks?.done },
        { key: 'total', label: t('projects.export.tasks_total'), type: 'number', value: (project) => project.tasks?.total },
    ],
    rows: projects.value,
}));
</script>

<template>
    <PageFrame :title="t('projects.list.title')" :description="t('projects.list.text')" width="full" :export="exported">
        <template #actions>
            <span class="contents print:hidden">
                <AppButton v-if="opts?.can.templates" variant="ghost" :icon="LayoutTemplate" :to="{ name: 'projects-templates' }">{{ t('projects.templates.title') }}</AppButton>
                <AppButton :icon="ListTodo" :to="{ name: 'projects-mine' }">{{ t('projects.mine.title') }}</AppButton>
                <AppButton v-if="opts?.can.open" variant="primary" :icon="Plus" @click="opening = true">{{ t('projects.dialog.title') }}</AppButton>
            </span>
        </template>

        <p v-if="opts?.reads_only" class="mb-4 rounded-xl border border-line bg-subtle px-4 py-3 text-[13px] text-fg-2">{{ t('projects.list.group_reads') }}</p>

        <RequestsSection v-if="opts" class="print:hidden" :options="opts" :calls="calls" :highlight="route.query.requests === '1'" @changed="list.reload()" />

        <section class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-5" :aria-label="t('projects.list.summary')">
            <button v-for="key in STATUSES" :key="key" type="button" class="card px-4 py-3 text-start transition hover:bg-subtle/60"
                :class="status === key ? 'ring-2 ring-brand' : ''" :aria-pressed="status === key" @click="status = status === key ? '' : key">
                <AppBadge :tone="projectTone(key)">{{ t(`projects.statuses.${key}`) }}</AppBadge>
                <span class="mt-2 block text-[22px] font-semibold tabular">{{ formatNumber(summary[key] ?? 0) }}</span>
            </button>
        </section>

        <div class="relative mb-4 print:hidden sm:w-80">
            <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
            <label class="sr-only" for="project-search">{{ t('projects.list.search') }}</label>
            <input id="project-search" v-model="q" type="search" class="field-input ps-9" :placeholder="t('projects.list.search')" maxlength="100" />
        </div>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!projects.length" :icon="FolderKanban" compact
                :title="status || q ? t('projects.list.none_title') : t('projects.list.empty_title')" :text="status || q ? t('projects.list.none_text') : t('projects.list.empty_text')">
                <AppButton v-if="!status && !q && opts?.can.open" variant="primary" :icon="Plus" @click="opening = true">{{ t('projects.dialog.title') }}</AppButton>
            </EmptyState>
            <ul v-else class="divide-y divide-line">
                <li v-for="project in projects" :key="project.id">
                    <RouterLink :to="{ name: 'project', params: { id: project.id } }" class="grid grid-cols-1 gap-2 px-5 py-3.5 transition hover:bg-subtle/60 sm:grid-cols-[minmax(0,1fr)_12rem_auto] sm:items-center">
                        <span class="min-w-0">
                            <span class="block truncate text-[14px] font-medium text-fg"><span class="font-mono text-[12.5px] text-muted" dir="ltr">{{ project.code }}</span> · {{ project.name }}</span>
                            <span class="block truncate text-[12.5px] text-muted">
                                {{ project.client.name }}<template v-if="project.manager"> · {{ project.manager.name }}</template><template v-if="project.unit.type === 'branch'"> · {{ project.unit.name }}</template>
                            </span>
                        </span>
                        <span class="flex items-center gap-2" :aria-label="t('projects.list.progress', { done: project.tasks.done, total: project.tasks.total })">
                            <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-subtle"><span class="block h-full rounded-full bg-brand" :style="{ width: `${progress(project.tasks)}%` }" /></span>
                            <span class="tabular text-[12px] text-muted">{{ formatNumber(project.tasks.done) }}/{{ formatNumber(project.tasks.total) }}</span>
                        </span>
                        <span class="flex flex-wrap items-center gap-2 sm:justify-end">
                            <span v-if="project.due_on" class="text-[12px]" :class="project.late ? 'font-medium text-bad' : 'text-muted'">{{ project.late ? t('projects.list.late', { date: formatDate(project.due_on) }) : formatDate(project.due_on) }}</span>
                            <AppBadge v-if="project.tasks.overdue" tone="bad">{{ t('projects.list.overdue_tasks', { count: project.tasks.overdue, tasks: formatNumber(project.tasks.overdue) }) }}</AppBadge>
                            <AppBadge :tone="projectTone(project.status)">{{ t(`projects.statuses.${project.status}`) }}</AppBadge>
                        </span>
                    </RouterLink>
                </li>
            </ul>
        </section>

        <ProjectDialog :open="opening" :options="opts" :calls="calls" @close="opening = false" @saved="opened" />
    </PageFrame>
</template>
