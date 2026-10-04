<script setup>
import { reactive, ref, watch } from 'vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { accountingApi } from '../api';

/**
 * Add or change a customer or vendor. Only what changed is sent, with the
 * version the person saw; a party may be both customer and vendor.
 */
const props = defineProps({
    open: Boolean,
    party: { type: Object, default: null },
    role: { type: String, default: 'customers' },
});
const emit = defineEmits(['close', 'saved']);
const books = accountingApi(currentOrganization().id);

const blank = () => ({ name: '', code: '', phone: '', email: '', tax_number: '', payment_terms_days: '', line1: '', city: '', is_customer: false, is_vendor: false, is_active: true });
const form = reactive(blank());
const errors = ref({});
const saving = ref(false);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        const party = props.party;
        Object.assign(form, blank(), party
            ? { ...party, code: party.code ?? '', phone: party.phone ?? '', email: party.email ?? '', tax_number: party.tax_number ?? '', payment_terms_days: party.payment_terms_days ?? '', line1: party.address?.line1 ?? '', city: party.address?.city ?? '' }
            : { is_customer: props.role === 'customers', is_vendor: props.role === 'vendors' });
        errors.value = {};
    },
);

async function save() {
    const body = {
        name: form.name.trim(),
        code: form.code.trim() || null,
        phone: form.phone.trim() || null,
        email: form.email.trim() || null,
        tax_number: form.tax_number.trim() || null,
        payment_terms_days: form.payment_terms_days === '' ? null : Number(form.payment_terms_days),
        address: form.line1.trim() || form.city.trim() ? { line1: form.line1.trim() || null, city: form.city.trim() || null } : null,
        is_customer: form.is_customer,
        is_vendor: form.is_vendor,
        ...(props.party ? { is_active: form.is_active, base_version: props.party.version } : {}),
    };
    saving.value = true;
    errors.value = {};
    try {
        const { data } = props.party ? await books.updateParty(props.party.id, body) : await books.createParty(body);
        toast.success(t('accounting.parties.saved'));
        emit('saved', data);
        emit('close');
    } catch (error) {
        if (error.code === 'version_conflict') toast.error(t('accounting.common.conflict'));
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length && error.code !== 'version_conflict') toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <AppDialog :open="open" :title="party ? t('accounting.parties.edit') : t(role === 'customers' ? 'accounting.parties.add_customer' : 'accounting.parties.add_vendor')" size="lg" @close="emit('close')">
        <form id="accounting-party" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
            <AppField v-slot="{ id }" :label="t('accounting.parties.name')" :error="fieldError('name')" class="sm:col-span-2">
                <input :id="id" v-model="form.name" class="field-input" maxlength="150" autocomplete="organization" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('accounting.parties.code')" :error="fieldError('code')" optional>
                <input :id="id" v-model="form.code" class="field-input font-mono" dir="ltr" maxlength="30" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('accounting.parties.phone')" :error="fieldError('phone')" optional>
                <input :id="id" v-model="form.phone" type="tel" class="field-input" dir="ltr" maxlength="30" autocomplete="tel" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('accounting.parties.email')" :error="fieldError('email')" optional>
                <input :id="id" v-model="form.email" type="email" class="field-input" dir="ltr" maxlength="190" autocomplete="email" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('accounting.parties.tax_number')" :error="fieldError('tax_number')" optional>
                <input :id="id" v-model="form.tax_number" class="field-input" dir="ltr" maxlength="50" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('accounting.parties.address')" :error="fieldError('address.line1')" optional>
                <input :id="id" v-model="form.line1" class="field-input" maxlength="150" autocomplete="street-address" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('accounting.parties.terms')" :hint="t('accounting.parties.terms_hint')" :error="fieldError('payment_terms_days')" optional>
                <input :id="id" v-model="form.payment_terms_days" type="number" min="0" max="365" inputmode="numeric" class="field-input" />
            </AppField>
            <div class="flex flex-wrap gap-5 sm:col-span-2">
                <AppSwitch v-model="form.is_customer" :disabled="!can('accounting.sell')" :label="t('accounting.parties.is_customer')" show-label />
                <AppSwitch v-model="form.is_vendor" :disabled="!can('accounting.buy')" :label="t('accounting.parties.is_vendor')" show-label />
                <AppSwitch v-if="party" v-model="form.is_active" :label="t('accounting.parties.active')" show-label />
            </div>
            <p v-if="fieldError('is_customer')" class="text-[12.5px] text-bad sm:col-span-2">{{ fieldError('is_customer') }}</p>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('accounting.common.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="accounting-party" :loading="saving" :disabled="form.name.trim().length < 2">{{ t('accounting.common.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
