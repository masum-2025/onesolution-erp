<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { CheckCircle2, Circle, KanbanSquare } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { formatDate, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { progress, projectTone, taskTone } from '../lib';

/**
 * A client's projects in the company's portal: status, dates and how far
 * along each is (and, when the company shows them, its tasks). Read only;
 * the server sends only the clients linked to the member.
 */
const route = useRoute();
const resource = useResource(() => api('/api/portal/projects', { query: { client: route.params.id } }).then((response) => response.data));
const data = computed(() => resource.data.value);
const projects = computed(() => (data.value?.clients ?? []).flatMap((client) => client.projects.map((project) => ({ ...project, client: client.name }))));
const counts = (project) => ({ total: project.tasks_total, done: project.tasks_done });
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <PageHeader :title="t('projects.portal_page.title')" :description="data ? t('projects.portal_page.text', { org: data.organization }) : ''" />
        <SkeletonRows v-if="resource.loading.value && !data" :rows="4" />
        <ErrorState v-else-if="resource.error.value" :error="resource.error.value" @retry="resource.reload()" />
        <template v-else-if="data">
            <EmptyState v-if="!projects.length" :icon="KanbanSquare" :title="t('projects.portal_page.empty_title')" :text="t('projects.portal_page.empty_text', { org: data.organization })" />
            <section v-for="project in projects" :key="project.id" class="card mb-4 p-5">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h2 class="text-[15px] font-semibold">{{ project.name }}</h2>
                        <p class="mt-0.5 text-[12.5px] text-muted">
                            <span class="font-mono" dir="ltr">{{ project.code }}</span>
                            <template v-if="project.service"> · {{ project.service }}</template>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <AppBadge :tone="projectTone(project.status)" dot>{{ t(`projects.statuses.${project.status}`) }}</AppBadge>
                        <AppBadge v-if="project.late" tone="bad">{{ t('projects.portal_page.late') }}</AppBadge>
                    </div>
                </div>

                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-[12.5px] sm:grid-cols-3">
                    <div>
                        <dt class="text-faint">{{ t('projects.portal_page.starts') }}</dt>
                        <dd>{{ project.starts_on ? formatDate(project.starts_on) : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-faint">{{ t('projects.portal_page.due') }}</dt>
                        <dd>{{ project.due_on ? formatDate(project.due_on) : '—' }}</dd>
                    </div>
                    <div v-if="project.finished_on">
                        <dt class="text-faint">{{ t('projects.portal_page.finished') }}</dt>
                        <dd>{{ formatDate(project.finished_on) }}</dd>
                    </div>
                </dl>

                <div v-if="project.tasks_total" class="mt-4">
                    <div class="mb-1 flex justify-between text-[12.5px] text-muted">
                        <span>{{ t('projects.portal_page.progress', { done: formatNumber(project.tasks_done), total: formatNumber(project.tasks_total) }) }}</span>
                        <span class="tabular">{{ formatNumber(progress(counts(project))) }}%</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-subtle" role="progressbar" :aria-valuenow="progress(counts(project))" aria-valuemin="0" aria-valuemax="100">
                        <span class="block h-full rounded-full bg-brand" :style="{ width: `${progress(counts(project))}%` }" />
                    </div>
                </div>

                <ul v-if="project.tasks?.length" class="mt-4 divide-y divide-line border-t border-line">
                    <li v-for="task in project.tasks" :key="task.id" class="flex items-center gap-3 py-2.5">
                        <CheckCircle2 v-if="task.status === 'done'" class="size-4 shrink-0 text-ok" aria-hidden="true" />
                        <Circle v-else class="size-4 shrink-0 text-faint" aria-hidden="true" />
                        <span class="min-w-0 flex-1 text-[13.5px]" :class="task.status === 'done' ? 'text-muted' : ''" dir="auto">{{ task.title }}</span>
                        <span v-if="task.due_on && task.status !== 'done'" class="text-[12px] text-muted">{{ formatDate(task.due_on) }}</span>
                        <AppBadge :tone="taskTone(task.status)">{{ t(`projects.task_statuses.${task.status}`) }}</AppBadge>
                    </li>
                </ul>
            </section>
        </template>
    </div>
</template>
