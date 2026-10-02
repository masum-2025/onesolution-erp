<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { ArrowLeft, ListPlus, PenLine, Plus, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import SourceBadge from '@/components/SourceBadge.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { hrmApi } from '../api';
import { fieldKeyFrom } from '../lib';

/**
 * Extra employee fields of this organization: its own (editable with
 * hrm.configure) and those set up above it (shown with where they come
 * from). Key and type are chosen once; fields are switched off, not removed.
 */
const org = currentOrganization();
const hrm = hrmApi(org.id);
const TYPES = ['text', 'number', 'date', 'choice', 'yes_no'];

const list = useResource(() => hrm.customFields(org.id, true));
const fields = computed(() => list.data.value?.data ?? []);
const meta = computed(() => list.data.value?.meta ?? null);

const editing = ref(null); // null | 'new' | field
const saving = ref(false);
const errors = ref({});
const keyTouched = ref(false);
const form = reactive({ label: {}, key: '', type: 'text', options: [], is_required: false, is_active: true, sort_order: 0 });

function edit(field = null) {
    Object.assign(form, {
        label: { ...(field?.labels ?? {}) },
        key: field?.key ?? '',
        type: field?.type ?? 'text',
        // Options already offered keep their value (people may hold it).
        options: (field?.options ?? []).map((option) => ({ value: option.value, label: { ...option.labels }, kept: true })),
        is_required: field?.is_required ?? false,
        is_active: field?.is_active ?? true,
        sort_order: field?.sort_order ?? 0,
    });
    keyTouched.value = false;
    errors.value = {};
    editing.value = field ?? 'new';
}

function setLabel(value) {
    form.label = value;
    if (editing.value === 'new' && !keyTouched.value) form.key = fieldKeyFrom(value.en);
}

function addOption() {
    form.options.push({ value: '', label: {}, kept: false });
}

// A new choice field starts with one empty choice to fill in.
watch(
    () => form.type,
    (type) => {
        if (type === 'choice' && !form.options.length) addOption();
    },
);

async function save() {
    const options = form.type === 'choice'
        ? form.options.map((option) => ({ value: option.value || fieldKeyFrom(option.label.en ?? ''), label: option.label }))
        : null;
    const body = {
        label: form.label,
        is_required: form.is_required,
        sort_order: Number(form.sort_order) || 0,
        ...(form.type === 'choice' ? { options } : {}),
    };

    saving.value = true;
    errors.value = {};
    try {
        if (editing.value === 'new') await hrm.createField({ ...body, key: form.key, type: form.type });
        else await hrm.updateField(editing.value.id, { ...body, is_active: form.is_active, base_version: editing.value.version });
        toast.success(t('hrm.fields_page.saved'));
        editing.value = null;
        list.reload();
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('hrm.profile.conflict'));
            editing.value = null;
            list.reload();
            return;
        }
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

const canConfigure = computed(() => meta.value?.can_configure ?? false);
const atLimit = computed(() => meta.value && meta.value.in_use >= meta.value.max);
const fieldError = (name) => errors.value[name]?.[0] ?? null;
const optionsError = computed(() => fieldError('options') ?? Object.entries(errors.value).find(([key]) => key.startsWith('options.'))?.[1]?.[0] ?? null);
</script>

<template>
    <div>
        <PageHeader :title="t('hrm.fields_page.title')" :description="t('hrm.fields_page.text')">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: 'hrm' }" :icon="ArrowLeft">{{ t('hrm.profile.back') }}</AppButton>
                <AppButton v-if="canConfigure" variant="primary" :icon="Plus" :disabled="atLimit" @click="edit()">{{ t('hrm.fields_page.add') }}</AppButton>
            </template>
        </PageHeader>

        <p v-if="meta" class="mb-4 text-[12.5px] text-muted" role="status">
            {{ t('hrm.fields_page.in_use', { count: meta.in_use, max: meta.max }) }}
            <template v-if="atLimit"> · {{ t('hrm.fields_page.at_limit') }}</template>
        </p>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!fields.length" :icon="ListPlus" :title="t('hrm.fields_page.empty_title')" :text="t('hrm.fields_page.empty_text')" compact>
                <AppButton v-if="canConfigure" variant="primary" :icon="Plus" @click="edit()">{{ t('hrm.fields_page.add') }}</AppButton>
            </EmptyState>
            <ul v-else class="divide-y divide-line">
                <li v-for="field in fields" :key="field.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[13.5px] font-medium text-fg" :class="{ 'text-muted': !field.is_active }">{{ field.label }}</span>
                        <span class="block text-[12px] text-muted">
                            <span class="font-mono" dir="ltr">{{ field.key }}</span> · {{ t(`hrm.fields_page.types.${field.type}`) }}
                            <template v-if="field.type === 'choice'"> · {{ field.options.map((option) => option.label).join(', ') }}</template>
                        </span>
                    </span>
                    <AppBadge v-if="field.is_required" tone="warn">{{ t('hrm.fields_page.required') }}</AppBadge>
                    <AppBadge v-if="!field.is_active" tone="outline">{{ t('hrm.fields_page.inactive') }}</AppBadge>
                    <SourceBadge :kind="field.source.kind" :name="field.source.unit" />
                    <AppButton
                        v-if="canConfigure && field.source.kind === 'self'"
                        size="icon-sm"
                        variant="ghost"
                        :icon="PenLine"
                        :aria-label="t('hrm.fields_page.edit_named', { name: field.label })"
                        @click="edit(field)"
                    />
                </li>
            </ul>
        </section>

        <AppDialog :open="editing !== null" :title="editing === 'new' ? t('hrm.fields_page.add') : t('hrm.fields_page.edit')" size="lg" @close="editing = null">
            <form id="hrm-field" class="space-y-4" novalidate @submit.prevent="save">
                <TranslatedFields :model-value="form.label" :label="t('hrm.fields_page.label')" required :maxlength="100" :errors="errors" error-prefix="label" autofocus @update:model-value="setLabel" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <AppField v-slot="{ id }" :label="t('hrm.fields_page.key')" :hint="editing === 'new' ? t('hrm.fields_page.key_hint') : t('hrm.fields_page.fixed')" :error="fieldError('key')">
                        <input :id="id" v-model="form.key" class="field-input font-mono" dir="ltr" maxlength="40" :disabled="editing !== 'new'" @input="keyTouched = true" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('hrm.fields_page.type')" :hint="editing === 'new' ? '' : t('hrm.fields_page.fixed')" :error="fieldError('type')">
                        <select :id="id" v-model="form.type" class="field-input" :disabled="editing !== 'new'">
                            <option v-for="type in TYPES" :key="type" :value="type">{{ t(`hrm.fields_page.types.${type}`) }}</option>
                        </select>
                    </AppField>
                </div>

                <fieldset v-if="form.type === 'choice'" class="space-y-3 rounded-xl border border-line p-4">
                    <legend class="px-1 text-[13px] font-medium text-fg-2">{{ t('hrm.fields_page.options') }}</legend>
                    <div v-for="(option, index) in form.options" :key="index" class="grid items-end gap-2 sm:grid-cols-[1fr_1fr_auto]">
                        <AppField v-slot="{ id }" :label="`${t('hrm.fields_page.option')} (${t('core.languages.en')})`">
                            <input :id="id" v-model="option.label.en" class="field-input" lang="en" maxlength="100" />
                        </AppField>
                        <AppField v-slot="{ id }" :label="`${t('hrm.fields_page.option')} (${t('core.languages.bn')})`" optional>
                            <input :id="id" v-model="option.label.bn" class="field-input" lang="bn" maxlength="100" />
                        </AppField>
                        <AppButton
                            v-if="!option.kept"
                            size="icon-sm"
                            variant="ghost"
                            :icon="Trash2"
                            :aria-label="t('hrm.fields_page.remove_option')"
                            class="mb-1"
                            @click="form.options.splice(index, 1)"
                        />
                        <span v-else class="mb-2 text-[11.5px] text-faint">{{ t('hrm.fields_page.kept') }}</span>
                    </div>
                    <p v-if="optionsError" class="text-[12.5px] text-bad" role="alert">{{ optionsError }}</p>
                    <AppButton size="sm" variant="ghost" :icon="Plus" @click="addOption">{{ t('hrm.fields_page.add_option') }}</AppButton>
                </fieldset>

                <div class="grid gap-4 sm:grid-cols-2">
                    <AppSwitch v-model="form.is_required" :label="t('hrm.fields_page.required_switch')" show-label />
                    <AppSwitch v-if="editing !== 'new'" v-model="form.is_active" :label="t('hrm.fields_page.active_switch')" show-label />
                </div>
                <AppField v-slot="{ id }" :label="t('hrm.fields_page.sort_order')" :hint="t('hrm.fields_page.sort_hint')" optional class="sm:w-40">
                    <input :id="id" v-model="form.sort_order" type="number" min="0" max="999" class="field-input" />
                </AppField>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('hrm.profile.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="hrm-field" :loading="saving" :disabled="!(form.label.en ?? '').trim() || (editing === 'new' && !form.key)">
                    {{ t('hrm.fields_page.save') }}
                </AppButton>
            </template>
        </AppDialog>
    </div>
</template>
