<script setup>
import { computed, reactive, ref } from 'vue';
import { ListPlus, Plus, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { useResource } from '@/lib/useResource';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { crmApi } from '../api';
import { useCrmSetup } from '../setup';

/**
 * The company's own fields on contacts, deals, estimates and quotations,
 * and quote lines: label in each language, kind of value, choices, needed
 * or not, printed on quotes or not, order. Key and kind are fixed once
 * made; a field is switched off, never removed, so old records keep values.
 */
const ENTITIES = ['quote', 'quote_line', 'contact', 'deal'];
const TYPES = ['text', 'long_text', 'number', 'money', 'date', 'choice', 'yes_no'];
const crm = crmApi(currentOrganization().id);
const setup = useCrmSetup();
const entity = ref('quote');
const list = useResource(() => crm.fields());
const fields = computed(() => (list.data.value?.data ?? []).filter((field) => field.entity === entity.value));

const editing = ref(null);
const form = reactive({ key: '', type: 'text', label: { en: '', bn: '' }, options: [], is_required: false, on_print: true, sort_order: 0, is_active: true });
const errors = ref({});
const saving = ref(false);
function open(field = null) {
    editing.value = field ?? {};
    Object.assign(form, {
        key: field?.key ?? '', type: field?.type ?? 'text', label: { en: field?.labels?.en ?? '', bn: field?.labels?.bn ?? '' },
        options: (field?.options ?? []).map((option) => ({ value: option.value, label: { en: option.labels?.en ?? option.label, bn: option.labels?.bn ?? '' } })),
        is_required: field?.is_required ?? false, on_print: field?.on_print ?? true, sort_order: field?.sort_order ?? (fields.value.length + 1) * 10, is_active: field?.is_active ?? true,
    });
    errors.value = {};
}
const keyFrom = (text) => text.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').replace(/^(\d)/, 'f_$1').slice(0, 40);
async function save() {
    const field = editing.value;
    const label = Object.fromEntries(Object.entries(form.label).filter(([, value]) => value?.trim()));
    const options = form.type === 'choice' ? form.options.filter((option) => option.label.en.trim()).map((option) => ({
        value: option.value || keyFrom(option.label.en), label: Object.fromEntries(Object.entries(option.label).filter(([, value]) => value?.trim())),
    })) : null;
    const common = { label, is_required: form.is_required, on_print: form.on_print, sort_order: Number(form.sort_order) || 0, ...(form.type === 'choice' ? { options } : {}) };
    saving.value = true;
    errors.value = {};
    try {
        if (field.id) await crm.updateField(field.id, { ...common, is_active: form.is_active, base_version: field.version });
        else await crm.createField({ ...common, entity: entity.value, key: form.key || keyFrom(form.label.en), type: form.type });
        toast.success(t('crm.fields.saved'));
        editing.value = null;
        list.reload();
        setup.reload();
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('crm.fields.title')" :description="t('crm.fields.text')">
            <template #actions>
                <AppButton v-if="can('crm.manage')" variant="primary" :icon="Plus" @click="open()">{{ t('crm.fields.add') }}</AppButton>
            </template>
        </PageHeader>
        <AppSegmented v-model="entity" class="mb-4" :label="t('crm.fields.for')" :options="ENTITIES.map((value) => ({ value, label: t(`crm.entities.${value}`) }))" />
        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!fields.length" :icon="ListPlus" :title="t('crm.fields.empty')" :text="t(`crm.fields.empty_${entity}`)" compact>
                <AppButton v-if="can('crm.manage')" variant="primary" :icon="Plus" @click="open()">{{ t('crm.fields.add') }}</AppButton>
            </EmptyState>
            <ul v-else class="divide-y divide-line">
                <li v-for="field in fields" :key="field.id">
                    <button type="button" class="flex w-full flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-start hover:bg-surface-2" :disabled="!can('crm.manage')" @click="open(field)">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14px] font-medium" :class="field.is_active ? '' : 'text-muted line-through'">{{ field.label }}</span>
                            <span class="block font-mono text-[12px] text-muted" dir="ltr">{{ field.key }}</span>
                        </span>
                        <AppBadge tone="neutral">{{ t(`crm.field_types.${field.type}`) }}</AppBadge>
                        <AppBadge v-if="field.is_required" tone="warn">{{ t('crm.fields.required') }}</AppBadge>
                        <AppBadge v-if="field.on_print && ['quote', 'quote_line'].includes(field.entity)" tone="brand">{{ t('crm.fields.printed') }}</AppBadge>
                        <AppBadge v-if="!field.is_active" tone="neutral">{{ t('crm.common.off') }}</AppBadge>
                    </button>
                </li>
            </ul>
        </section>

        <AppDialog :open="!!editing" :title="editing?.id ? t('crm.fields.edit') : t('crm.fields.add')" :description="t(`crm.entities.${entity}`)" :icon="ListPlus" size="lg" @close="editing = null">
            <form id="crm-field" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
                <div class="sm:col-span-2">
                    <TranslatedFields v-model="form.label" :label="t('crm.fields.label')" :errors="errors" error-prefix="label" required :maxlength="80" />
                </div>
                <AppField v-slot="{ id }" :label="t('crm.fields.type')" :hint="editing?.id ? t('crm.fields.fixed') : ''" :error="errors.type?.[0]">
                    <select :id="id" v-model="form.type" class="field-input" :disabled="!!editing?.id">
                        <option v-for="type in TYPES" :key="type" :value="type">{{ t(`crm.field_types.${type}`) }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('crm.fields.key')" :hint="editing?.id ? t('crm.fields.fixed') : t('crm.fields.key_hint')" :error="errors.key?.[0]" :optional="!editing?.id">
                    <input :id="id" v-model="form.key" class="field-input font-mono" dir="ltr" maxlength="40" :disabled="!!editing?.id" :placeholder="keyFrom(form.label.en || '')" />
                </AppField>
                <div v-if="form.type === 'choice'" class="sm:col-span-2">
                    <p class="mb-2 text-[13px] font-semibold">{{ t('crm.fields.options') }}</p>
                    <p v-if="errors.options" class="mb-2 text-[12px] text-bad">{{ errors.options[0] }}</p>
                    <div v-for="(option, index) in form.options" :key="index" class="mb-2 grid grid-cols-[1fr_1fr_auto] gap-2">
                        <input v-model="option.label.en" class="field-input" maxlength="80" :placeholder="t('crm.fields.option_en')" :aria-label="t('crm.fields.option_en')" />
                        <input v-model="option.label.bn" class="field-input" maxlength="80" :placeholder="t('crm.fields.option_bn')" :aria-label="t('crm.fields.option_bn')" />
                        <AppButton size="icon" variant="ghost" :icon="Trash2" :aria-label="t('crm.common.delete')" :disabled="!!option.value && !!editing?.id" @click="form.options.splice(index, 1)" />
                    </div>
                    <AppButton size="sm" variant="secondary" :icon="Plus" @click="form.options.push({ value: '', label: { en: '', bn: '' } })">{{ t('crm.fields.add_option') }}</AppButton>
                </div>
                <AppField v-slot="{ id }" :label="t('crm.fields.order')" :error="errors.sort_order?.[0]">
                    <input :id="id" v-model="form.sort_order" inputmode="numeric" class="field-input tabular" dir="ltr" />
                </AppField>
                <div class="grid content-end gap-2">
                    <AppSwitch v-model="form.is_required" :label="t('crm.fields.required')" show-label />
                    <AppSwitch v-if="['quote', 'quote_line'].includes(entity)" v-model="form.on_print" :label="t('crm.fields.on_print')" show-label />
                    <AppSwitch v-if="editing?.id" v-model="form.is_active" :label="t('crm.common.active')" show-label />
                </div>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('crm.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="crm-field" :loading="saving" :disabled="!form.label.en?.trim()">{{ t('crm.common.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
