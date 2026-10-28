<script setup>
import { computed, reactive, ref, watch } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { expiryFrom } from '../lib';

/**
 * A new register line: the factory and the audit service, its provider and
 * kind, and (for people allowed) its official dates. The expiry day is
 * proposed from the service's usual months once the last audit day is in.
 */
const props = defineProps({
    open: Boolean,
    options: { type: Object, default: null },
    preset: { type: Object, default: () => ({}) }, // { unit_id, service_id } from an empty grid cell
    calls: { type: Object, required: true },
});
const emit = defineEmits(['close', 'saved']);

const blank = () => ({ unit_id: '', service_id: '', provider_id: '', audit_type_id: '', last_audit_on: '', expires_on: '', result: '', certificate_no: '' });
const form = reactive(blank());
const errors = ref({});
const saving = ref(false);
const touchedExpiry = ref(false);
watch(() => props.open, (open) => {
    if (!open) return;
    Object.assign(form, blank(), props.preset);
    form.unit_id ||= props.options?.factories[0]?.id ?? '';
    errors.value = {};
    touchedExpiry.value = false;
});

const months = computed(() => props.options?.services.find((service) => service.id === form.service_id)?.validity_months ?? null);
// Proposed until the expiry day is typed by hand.
watch(() => [form.last_audit_on, months.value], () => {
    if (!touchedExpiry.value) form.expires_on = expiryFrom(form.last_audit_on, months.value);
});
const indent = (factory) => '— '.repeat(Math.max(0, factory.depth - (props.options?.factories[0]?.depth ?? 0)));

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const body = Object.fromEntries(Object.entries(form).filter(([, value]) => value !== ''));
        const { data } = await props.calls.add(body);
        toast.success(t('compliance.add.saved'));
        emit('saved', data);
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
    <AppDialog :open="open" :title="t('compliance.add.title')" @close="emit('close')">
        <form v-if="options" id="compliance-add" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
            <AppField v-slot="{ id }" :label="t('compliance.fields.factory')" :error="fieldError('unit_id')">
                <select :id="id" v-model="form.unit_id" class="field-input">
                    <option v-for="factory in options.factories" :key="factory.id" :value="factory.id">{{ indent(factory) }}{{ factory.name }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('compliance.fields.service')" :error="fieldError('service_id')">
                <select :id="id" v-model="form.service_id" class="field-input">
                    <option value="" disabled>{{ t('compliance.common.choose') }}</option>
                    <option v-for="service in options.services" :key="service.id" :value="service.id">{{ service.name }} ({{ service.code }})</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('compliance.fields.provider')" :error="fieldError('provider_id')" optional>
                <select :id="id" v-model="form.provider_id" class="field-input">
                    <option value="">{{ t('compliance.common.none') }}</option>
                    <option v-for="provider in options.providers" :key="provider.id" :value="provider.id">{{ provider.name }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('compliance.fields.audit_type')" :error="fieldError('audit_type_id')" optional>
                <select :id="id" v-model="form.audit_type_id" class="field-input">
                    <option value="">{{ t('compliance.common.none') }}</option>
                    <option v-for="type in options.types" :key="type.id" :value="type.id">{{ type.name }}</option>
                </select>
            </AppField>
            <template v-if="options.can.dates">
                <AppField v-slot="{ id }" :label="t('compliance.fields.last_audit_on')" :error="fieldError('last_audit_on')" optional>
                    <input :id="id" v-model="form.last_audit_on" type="date" class="field-input" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('compliance.fields.expires_on')" :hint="months ? t('compliance.add.expiry_hint', { months }) : ''" :error="fieldError('expires_on')" optional>
                    <input :id="id" v-model="form.expires_on" type="date" class="field-input" @input="touchedExpiry = true" />
                </AppField>
            </template>
            <p v-else class="rounded-lg bg-subtle px-3 py-2 text-[13px] text-fg-2 sm:col-span-2">{{ t('compliance.add.no_dates') }}</p>
            <AppField v-slot="{ id }" :label="t('compliance.fields.result')" :error="fieldError('result')" optional>
                <input :id="id" v-model="form.result" class="field-input" maxlength="60" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('compliance.fields.certificate_no')" :error="fieldError('certificate_no')" optional>
                <input :id="id" v-model="form.certificate_no" class="field-input" dir="ltr" maxlength="100" />
            </AppField>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('compliance.common.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="compliance-add" :loading="saving" :disabled="!form.unit_id || !form.service_id">{{ t('compliance.add.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
