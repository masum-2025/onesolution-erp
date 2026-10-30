<script setup>
import { CalendarClock, Star } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { initials, taskTone } from '../lib';

/** A task on the board or in a list: title, its people, due day (overdue in red, with words), priority. */
defineProps({
    task: { type: Object, required: true },
    showStatus: Boolean,
    project: { type: Object, default: null },
});
const emit = defineEmits(['open']);
</script>

<template>
    <button type="button" class="block w-full rounded-xl border border-line bg-raised px-3 py-2.5 text-start shadow-xs transition hover:border-line-strong hover:shadow-pop"
        :class="task.mine ? 'border-s-[3px] border-s-brand' : ''" @click="emit('open', task)">
        <p v-if="project" class="mb-0.5 truncate text-[11.5px] text-muted">{{ project.code }} · {{ project.client ?? project.name }}</p>
        <p class="flex items-start gap-2 text-[13.5px] font-medium leading-snug" :class="task.status === 'done' ? 'text-muted line-through' : 'text-fg'">
            <span class="min-w-0 flex-1">{{ task.title }}</span>
            <AppBadge v-if="showStatus" :tone="taskTone(task.status)">{{ t(`projects.task_statuses.${task.status}`) }}</AppBadge>
        </p>
        <p class="mt-2 flex flex-wrap items-center gap-2 text-[12px]">
            <span v-if="task.due_on" class="inline-flex items-center gap-1" :class="task.overdue ? 'font-medium text-bad' : 'text-muted'">
                <CalendarClock class="size-3.5" aria-hidden="true" />
                {{ task.overdue ? t('projects.task.overdue_short', { date: formatDate(task.due_on) }) : formatDate(task.due_on) }}
            </span>
            <AppBadge v-if="task.priority === 'high'" tone="warn">{{ t('projects.priorities.high') }}</AppBadge>
            <span class="ms-auto flex -space-x-1.5 rtl:space-x-reverse">
                <span v-for="person in task.people.slice(0, 4)" :key="person.id" :title="person.name"
                    class="relative grid size-6 place-items-center rounded-full border-2 border-raised bg-subtle text-[10px] font-semibold">
                    {{ initials(person.name) }}
                    <Star v-if="person.lead" class="absolute -end-1 -top-1 size-3 text-warn" fill="currentColor" aria-hidden="true" />
                </span>
                <span v-if="task.people.length > 4" class="grid size-6 place-items-center rounded-full border-2 border-raised bg-subtle text-[10px]">+{{ task.people.length - 4 }}</span>
            </span>
            <span class="sr-only">{{ task.people.map((person) => person.name).join(', ') }}</span>
        </p>
    </button>
</template>
