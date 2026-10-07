<script setup>
import { reactive, ref } from 'vue';
import { UserRound } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { crmApi } from '../api';
import { fieldToInput, fieldsToApi, phoneText } from '../lib';
import { useCrmSetup } from '../setup';
import ExtraFields from './ExtraFields.vue';

/**
 * Add or change a contact: person or organization, name, mobile (one per
 * contact; the server says whose it is when taken), email, tags, source,
 * who looks after them, consent to offers by SMS and email, and the
 * company's own fields.
 */
const props = defineProps({ contact: { type: Object, default: null } });
const emit = defineEmits(['close', 'saved']);
const crm = crmApi(currentOrganization().id);
const setup = useCrmSetup();
const fields = setup.fields('contact');
const form = reactive({
    kind: props.contact?.kind ?? 'person',
    name: props.contact?.name ?? '',
    company_name: props.contact?.company_name ?? '',
    phone: phoneText(props.contact?.phone ?? ''),
    email: props.contact?.email ?? '',
    tags: (props.contact?.tags ?? []).join(', '),
    source: props.contact?.source ?? '',
    owner_id: props.contact?.owner_id ?? '',
    sms_consent: props.contact?.sms_consent ?? false,
    email_consent: props.contact?.email_consent ?? false,
    extra: Object.fromEntries(fields.map((field) => [field.key, fieldToInput(field, props.contact?.extra?.[field.key], setup.currency.value)])),
});
const errors = ref({});
const saving = ref(false);
const SOURCES = ['walk_in', 'phone', 'referral', 'website', 'facebook', 'event', 'pos', 'import'];

async function save() {
    const extra = fieldsToApi(fields, form.extra, setup.currency.value);
    if (Object.keys(extra.errors).length) {
        errors.value = Object.fromEntries(Object.keys(extra.errors).map((key) => [`extra.${key}`, [t('crm.validation.amount')]]));
        return;
    }
    const body = {
        kind: form.kind, name: form.name.trim(), company_name: form.company_name.trim() || null, phone: form.phone.trim() || null, email: form.email.trim() || null,
        tags: form.tags.split(/[,;]/).map((tag) => tag.trim()).filter(Boolean), source: form.source || null, owner_id: form.owner_id || null,
        sms_consent: form.sms_consent, email_consent: form.email_consent, extra: props.contact ? extra.values : Object.fromEntries(Object.entries(extra.values).filter(([, value]) => value !== null)),
    };
    saving.value = true;
    errors.value = {};
    try {
        const { data } = props.contact ? await crm.updateContact(props.contact.id, { ...body, base_version: props.contact.version }) : await crm.createContact(body);
        toast.success(t('crm.contacts.saved'));
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
    <AppDialog open :title="contact ? t('crm.contacts.edit') : t('crm.contacts.add')" :icon="UserRound" size="lg" @close="emit('close')">
        <form id="crm-contact" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
            <div class="sm:col-span-2">
                <AppSegmented v-model="form.kind" size="sm" :label="t('crm.contacts.kind')" :options="['person', 'organization'].map((value) => ({ value, label: t(`crm.kinds.${value}`) }))" />
            </div>
            <AppField v-slot="{ id }" :label="t(form.kind === 'organization' ? 'crm.contacts.contact_person' : 'crm.contacts.name')" :error="errors.name?.[0]">
                <input :id="id" v-model="form.name" class="field-input" maxlength="150" autocomplete="off" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('crm.contacts.company_name')" :error="errors.company_name?.[0]" :optional="form.kind !== 'organization'">
                <input :id="id" v-model="form.company_name" class="field-input" maxlength="150" autocomplete="off" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('crm.contacts.phone')" :hint="t('crm.contacts.phone_hint')" :error="errors.phone?.[0]" optional>
                <input :id="id" v-model="form.phone" type="tel" inputmode="tel" class="field-input tabular" dir="ltr" maxlength="20" autocomplete="off" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('crm.contacts.email')" :error="errors.email?.[0]" optional>
                <input :id="id" v-model="form.email" type="email" class="field-input" dir="ltr" maxlength="190" autocomplete="off" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('crm.contacts.tags')" :hint="t('crm.contacts.tags_hint')" :error="errors.tags?.[0]" optional>
                <input :id="id" v-model="form.tags" class="field-input" maxlength="300" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('crm.contacts.source')" :error="errors.source?.[0]" optional>
                <select :id="id" v-model="form.source" class="field-input">
                    <option value="">—</option>
                    <option v-for="source in SOURCES" :key="source" :value="source">{{ t(`crm.sources.${source}`) }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('crm.contacts.owner')" :error="errors.owner_id?.[0]" optional>
                <select :id="id" v-model="form.owner_id" class="field-input">
                    <option value="">—</option>
                    <option v-for="person in setup.data.value?.people ?? []" :key="person.id" :value="person.id">{{ person.name }}</option>
                </select>
            </AppField>
            <div class="grid content-end gap-2">
                <AppSwitch v-model="form.sms_consent" :label="t('crm.contacts.sms_consent')" show-label />
                <AppSwitch v-model="form.email_consent" :label="t('crm.contacts.email_consent')" show-label />
            </div>
            <div v-if="fields.length" class="sm:col-span-2">
                <p class="mb-2 text-[13px] font-semibold">{{ t('crm.fields.more') }}</p>
                <ExtraFields v-model="form.extra" :fields="fields" :errors="errors" :currency="setup.currency.value" />
            </div>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('crm.common.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="crm-contact" :loading="saving" :disabled="!form.name.trim()">{{ t('crm.common.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
