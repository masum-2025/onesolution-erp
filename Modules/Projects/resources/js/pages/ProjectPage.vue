<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, CirclePause, CirclePlay, CircleCheck, PenLine, Plus, RotateCcw, XCircle } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { projectsApi } from '../api';
import { TASK_STATUSES, columns, progress, projectTone } from '../lib';
import MoneySection from '../components/MoneySection.vue';
import ProjectDialog from '../components/ProjectDialog.vue';
import TaskCard from '../components/TaskCard.vue';
import TaskDialog from '../components/TaskDialog.vue';
import TaskDrawer from '../components/TaskDrawer.vue';

/**
 * One project: its client, manager, days and status with the next steps,
 * and its tasks on a board (one column per status; grouped on phones).
 * A task opens in a drawer; ?task= in the address opens it directly.
 */
const org = currentOrganization();
const calls = projectsApi(org.id);
const route = useRoute();
const router = useRouter();

const options = useResource(() => calls.options());
const data = useResource(() => calls.project(route.params.id));
const opts = computed(() => options.data.value?.data ?? null);
const project = computed(() => data.data.value?.data ?? null);
const board = computed(() => columns(project.value?.task_list ?? []));

const openTaskId = ref(route.query.task ?? null);
const openTask = computed(() => project.value?.task_list.find((task) => task.id === openTaskId.value) ?? null);
watch(openTaskId, (id) => router.replace({ query: id ? { task: id } : {} }));

const editing = ref(false);
const taskDialog = ref({ open: false, task: null });
const busy = ref(false);

const STEP_ICONS = { start: CirclePlay, hold: CirclePause, resume: CirclePlay, finish: CircleCheck, cancel: XCircle, reopen: RotateCcw };
async function step(name) {
    const answer = await confirmAction({
        title: t(`projects.steps.${name}_title`, { code: project.value.code }),
        message: t(`projects.steps.${name}_text`),
        confirmLabel: t(`projects.steps.${name}`),
        danger: name === 'cancel',
        reason: ['hold', 'cancel', 'reopen'].includes(name) ? 'required' : 'none',
    });
    if (!answer) return;
    busy.value = true;
    try {
        await calls.step(project.value.id, name, project.value.version, answer.reason);
        toast.success(t(`projects.steps.${name}_done`));
        data.reload();
    } catch (error) {
        toast.error(error.message);
        if (error.code === 'version_conflict' || error.code === 'wrong_step') data.reload();
    } finally {
        busy.value = false;
    }
}

// A task changed in the drawer: put it back on the board (or reload when it moved under us).
function taskChanged(task) {
    if (!task) return data.reload();
    const list = project.value.task_list;
    const index = list.findIndex((item) => item.id === task.id);
    if (index >= 0) list.splice(index, 1, task);
    data.reload();
}
function taskSaved(task) {
    taskDialog.value = { open: false, task: null };
    data.reload();
    openTaskId.value = task.id;
}
</script>

<template>
    <div>
        <SkeletonRows v-if="data.loading.value && !project" :rows="6" />
        <ErrorState v-else-if="data.error.value" :error="data.error.value" @retry="data.reload()" />
        <template v-else-if="project">
            <PageHeader :title="project.name">
                <template #eyebrow>
                    <p class="mb-1 flex flex-wrap items-center gap-2 text-[12.5px] text-muted">
                        <RouterLink :to="{ name: 'projects' }" class="inline-flex items-center gap-1 hover:text-fg"><ArrowLeft class="size-3.5 rtl:rotate-180" aria-hidden="true" />{{ t('projects.list.title') }}</RouterLink>
                        <span class="font-mono" dir="ltr">{{ project.code }}</span>
                        <AppBadge :tone="projectTone(project.status)">{{ t(`projects.statuses.${project.status}`) }}</AppBadge>
                    </p>
                </template>
                <template #actions>
                    <AppButton v-if="project.can.edit" variant="ghost" :icon="PenLine" @click="editing = true">{{ t('projects.common.edit') }}</AppButton>
                    <AppButton v-for="name in project.can.steps" :key="name" :variant="name === 'cancel' ? 'ghost' : 'secondary'" :class="name === 'cancel' ? 'text-bad' : ''"
                        :icon="STEP_ICONS[name]" :disabled="busy" @click="step(name)">{{ t(`projects.steps.${name}`) }}</AppButton>
                </template>
            </PageHeader>

            <section class="card mb-5 grid grid-cols-2 gap-x-6 gap-y-3 px-5 py-4 text-[13.5px] lg:grid-cols-5">
                <div><p class="text-[12px] text-muted">{{ t('projects.fields.client') }}</p><p class="font-medium">{{ project.client.name }}</p></div>
                <div><p class="text-[12px] text-muted">{{ t('projects.fields.manager') }}</p><p class="font-medium">{{ project.manager?.name ?? '—' }}</p></div>
                <div><p class="text-[12px] text-muted">{{ t('projects.fields.service') }}</p><p class="font-medium">{{ project.service ?? '—' }}</p></div>
                <div>
                    <p class="text-[12px] text-muted">{{ t('projects.fields.days') }}</p>
                    <p class="font-medium">{{ formatDate(project.starts_on) }} → <span :class="project.late ? 'text-bad' : ''">{{ project.due_on ? formatDate(project.due_on) : '—' }}</span></p>
                </div>
                <div class="col-span-2 lg:col-span-1">
                    <p class="text-[12px] text-muted">{{ t('projects.fields.progress') }}</p>
                    <p class="flex items-center gap-2">
                        <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-subtle"><span class="block h-full rounded-full bg-brand" :style="{ width: `${progress(project.tasks)}%` }" /></span>
                        <span class="tabular text-[12.5px]">{{ formatNumber(project.tasks.done) }}/{{ formatNumber(project.tasks.total) }}</span>
                    </p>
                </div>
                <p v-if="project.status_reason" class="col-span-2 rounded-lg bg-subtle px-3 py-2 text-[13px] lg:col-span-5">{{ t(`projects.reason.${project.status}`, { reason: project.status_reason }) }}</p>
                <p v-if="project.description" class="col-span-2 whitespace-pre-line text-fg-2 lg:col-span-5">{{ project.description }}</p>
            </section>

            <MoneySection :project="project" :today="opts?.today ?? ''" :calls="calls" @client-changed="data.reload()" />

            <div class="mb-3 flex items-center justify-between gap-3">
                <h2 class="text-[14.5px] font-semibold">{{ t('projects.board.title') }}</h2>
                <AppButton v-if="project.can.tasks" variant="primary" :icon="Plus" @click="taskDialog = { open: true, task: null }">{{ t('projects.task.add') }}</AppButton>
            </div>
            <p v-if="!project.task_list.length" class="card px-5 py-6 text-center text-[13.5px] text-muted">{{ t(project.can.tasks ? 'projects.board.empty' : 'projects.board.empty_read') }}</p>
            <!-- Wide screens: a column per status. Phones: the same, one under another. -->
            <div v-else class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <section v-for="status in TASK_STATUSES" :key="status" class="rounded-2xl bg-subtle/70 p-3" :aria-label="t(`projects.task_statuses.${status}`)">
                    <h3 class="mb-2 flex items-center justify-between px-1 text-[13px] font-semibold text-fg-2">
                        {{ t(`projects.task_statuses.${status}`) }}<span class="tabular text-[12px] font-normal text-muted">{{ formatNumber(board[status].length) }}</span>
                    </h3>
                    <p v-if="!board[status].length" class="px-1 py-2 text-[12.5px] text-faint">{{ t('projects.board.column_empty') }}</p>
                    <div v-else class="space-y-2">
                        <TaskCard v-for="task in board[status]" :key="task.id" :task="task" @open="openTaskId = task.id" />
                    </div>
                </section>
            </div>
        </template>

        <ProjectDialog :open="editing" :project="project" :options="opts" :calls="calls" @close="editing = false" @saved="editing = false; data.reload()" />
        <TaskDialog v-if="project" :open="taskDialog.open" :project-id="project.id" :task="taskDialog.task" :options="opts" :calls="calls" @close="taskDialog = { open: false, task: null }" @saved="taskSaved" />
        <TaskDrawer v-if="project" :open="!!openTask && !taskDialog.open" :task="openTask" :project-id="project.id" :options="opts" :calls="calls"
            @close="openTaskId = null" @changed="taskChanged" @edit="(task) => (taskDialog = { open: true, task })" />
    </div>
</template>
