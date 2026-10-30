<script setup>
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { ListTodo } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { projectsApi } from '../api';
import { groupMine } from '../lib';
import TaskCard from '../components/TaskCard.vue';
import TaskDrawer from '../components/TaskDrawer.vue';

/**
 * Everything the signed-in person is on, in open projects: overdue first,
 * then this week, later, and with no due day. Done ones on request.
 */
const org = currentOrganization();
const calls = projectsApi(org.id);
const router = useRouter();
const withDone = ref(false);
const options = useResource(() => calls.options());
const list = useResource(() => calls.mine(withDone.value));
watch(withDone, () => list.reload());
const opts = computed(() => options.data.value?.data ?? null);
const tasks = computed(() => list.data.value?.data ?? []);
const groups = computed(() => groupMine(tasks.value, opts.value?.today ?? new Date().toISOString().slice(0, 10)));
const GROUPS = ['overdue', 'week', 'later', 'none'];

const openId = ref(null);
const openTask = computed(() => tasks.value.find((task) => task.id === openId.value) ?? null);
</script>

<template>
    <div>
        <PageHeader :title="t('projects.mine.title')" :description="t('projects.mine.text')">
            <template #actions>
                <AppSwitch v-model="withDone" :label="t('projects.mine.with_done')" show-label />
            </template>
        </PageHeader>

        <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
        <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
        <section v-else-if="!tasks.length" class="card">
            <EmptyState :icon="ListTodo" :title="t('projects.mine.empty_title')" :text="t('projects.mine.empty_text')" />
        </section>
        <div v-else class="space-y-6">
            <template v-for="key in GROUPS" :key="key">
                <section v-if="groups[key].length" :aria-label="t(`projects.mine.${key}`)">
                    <h2 class="mb-2 flex items-center gap-2 text-[14px] font-semibold" :class="key === 'overdue' ? 'text-bad' : ''">
                        {{ t(`projects.mine.${key}`) }}<span class="tabular text-[12.5px] font-normal text-muted">{{ formatNumber(groups[key].length) }}</span>
                    </h2>
                    <div class="grid grid-cols-1 gap-2 md:grid-cols-2 xl:grid-cols-3">
                        <TaskCard v-for="task in groups[key]" :key="task.id" :task="task" :project="task.project" show-status @open="openId = task.id" />
                    </div>
                </section>
            </template>
        </div>

        <TaskDrawer v-if="openTask" :open="!!openTask" :task="openTask" :project-id="openTask.project.id" :options="opts" :calls="calls"
            @close="openId = null" @changed="list.reload()" @edit="(task) => router.push({ name: 'project', params: { id: task.project_id }, query: { task: task.id } })" />
    </div>
</template>
