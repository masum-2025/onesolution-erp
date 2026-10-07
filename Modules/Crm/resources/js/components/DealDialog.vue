<script setup>
import { computed, reactive, ref } from 'vue';
import { Handshake } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { crmApi } from '../api';
import { amountToMinor, fieldToInput, fieldsToApi, minorToText } from '../lib';
import { useCrmSetup } from '../setup';
import ExtraFields from './ExtraFields.vue';

/** Add a deal for a contact (or change one): title, value, expected day, pipeline and stage, extra fields. */
const props = defineProps({ contactId: { type: String, default: null }, deal: { type: Object, default: null }, pipelineId: { type: String, default: null } });
const emit = defineEmits(['close', 'saved']);
const crm = crmApi(currentOrganization().id);
const setup = useCrmSetup();
const fields = setup.fields('deal');
const pipelines = computed(() => (setup.data.value?.pipelines ?? []).filter((pipeline) => pipeline.is_active));
const form = reactive({
    contact_id: props.contactId ?? props.deal?.contact_id ?? '',
    title: props.deal?.title ?? '',
    value: minorToText(props.deal?.value_minor ?? null, setup.currency.value),
    expected_on: props.deal?.expected_on ?? '',
    pipeline_id: props.deal?.pipeline_id ?? props.pipelineId ?? pipelines.value.find((pipeline) => pipeline.is_default)?.id ?? pipelines.value[0]?.id ?? '',
    stage_id: '',
    extra: Object.fromEntries(fields.map((field) => [field.key, fieldToInput(field, props.deal?.extra?.[field.key], setup.currency.value)])),
});
const stages = computed(() => (pipelines.value.find((pipeline) => pipeline.id === form.pipeline_id)?.stages ?? []).filter((stage) => stage.is_active && stage.outcome === 'open'));
const contacts = ref([]);
const search = ref('');
async function findContacts() {
    if (search.value.trim().length < 2) return;
    contacts.value = (await crm.contacts({ q: search.value.trim() })).data;
}
const errors = ref({});
const saving = ref(false);

async function save() {
    const value = form.value.trim() === '' ? 0 : amountToMinor(form.value, setup.currency.value);
    const extra = fieldsToApi(fields, form.extra, setup.currency.value);
    if (value === null || Object.keys(extra.errors).length) {
        errors.value = { ...(value === null ? { value_minor: [t('crm.validation.amount')] } : {}), ...Object.fromEntries(Object.keys(extra.errors).map((key) => [`extra.${key}`, [t('crm.validation.amount')]])) };
        return;
    }
    saving.value = true;
    errors.value = {};
    try {
        const common = { title: form.title.trim(), value_minor: value, expected_on: form.expected_on || null };
        const { data } = props.deal
            ? await crm.updateDeal(props.deal.id, { ...common, extra: extra.values, base_version: props.deal.version })
            : await crm.createDeal({ ...common, contact_id: form.contact_id, pipeline_id: form.pipeline_id || null, stage_id: form.stage_id || null,
                extra: Object.fromEntries(Object.entries(extra.values).filter(([, item]) => item !== null)) });
        toast.success(t('crm.deals.saved'));
        emit('saved', data);
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppDialog open :title="deal ? t('crm.deals.edit') : t('crm.deals.add')" :icon="Handshake" size="lg" @close="emit('close')">
        <form id="crm-deal" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
            <AppField v-if="!contactId && !deal" v-slot="{ id }" :label="t('crm.deals.contact')" :error="errors.contact_id?.[0]" class="sm:col-span-2">
                <div class="grid gap-2 sm:grid-cols-2">
                    <input v-model="search" class="field-input" :placeholder="t('crm.contacts.search')" :aria-label="t('crm.contacts.search')" @input="findContacts" />
                    <select :id="id" v-model="form.contact_id" class="field-input">
                        <option value="" disabled>{{ t('crm.deals.choose_contact') }}</option>
                        <option v-for="contact in contacts" :key="contact.id" :value="contact.id">{{ contact.name }}{{ contact.company_name ? ` · ${contact.company_name}` : '' }}</option>
                    </select>
                </div>
            </AppField>
            <AppField v-slot="{ id }" :label="t('crm.deals.title_field')" :error="errors.title?.[0]" class="sm:col-span-2">
                <input :id="id" v-model="form.title" class="field-input" maxlength="150" :placeholder="t('crm.deals.title_hint')" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('crm.deals.value')" :error="errors.value_minor?.[0]" optional>
                <input :id="id" v-model="form.value" inputmode="decimal" class="field-input tabular text-end" dir="ltr" :placeholder="setup.currency.value" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('crm.deals.expected_on')" :error="errors.expected_on?.[0]" optional>
                <input :id="id" v-model="form.expected_on" type="date" class="field-input" />
            </AppField>
            <template v-if="!deal">
                <AppField v-if="pipelines.length > 1" v-slot="{ id }" :label="t('crm.deals.pipeline')" :error="errors.pipeline_id?.[0]">
                    <select :id="id" v-model="form.pipeline_id" class="field-input">
                        <option v-for="pipeline in pipelines" :key="pipeline.id" :value="pipeline.id">{{ pipeline.name }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('crm.deals.stage')" :error="errors.stage_id?.[0]" optional>
                    <select :id="id" v-model="form.stage_id" class="field-input">
                        <option value="">{{ stages[0]?.name ?? '—' }}</option>
                        <option v-for="stage in stages.slice(1)" :key="stage.id" :value="stage.id">{{ stage.name }}</option>
                    </select>
                </AppField>
            </template>
            <div v-if="fields.length" class="sm:col-span-2">
                <ExtraFields v-model="form.extra" :fields="fields" :errors="errors" :currency="setup.currency.value" />
            </div>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('crm.common.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="crm-deal" :loading="saving" :disabled="form.title.trim().length < 2 || !form.contact_id">{{ t('crm.common.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
