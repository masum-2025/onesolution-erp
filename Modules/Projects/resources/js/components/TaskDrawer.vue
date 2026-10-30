<script setup>
import { ref, watch } from 'vue';
import { PenLine, Send, Star, Users } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDrawer from '@/components/AppDrawer.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import { formatDate, formatDateTime } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { TASK_STATUSES, initials, taskTone } from '../lib';
import PeoplePicker from './PeoplePicker.vue';

/**
 * One task: its status (its people or a manager change it), its people
 * (a manager changes them), and notes anyone working on it adds.
 */
const props = defineProps({
    open: Boolean,
    task: { type: Object, default: null },
    projectId: { type: String, required: true },
    options: { type: Object, default: null },
    calls: { type: Object, required: true },
});
const emit = defineEmits(['close', 'changed', 'edit']);

const notes = ref([]);
const note = ref('');
const busy = ref(false);
const choosing = ref(false);
const people = ref({ ids: [], lead: null });
const peopleError = ref(null);

watch(() => [props.open, props.task?.id], async ([open]) => {
    if (!open || !props.task) return;
    choosing.value = false;
    note.value = '';
    try {
        notes.value = (await props.calls.notes(props.projectId, props.task.id)).data;
    } catch {
        notes.value = [];
    }
}, { immediate: true });

const statuses = TASK_STATUSES.map((value) => ({ value, label: t(`projects.task_statuses.${value}`) }));

async function move(status) {
    // One change at a time: the next carries the version the last one returned.
    if (busy.value || status === props.task.status) return;
    busy.value = true;
    try {
        emit('changed', (await props.calls.moveTask(props.projectId, props.task.id, status, props.task.version)).data);
    } catch (error) {
        toast.error(error.message);
        if (error.code === 'version_conflict') emit('changed', null);
    } finally {
        busy.value = false;
    }
}

function choosePeople() {
    people.value = { ids: props.task.people.map((person) => person.id), lead: props.task.people.find((person) => person.lead)?.id ?? null };
    peopleError.value = null;
    choosing.value = true;
}
async function savePeople() {
    busy.value = true;
    peopleError.value = null;
    try {
        emit('changed', (await props.calls.people(props.projectId, props.task.id, people.value.ids, people.value.lead, props.task.version)).data);
        toast.success(t('projects.people.saved'));
        choosing.value = false;
    } catch (error) {
        peopleError.value = error.message;
    } finally {
        busy.value = false;
    }
}

async function addNote() {
    const body = note.value.trim();
    if (!body) return;
    busy.value = true;
    try {
        notes.value = [...notes.value, (await props.calls.addNote(props.projectId, props.task.id, body)).data];
        note.value = '';
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AppDrawer :open="open" :title="task?.title ?? t('projects.task.title')" width="sm:max-w-[560px]" @close="emit('close')">
        <div v-if="task" class="space-y-5 px-5 py-5 sm:px-6">
            <header class="flex flex-wrap items-center gap-2 text-[13px]">
                <AppBadge :tone="taskTone(task.status)">{{ t(`projects.task_statuses.${task.status}`) }}</AppBadge>
                <AppBadge v-if="task.priority === 'high'" tone="warn">{{ t('projects.priorities.high') }}</AppBadge>
                <span v-if="task.due_on" :class="task.overdue ? 'font-medium text-bad' : 'text-muted'">
                    {{ t(task.overdue ? 'projects.task.overdue_since' : 'projects.task.due', { date: formatDate(task.due_on) }) }}
                </span>
                <span v-if="task.done_at" class="text-muted">{{ t('projects.task.done_by', { name: task.done_by ?? '—', date: formatDate(task.done_at) }) }}</span>
                <AppButton v-if="task.can.edit" size="sm" variant="ghost" class="ms-auto" :icon="PenLine" @click="emit('edit', task)">{{ t('projects.common.edit') }}</AppButton>
            </header>
            <p v-if="task.description" class="whitespace-pre-line text-[13.5px] text-fg-2">{{ task.description }}</p>

            <section v-if="task.can.move" :aria-label="t('projects.task.status')">
                <h3 class="mb-2 text-[13px] font-semibold text-fg-2">{{ t('projects.task.status') }}</h3>
                <AppSegmented :model-value="task.status" size="sm" :label="t('projects.task.status')" :options="statuses" @update:model-value="move" />
            </section>

            <section :aria-label="t('projects.people.title')">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <h3 class="text-[13px] font-semibold text-fg-2">{{ t('projects.people.title') }}</h3>
                    <AppButton v-if="task.can.assign && !choosing" size="sm" variant="ghost" :icon="Users" @click="choosePeople">{{ t('projects.people.change') }}</AppButton>
                </div>
                <template v-if="choosing">
                    <PeoplePicker v-model="people" :people="options?.people ?? []" :error="peopleError" />
                    <div class="mt-2 flex justify-end gap-2">
                        <AppButton size="sm" variant="ghost" @click="choosing = false">{{ t('projects.common.cancel') }}</AppButton>
                        <AppButton size="sm" variant="primary" :loading="busy" @click="savePeople">{{ t('projects.common.save') }}</AppButton>
                    </div>
                </template>
                <p v-else-if="!task.people.length" class="text-[13px] text-muted">{{ t('projects.people.nobody') }}</p>
                <ul v-else class="flex flex-wrap gap-2">
                    <li v-for="person in task.people" :key="person.id" class="flex items-center gap-2 rounded-full border border-line py-1 ps-1 pe-3 text-[13px]">
                        <span class="grid size-6 place-items-center rounded-full bg-subtle text-[10.5px] font-semibold" aria-hidden="true">{{ initials(person.name) }}</span>
                        {{ person.name }}
                        <Star v-if="person.lead" class="size-3.5 text-warn" fill="currentColor" :aria-label="t('projects.people.leads')" />
                    </li>
                </ul>
            </section>

            <section :aria-label="t('projects.notes.title')">
                <h3 class="mb-2 text-[13px] font-semibold text-fg-2">{{ t('projects.notes.title') }}</h3>
                <p v-if="!notes.length" class="text-[13px] text-muted">{{ t('projects.notes.none') }}</p>
                <ol v-else class="space-y-2">
                    <li v-for="item in notes" :key="item.id" class="rounded-lg bg-subtle px-3 py-2 text-[13px]">
                        <p class="whitespace-pre-line">{{ item.body }}</p>
                        <p class="mt-1 text-[12px] text-muted">{{ item.by ?? '—' }} · {{ formatDateTime(item.at) }}</p>
                    </li>
                </ol>
                <form v-if="task.can.move" class="mt-3 flex items-end gap-2" @submit.prevent="addNote">
                    <label class="sr-only" for="task-note">{{ t('projects.notes.add') }}</label>
                    <textarea id="task-note" v-model="note" rows="2" class="field-input flex-1" maxlength="2000" :placeholder="t('projects.notes.placeholder')"></textarea>
                    <AppButton type="submit" size="icon" variant="primary" :icon="Send" :loading="busy" :disabled="!note.trim()" :aria-label="t('projects.notes.add')" />
                </form>
            </section>
        </div>
    </AppDrawer>
</template>
