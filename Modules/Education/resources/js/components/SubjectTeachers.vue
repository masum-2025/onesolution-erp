<script setup>
import { computed, ref } from 'vue';
import { BookOpen } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { useEducationSetup } from '../setup';

/**
 * A section's subjects (by the curriculum in force) and who teaches each.
 * People who set the institution up pick the teacher right here; picking
 * "nobody" takes the teacher out. Teachers come from HRM; the server
 * checks the teacher, the section's campus and the version again.
 */
const props = defineProps({
    sectionId: { type: String, required: true },
    subjects: { type: Array, required: true },
    education: { type: Object, required: true },
});
const emit = defineEmits(['changed']);
const setup = useEducationSetup();

const canPick = computed(() => setup.can('manage') && setup.data.value?.hrm);
const teachers = computed(() => setup.data.value?.teachers ?? []);
const busy = ref(null);
const assigned = computed(() => props.subjects.filter((subject) => subject.assignment).length);

async function pick(subject, teacherId) {
    busy.value = subject.subject_id;
    const current = subject.assignment;
    try {
        if (!teacherId) {
            await props.education.remove('subject_teachers', current.id);
        } else if (current) {
            await props.education.update('subject_teachers', current.id, { teacher_id: teacherId, base_version: current.version });
        } else {
            await props.education.create('subject_teachers', { section_id: props.sectionId, subject_id: subject.subject_id, teacher_id: teacherId });
        }
        toast.success(t('education.subject_teachers.saved'));
    } catch (error) {
        toast.error(error.code === 'version_conflict' ? t('education.subject_teachers.conflict') : error.errors?.teacher_id?.[0] ?? error.message);
    } finally {
        busy.value = null;
        emit('changed');
    }
}
</script>

<template>
    <section class="card mb-5" aria-labelledby="subject-teachers">
        <header class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-line px-5 py-3">
            <h2 id="subject-teachers" class="flex-1 text-[13.5px] font-semibold text-fg">{{ t('education.subject_teachers.title') }}</h2>
            <span v-if="subjects.length" class="tabular text-[12.5px] text-muted">{{ t('education.subject_teachers.count', { done: assigned, total: subjects.length }) }}</span>
        </header>
        <EmptyState v-if="!subjects.length" :icon="BookOpen" compact :title="t('education.subject_teachers.empty_title')" :text="t('education.subject_teachers.empty_text')" />
        <template v-else>
            <p v-if="setup.can('manage') && !setup.data.value?.hrm" class="border-b border-line bg-subtle px-5 py-2.5 text-[12.5px] text-muted">{{ t('education.subject_teachers.hrm_off') }}</p>
            <ul class="divide-y divide-line">
                <li v-for="subject in subjects" :key="subject.subject_id" class="flex flex-wrap items-center gap-x-3 gap-y-2 px-5 py-2.5">
                    <span class="min-w-0 flex-1 basis-40">
                        <span class="block truncate text-[14px] font-medium text-fg">{{ textIn(subject.name) }}</span>
                        <span class="block text-[12px] text-muted">
                            <span class="font-mono" dir="ltr">{{ subject.code }}</span>
                            <template v-if="subject.kind !== 'compulsory'"> · {{ t(`education.subject_teachers.kinds.${subject.kind}`) }}</template>
                        </span>
                    </span>
                    <label v-if="canPick" class="w-full sm:w-64">
                        <span class="sr-only">{{ t('education.subject_teachers.pick_for', { subject: textIn(subject.name) }) }}</span>
                        <select
                            class="field-input"
                            :value="subject.assignment?.teacher_id ?? ''"
                            :disabled="busy === subject.subject_id"
                            @change="pick(subject, $event.target.value)"
                        >
                            <option value="">{{ t('education.subject_teachers.nobody') }}</option>
                            <option v-for="teacher in teachers" :key="teacher.id" :value="teacher.id">{{ teacher.name }}<template v-if="teacher.code"> ({{ teacher.code }})</template></option>
                        </select>
                    </label>
                    <span v-else-if="subject.assignment" class="text-[13.5px] text-fg">{{ subject.assignment.teacher_name ?? '—' }}</span>
                    <AppBadge v-else tone="warn">{{ t('education.subject_teachers.nobody') }}</AppBadge>
                </li>
            </ul>
        </template>
    </section>
</template>
