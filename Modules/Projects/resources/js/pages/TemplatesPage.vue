<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { ArrowDown, ArrowLeft, ArrowUp, LayoutTemplate, PenLine, Plus, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { useResource } from '@/lib/useResource';
import { can, currentOrganization } from '@/lib/session';
import { formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { projectsApi } from '../api';

/**
 * Templates of the usual steps (e.g. "BSCI audit"): kept by a group or a
 * company, used at it and below. Each step has a title per language and is
 * due some days after a project starts. Switched off, never removed.
 */
const org = currentOrganization();
const calls = projectsApi(org.id);
const showAll = ref(false);
const list = useResource(() => calls.templates(showAll.value));
watch(showAll, () => list.reload());
const templates = computed(() => list.data.value?.data ?? []);
const keeps = computed(() => ['group', 'company'].includes(org.organization_type) && can('projects.configure'));

const editing = ref(null); // null | 'new' | template
const form = reactive({ name: {}, service: '', is_active: true, steps: [] });
const errors = ref({});
const saving = ref(false);
const loadingOne = ref(false);

async function edit(template = null) {
    errors.value = {};
    if (!template) {
        Object.assign(form, { name: {}, service: '', is_active: true, steps: [{ title: {}, due_after_days: 0 }] });
        editing.value = 'new';
        return;
    }
    loadingOne.value = true;
    try {
        const { data } = await calls.template(template.id);
        Object.assign(form, {
            name: { ...data.names }, service: data.service ?? '', is_active: data.is_active,
            steps: data.steps.map((step) => ({ title: { ...step.titles }, due_after_days: step.due_after_days })),
        });
        editing.value = data;
    } catch (error) {
        toast.error(error.message);
    } finally {
        loadingOne.value = false;
    }
}
function addStep() {
    const last = form.steps[form.steps.length - 1];
    form.steps.push({ title: {}, due_after_days: last ? last.due_after_days : 0 });
}
function moveStep(index, by) {
    const [step] = form.steps.splice(index, 1);
    form.steps.splice(index + by, 0, step);
}
async function save() {
    saving.value = true;
    errors.value = {};
    const body = { name: form.name, service: form.service.trim() || null, steps: form.steps.map((step) => ({ title: step.title, due_after_days: Number(step.due_after_days) || 0 })) };
    try {
        if (editing.value === 'new') await calls.addTemplate(body);
        else await calls.updateTemplate(editing.value.id, { ...body, is_active: form.is_active, base_version: editing.value.version });
        toast.success(t('projects.templates.saved'));
        editing.value = null;
        list.reload();
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <div>
        <PageHeader :title="t('projects.templates.title')" :description="t('projects.templates.text')">
            <template #actions>
                <AppButton variant="ghost" :icon="ArrowLeft" :to="{ name: 'projects' }">{{ t('projects.list.title') }}</AppButton>
                <AppButton v-if="keeps" variant="primary" :icon="Plus" @click="edit()">{{ t('projects.templates.add') }}</AppButton>
            </template>
        </PageHeader>

        <div class="mb-4 flex justify-end">
            <AppSwitch v-model="showAll" :label="t('projects.templates.show_off')" show-label />
        </div>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!templates.length" :icon="LayoutTemplate" compact :title="t('projects.templates.empty_title')" :text="t('projects.templates.empty_text')">
                <AppButton v-if="keeps" variant="primary" :icon="Plus" @click="edit()">{{ t('projects.templates.add') }}</AppButton>
            </EmptyState>
            <ul v-else class="divide-y divide-line">
                <li v-for="template in templates" :key="template.id" class="flex items-center gap-3 px-5 py-3">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[13.5px] font-medium">{{ template.name }}</span>
                        <span class="block truncate text-[12px] text-muted">
                            <template v-if="template.service">{{ template.service }}</template>
                            <template v-if="template.kept_by"> · {{ t('projects.templates.kept_by', { name: template.kept_by }) }}</template>
                        </span>
                    </span>
                    <AppBadge v-if="!template.is_active" tone="outline">{{ t('projects.templates.off') }}</AppBadge>
                    <AppButton v-if="keeps && template.own" size="icon-sm" variant="ghost" :icon="PenLine" :loading="loadingOne" :aria-label="t('projects.templates.edit', { name: template.name })" @click="edit(template)" />
                </li>
            </ul>
        </section>

        <AppDialog :open="editing !== null" :title="editing === 'new' ? t('projects.templates.add') : t('projects.templates.edit', { name: editing?.name })" @close="editing = null">
            <form id="template-form" class="space-y-4" novalidate @submit.prevent="save">
                <TranslatedFields v-model="form.name" :label="t('projects.templates.name')" :errors="errors" error-prefix="name" required :maxlength="120" />
                <AppField v-slot="{ id }" :label="t('projects.fields.service')" :hint="t('projects.templates.service_hint')" :error="fieldError('service')" optional>
                    <input :id="id" v-model="form.service" class="field-input" maxlength="120" />
                </AppField>
                <fieldset>
                    <legend class="mb-2 text-[13px] font-medium text-fg-2">{{ t('projects.templates.steps', { count: form.steps.length, steps: formatNumber(form.steps.length) }) }}</legend>
                    <ol class="space-y-3">
                        <li v-for="(step, index) in form.steps" :key="index" class="rounded-xl border border-line p-3">
                            <div class="mb-2 flex items-center gap-2 text-[12.5px] text-muted">
                                <span class="font-semibold text-fg-2">{{ formatNumber(index + 1) }}</span>
                                <label class="ms-auto flex items-center gap-1.5">
                                    {{ t('projects.templates.after_days') }}
                                    <input v-model="step.due_after_days" type="number" min="0" max="3650" class="field-input w-20 py-1" />
                                </label>
                                <AppButton size="icon-sm" variant="ghost" :icon="ArrowUp" :disabled="index === 0" :aria-label="t('projects.templates.up')" @click="moveStep(index, -1)" />
                                <AppButton size="icon-sm" variant="ghost" :icon="ArrowDown" :disabled="index === form.steps.length - 1" :aria-label="t('projects.templates.down')" @click="moveStep(index, 1)" />
                                <AppButton size="icon-sm" variant="ghost" class="text-bad" :icon="Trash2" :disabled="form.steps.length === 1" :aria-label="t('projects.templates.remove')" @click="form.steps.splice(index, 1)" />
                            </div>
                            <TranslatedFields v-model="step.title" :label="t('projects.templates.step_title')" :errors="errors" :error-prefix="`steps.${index}.title`" required :maxlength="200" />
                        </li>
                    </ol>
                    <AppButton class="mt-3" size="sm" :icon="Plus" @click="addStep">{{ t('projects.templates.add_step') }}</AppButton>
                </fieldset>
                <AppSwitch v-if="editing !== 'new'" v-model="form.is_active" :label="t('projects.templates.in_use')" show-label />
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('projects.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="template-form" :loading="saving">{{ t('projects.common.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
