<script setup>
import { computed, reactive, ref, watch } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import ClientPicker from './ClientPicker.vue';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Open a project, or change one. With CRM the client is one of the
 * company's contacts, a person or an organization (or a new one added there
 * from here); without CRM it is only a name. A template makes the usual tasks.
 */
const props = defineProps({
    open: Boolean,
    project: { type: Object, default: null },
    options: { type: Object, default: null },
    calls: { type: Object, required: true },
});
const emit = defineEmits(['close', 'saved']);

const form = reactive({ name: '', client: '', picked: { contact_id: null, name: '', kind: null }, template_id: '', service: '', manager_id: '', starts_on: '', due_on: '', description: '' });
const errors = ref({});
const saving = ref(false);
const editing = computed(() => !!props.project);
// With CRM the client is one of its contacts (person or organization); without it, a name.
const usesCrm = computed(() => !!props.options?.uses_crm);

watch(() => props.open, (open) => {
    if (!open) return;
    const project = props.project;
    Object.assign(form, {
        name: project?.name ?? '',
        client: project?.client.name ?? '',
        picked: project?.client.contact_id ? { contact_id: project.client.contact_id, name: project.client.name, kind: project.client.kind ?? null } : { contact_id: null, name: '', kind: null },
        template_id: '',
        service: project?.service ?? '',
        manager_id: project?.manager?.id ?? '',
        starts_on: project?.starts_on ?? props.options?.today ?? '',
        due_on: project?.due_on ?? '',
        description: project?.description ?? '',
    });
    errors.value = {};
});
const hasClient = computed(() => (usesCrm.value ? !!form.picked.contact_id : !!form.client.trim()));
// A template's service comes with it unless one is typed.
watch(() => form.template_id, (id) => {
    const template = props.options?.templates.find((item) => item.id === id);
    if (template?.service && !form.service) form.service = template.service;
});

async function save() {
    saving.value = true;
    errors.value = {};
    const body = {
        name: form.name.trim(),
        ...(usesCrm.value ? { client_contact_id: form.picked.contact_id } : { client_name: form.client.trim() || null }),
        service: form.service.trim() || null,
        manager_id: form.manager_id || null,
        starts_on: form.starts_on,
        due_on: form.due_on || null,
        description: form.description.trim() || null,
    };
    try {
        const { data } = editing.value
            ? await props.calls.update(props.project.id, { ...body, base_version: props.project.version })
            : await props.calls.open({ ...body, template_id: form.template_id || null });
        toast.success(t(editing.value ? 'projects.dialog.saved' : 'projects.dialog.opened', { code: data.code }));
        emit('saved', data);
    } catch (error) {
        errors.value = error.errors ?? {};
        if (error.code === 'client_needed' || error.code === 'client_unknown') errors.value = { client_name: [error.message] };
        else if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
const fieldError = (...names) => names.map((name) => errors.value[name]?.[0]).find(Boolean) ?? null;
</script>

<template>
    <AppDialog :open="open" :title="editing ? t('projects.dialog.edit_title', { code: project.code }) : t('projects.dialog.title')" @close="emit('close')">
        <form id="project-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
            <AppField v-slot="{ id }" :label="t('projects.fields.name')" :error="fieldError('name')" class="sm:col-span-2">
                <input :id="id" v-model="form.name" class="field-input" maxlength="200" />
            </AppField>
            <div v-if="usesCrm" class="sm:col-span-2">
                <ClientPicker v-model="form.picked" :calls="calls" :can-add="!!options?.can.add_client" :error="fieldError('client_name', 'client_contact_id')" />
            </div>
            <AppField v-else v-slot="{ id }" :label="t('projects.fields.client')" :hint="t('projects.dialog.client_hint_plain')"
                :error="fieldError('client_name', 'client_contact_id')" class="sm:col-span-2">
                <input :id="id" v-model="form.client" class="field-input" maxlength="190" autocomplete="off" />
            </AppField>
            <AppField v-if="!editing" v-slot="{ id }" :label="t('projects.fields.template')" :hint="t('projects.dialog.template_hint')" :error="fieldError('template_id')" optional>
                <select :id="id" v-model="form.template_id" class="field-input">
                    <option value="">{{ t('projects.dialog.no_template') }}</option>
                    <option v-for="template in options?.templates ?? []" :key="template.id" :value="template.id">{{ template.name }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('projects.fields.service')" :error="fieldError('service')" optional>
                <input :id="id" v-model="form.service" class="field-input" maxlength="120" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('projects.fields.manager')" :error="fieldError('manager_id')" optional>
                <select :id="id" v-model="form.manager_id" class="field-input">
                    <option value="">{{ t('projects.common.none') }}</option>
                    <option v-for="person in options?.people ?? []" :key="person.id" :value="person.id">{{ person.name }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('projects.fields.starts_on')" :error="fieldError('starts_on')">
                <input :id="id" v-model="form.starts_on" type="date" class="field-input" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('projects.fields.due_on')" :error="fieldError('due_on')" optional>
                <input :id="id" v-model="form.due_on" type="date" :min="form.starts_on || undefined" class="field-input" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('projects.fields.description')" :error="fieldError('description')" optional class="sm:col-span-2">
                <textarea :id="id" v-model="form.description" rows="3" class="field-input" maxlength="5000"></textarea>
            </AppField>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('projects.common.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="project-form" :loading="saving" :disabled="form.name.trim().length < 2 || !hasClient || !form.starts_on">
                {{ editing ? t('projects.common.save') : t('projects.dialog.open') }}
            </AppButton>
        </template>
    </AppDialog>
</template>
