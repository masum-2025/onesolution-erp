<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, ListPlus, PenLine, Plus, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import AppTabs from '@/components/AppTabs.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { cleanTexts, textIn, textLocales, textsFor } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { educationApi } from '../api';
import { forgetEducationSetup, useEducationSetup } from '../setup';
import { keyFrom } from '../lib';

/**
 * The institution's own fields on students, guardians and applications.
 * Key and kind of answer are chosen once; choices already offered keep
 * their value. Private fields never show in the portal. Fields are
 * switched off, never removed (records may hold values).
 */
const org = currentOrganization();
const education = educationApi(org.id);
const route = useRoute();
const setup = useEducationSetup();
const manage = computed(() => setup.can('manage'));
const router = useRouter();
const ENTITIES = ['student', 'guardian', 'admission'];
const TYPES = ['text', 'long_text', 'number', 'date', 'choice', 'multi_choice', 'yes_no'];

const list = useResource(() => education.fields({ all: 1 }));
const all = computed(() => list.data.value?.data ?? []);
const tabs = computed(() => ENTITIES.map((key) => ({ key, label: t(`education.fields_page.entities.${key}`), count: all.value.filter((field) => field.entity === key).length })));
const entity = computed({
    get: () => (ENTITIES.includes(route.query.of) ? route.query.of : 'student'),
    set: (value) => router.replace({ query: value === 'student' ? {} : { of: value } }),
});
const fields = computed(() => all.value.filter((field) => field.entity === entity.value));

const editing = ref(null); // null | 'new' | field
const saving = ref(false);
const errors = ref({});
const keyTouched = ref(false);
const form = reactive({ label: {}, key: '', type: 'text', options: [], is_required: false, portal_visible: false, on_documents: false, is_sensitive: false, is_active: true, sort_order: 0 });
const choices = computed(() => ['choice', 'multi_choice'].includes(form.type));

function edit(field = null) {
    Object.assign(form, {
        label: textsFor(field?.label),
        key: field?.key ?? '',
        type: field?.type ?? 'text',
        options: (field?.options ?? []).map((option) => ({ value: option.value, label: textsFor(option.label), kept: true })),
        is_required: field?.is_required ?? false,
        portal_visible: field?.portal_visible ?? false,
        on_documents: field?.on_documents ?? false,
        is_sensitive: field?.is_sensitive ?? false,
        is_active: field?.is_active ?? true,
        sort_order: field?.sort_order ?? 0,
    });
    keyTouched.value = false;
    errors.value = {};
    editing.value = field ?? 'new';
}

function setLabel(value) {
    form.label = value;
    if (editing.value === 'new' && !keyTouched.value) form.key = keyFrom(value.en);
}

function addOption() {
    form.options.push({ value: '', label: textsFor({}), kept: false });
}

watch(() => form.type, (type) => {
    if (['choice', 'multi_choice'].includes(type) && !form.options.length) addOption();
});
// A private value never shows in the portal.
watch(() => form.is_sensitive, (value) => {
    if (value) form.portal_visible = false;
});

async function save() {
    const body = {
        label: cleanTexts(form.label),
        is_required: form.is_required,
        portal_visible: form.portal_visible,
        on_documents: form.on_documents,
        is_sensitive: form.is_sensitive,
        sort_order: Number(form.sort_order) || 0,
        ...(choices.value ? { options: form.options.map((option) => ({ value: option.value || keyFrom(option.label.en) || `option_${Math.random().toString(36).slice(2, 7)}`, label: cleanTexts(option.label) })) } : {}),
    };
    saving.value = true;
    errors.value = {};
    try {
        if (editing.value === 'new') await education.createField({ ...body, entity: entity.value, key: form.key, type: form.type });
        else await education.updateField(editing.value.id, { ...body, is_active: form.is_active, base_version: editing.value.version });
        toast.success(t('education.fields_page.saved'));
        editing.value = null;
        // Forms read own fields from the set-up: read it again next time.
        forgetEducationSetup();
        list.reload();
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('education.conflict'));
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

const fieldError = (name) => errors.value[name]?.[0] ?? null;
const optionsError = computed(() => fieldError('options') ?? Object.entries(errors.value).find(([key]) => key.startsWith('options.'))?.[1]?.[0] ?? null);
const otherLocales = computed(() => textLocales().slice(1));
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'education' }" :icon="ArrowLeft" class="mb-3">{{ t('education.back') }}</AppButton>
        <PageHeader :title="t('education.fields_page.title')" :description="t('education.fields_page.text')">
            <template #actions>
                <AppButton v-if="manage" variant="primary" :icon="Plus" @click="edit()">{{ t('education.fields_page.add') }}</AppButton>
            </template>
        </PageHeader>

        <div class="mb-5"><AppTabs v-model="entity" :tabs="tabs" :label="t('education.fields_page.title')" /></div>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!fields.length" :icon="ListPlus" :title="t('education.fields_page.empty_title')" :text="t('education.fields_page.empty_text')" compact>
                <AppButton v-if="manage" variant="primary" :icon="Plus" @click="edit()">{{ t('education.fields_page.add') }}</AppButton>
            </EmptyState>
            <ul v-else class="divide-y divide-line">
                <li v-for="field in fields" :key="field.id" class="flex flex-wrap items-center gap-2 px-5 py-3">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[13.5px] font-medium" :class="field.is_active ? 'text-fg' : 'text-muted line-through'">{{ field.label_text }}</span>
                        <span class="block truncate text-[12px] text-muted">
                            <span class="font-mono" dir="ltr">{{ field.key }}</span> · {{ t(`education.fields_page.types.${field.type}`) }}
                            <template v-if="field.options?.length"> · {{ field.options.map((option) => textIn(option.label)).join(', ') }}</template>
                        </span>
                    </span>
                    <AppBadge v-if="field.is_required" tone="warn">{{ t('education.fields_page.required') }}</AppBadge>
                    <AppBadge v-if="field.is_sensitive" tone="bad">{{ t('education.fields_page.private') }}</AppBadge>
                    <AppBadge v-if="field.portal_visible" tone="ok">{{ t('education.fields_page.portal') }}</AppBadge>
                    <AppBadge v-if="field.on_documents" tone="brand">{{ t('education.fields_page.documents') }}</AppBadge>
                    <AppBadge v-if="!field.is_active" tone="outline">{{ t('education.fields_page.inactive') }}</AppBadge>
                    <AppButton v-if="manage" size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="t('education.fields_page.edit_named', { name: field.label_text })" @click="edit(field)" />
                </li>
            </ul>
        </section>

        <AppDialog :open="editing !== null" :title="editing === 'new' ? t('education.fields_page.add') : t('education.fields_page.edit')" :description="t(`education.fields_page.entities.${entity}`)" size="lg" @close="editing = null">
            <form id="education-field" class="space-y-4" novalidate @submit.prevent="save">
                <TranslatedFields :model-value="form.label" :label="t('education.fields_page.label')" required :maxlength="80" :errors="errors" error-prefix="label" autofocus @update:model-value="setLabel" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <AppField v-slot="{ id }" :label="t('education.fields_page.key')" :hint="editing === 'new' ? t('education.fields_page.key_hint') : t('education.fields_page.fixed')" :error="fieldError('key')">
                        <input :id="id" v-model="form.key" class="field-input font-mono" dir="ltr" maxlength="40" :disabled="editing !== 'new'" @input="keyTouched = true" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('education.fields_page.type')" :hint="editing === 'new' ? '' : t('education.fields_page.fixed')" :error="fieldError('type')">
                        <select :id="id" v-model="form.type" class="field-input" :disabled="editing !== 'new'">
                            <option v-for="type in TYPES" :key="type" :value="type">{{ t(`education.fields_page.types.${type}`) }}</option>
                        </select>
                    </AppField>
                </div>

                <fieldset v-if="choices" class="space-y-3 rounded-xl border border-line p-4">
                    <legend class="px-1 text-[13px] font-medium text-fg-2">{{ t('education.fields_page.options') }}</legend>
                    <div v-for="(option, index) in form.options" :key="index" class="grid grid-cols-1 items-end gap-2" :class="otherLocales.length ? 'sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]' : 'sm:grid-cols-[minmax(0,1fr)_auto]'">
                        <AppField v-slot="{ id }" :label="`${t('education.fields_page.option')} (${t('core.languages.en')})`">
                            <input :id="id" v-model="option.label.en" class="field-input" lang="en" maxlength="80" />
                        </AppField>
                        <AppField v-for="locale in otherLocales.slice(0, 1)" :key="locale" v-slot="{ id }" :label="`${t('education.fields_page.option')} (${t(`core.languages.${locale}`)})`" optional>
                            <input :id="id" v-model="option.label[locale]" class="field-input" :lang="locale" maxlength="80" />
                        </AppField>
                        <AppButton v-if="!option.kept" size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('education.fields_page.remove_option')" class="mb-1" @click="form.options.splice(index, 1)" />
                        <span v-else class="mb-2 text-[11.5px] text-faint">{{ t('education.fields_page.kept') }}</span>
                    </div>
                    <p v-if="optionsError" class="text-[12.5px] text-bad" role="alert">{{ optionsError }}</p>
                    <AppButton size="sm" variant="ghost" :icon="Plus" @click="addOption">{{ t('education.fields_page.add_option') }}</AppButton>
                </fieldset>

                <div class="grid gap-3 sm:grid-cols-2">
                    <AppSwitch v-model="form.is_required" :label="t('education.fields_page.required_switch')" show-label />
                    <AppSwitch v-model="form.is_sensitive" :label="t('education.fields_page.sensitive_switch')" show-label />
                    <AppSwitch v-model="form.portal_visible" :label="t('education.fields_page.portal_switch')" show-label :disabled="form.is_sensitive" />
                    <AppSwitch v-model="form.on_documents" :label="t('education.fields_page.documents_switch')" show-label />
                    <AppSwitch v-if="editing !== 'new'" v-model="form.is_active" :label="t('education.fields_page.active_switch')" show-label />
                </div>
                <AppField v-slot="{ id }" :label="t('education.fields_page.sort_order')" :hint="t('education.fields_page.sort_hint')" optional class="sm:w-40">
                    <input :id="id" v-model="form.sort_order" type="number" min="0" max="1000" class="field-input tabular" />
                </AppField>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('education.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="education-field" :loading="saving" :disabled="!(form.label.en ?? '').trim() || (editing === 'new' && !form.key)">
                    {{ t('education.fields_page.save') }}
                </AppButton>
            </template>
        </AppDialog>
    </div>
</template>
