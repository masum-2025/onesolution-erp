<script setup>
import { computed, reactive, ref, watch } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { newOpId } from '@/lib/billing';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import PeoplePicker from './PeoplePicker.vue';

/**
 * Add a task (with its people), or change one's title, description,
 * priority and due day. A task added offline would carry the same op_id
 * again; here each opening of the dialog gets its own.
 */
const props = defineProps({
    open: Boolean,
    projectId: { type: String, required: true },
    task: { type: Object, default: null },
    options: { type: Object, default: null },
    calls: { type: Object, required: true },
});
const emit = defineEmits(['close', 'saved']);

const form = reactive({ title: '', description: '', priority: 'normal', due_on: '', people: { ids: [], lead: null } });
const errors = ref({});
const saving = ref(false);
let opId = null;
const editing = computed(() => !!props.task);

watch(() => props.open, (open) => {
    if (!open) return;
    Object.assign(form, {
        title: props.task?.title ?? '',
        description: props.task?.description ?? '',
        priority: props.task?.priority ?? 'normal',
        due_on: props.task?.due_on ?? '',
        people: { ids: [], lead: null },
    });
    errors.value = {};
    opId = newOpId();
});

async function save() {
    saving.value = true;
    errors.value = {};
    const body = { title: form.title.trim(), description: form.description.trim() || null, priority: form.priority, due_on: form.due_on || null };
    try {
        const { data } = editing.value
            ? await props.calls.updateTask(props.projectId, props.task.id, { ...body, base_version: props.task.version })
            : await props.calls.addTask(props.projectId, { ...body, user_ids: form.people.ids, lead_id: form.people.lead, op_id: opId });
        toast.success(t(editing.value ? 'projects.task.saved' : 'projects.task.added'));
        emit('saved', data);
    } catch (error) {
        errors.value = error.errors ?? {};
        if (['not_assignable', 'too_many_assignees'].includes(error.code)) errors.value = { user_ids: [error.message] };
        else if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <AppDialog :open="open" :title="editing ? t('projects.task.edit_title') : t('projects.task.add_title')" @close="emit('close')">
        <form id="task-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
            <AppField v-slot="{ id }" :label="t('projects.fields.title')" :error="fieldError('title')" class="sm:col-span-2">
                <input :id="id" v-model="form.title" class="field-input" maxlength="200" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('projects.fields.priority')" :error="fieldError('priority')">
                <select :id="id" v-model="form.priority" class="field-input">
                    <option v-for="priority in options?.priorities ?? ['low', 'normal', 'high']" :key="priority" :value="priority">{{ t(`projects.priorities.${priority}`) }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('projects.fields.due_on')" :error="fieldError('due_on')" optional>
                <input :id="id" v-model="form.due_on" type="date" class="field-input" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('projects.fields.description')" :error="fieldError('description')" optional class="sm:col-span-2">
                <textarea :id="id" v-model="form.description" rows="3" class="field-input" maxlength="5000"></textarea>
            </AppField>
            <div v-if="!editing" class="sm:col-span-2">
                <PeoplePicker v-model="form.people" :people="options?.people ?? []" :error="fieldError('user_ids')" />
            </div>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('projects.common.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="task-form" :loading="saving" :disabled="form.title.trim().length < 2">{{ editing ? t('projects.common.save') : t('projects.task.add') }}</AppButton>
        </template>
    </AppDialog>
</template>
