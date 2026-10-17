<script setup>
import { computed } from 'vue';
import AppField from '@/components/AppField.vue';
import { t } from '@/lib/i18n';
import BuyerPicker from './BuyerPicker.vue';

/**
 * The fields of a letter of credit (shared by making one and its page):
 * buyer (only when making), number, dates, banks, currency and amount with
 * a tolerance %, terms, ports, payment days and the documents it asks for.
 * v-model is a plain object of texts; the page turns it into the API's.
 */
const props = defineProps({
    modelValue: { type: Object, required: true },
    documents: { type: Array, required: true },
    making: Boolean,
    disabled: Boolean,
    errors: { type: Object, default: () => ({}) },
});
const form = computed(() => props.modelValue);
const toggle = (key) => {
    const list = form.value.documents;
    const index = list.indexOf(key);
    if (index >= 0) list.splice(index, 1);
    else list.push(key);
};
</script>

<template>
    <div class="grid gap-3 sm:grid-cols-3">
        <AppField v-if="making" v-slot="{ id }" class="sm:col-span-3" :label="t('factory_erp.fields_.buyer')" :error="errors.buyer_id?.[0] ?? null">
            <BuyerPicker :id="id" v-model="form.buyer_id" />
        </AppField>
        <AppField v-slot="{ id }" :label="t('factory_erp.lcs.number')" :error="errors.number?.[0] ?? null">
            <input :id="id" v-model="form.number" class="field-input font-mono" dir="ltr" maxlength="60" :disabled="disabled" placeholder="LC-0042/26" />
        </AppField>
        <AppField v-slot="{ id }" :label="t('factory_erp.lcs.issued_on')">
            <input :id="id" v-model="form.issued_on" type="date" class="field-input" :disabled="disabled" />
        </AppField>
        <AppField v-slot="{ id }" :label="t('factory_erp.lcs.payment_days')" :hint="t('factory_erp.lcs.payment_days_hint')">
            <input :id="id" v-model="form.payment_days" inputmode="numeric" class="field-input tabular" :disabled="disabled" />
        </AppField>
        <AppField v-slot="{ id }" :label="t('factory_erp.lcs.issuing_bank')" :error="errors.issuing_bank?.[0] ?? null">
            <input :id="id" v-model="form.issuing_bank" class="field-input" maxlength="150" :disabled="disabled" />
        </AppField>
        <AppField v-slot="{ id }" class="sm:col-span-2" :label="t('factory_erp.lcs.advising_bank')">
            <input :id="id" v-model="form.advising_bank" class="field-input" maxlength="150" :disabled="disabled" />
        </AppField>
        <AppField v-slot="{ id }" :label="t('factory_erp.lcs.currency')">
            <input :id="id" v-model="form.currency" class="field-input font-mono uppercase" dir="ltr" maxlength="3" :disabled="disabled" placeholder="USD" />
        </AppField>
        <AppField v-slot="{ id }" :label="t('factory_erp.lcs.amount')" :error="errors.amount_minor?.[0] ?? null">
            <input :id="id" v-model="form.amount" inputmode="decimal" class="field-input tabular" dir="ltr" :disabled="disabled" />
        </AppField>
        <AppField v-slot="{ id }" :label="t('factory_erp.lcs.tolerance')">
            <input :id="id" v-model="form.tolerance" inputmode="decimal" class="field-input tabular" dir="ltr" :disabled="disabled" placeholder="5" />
        </AppField>
        <AppField v-slot="{ id }" :label="t('factory_erp.lcs.latest_ship_on')" :error="errors.latest_ship_on?.[0] ?? null">
            <input :id="id" v-model="form.latest_ship_on" type="date" class="field-input" :disabled="disabled" />
        </AppField>
        <AppField v-slot="{ id }" :label="t('factory_erp.lcs.expires_on')">
            <input :id="id" v-model="form.expires_on" type="date" class="field-input" :disabled="disabled" />
        </AppField>
        <div class="flex flex-col justify-end gap-2 text-[13px]">
            <label class="flex items-center gap-2"><input v-model="form.partial_allowed" type="checkbox" class="size-4 accent-brand" :disabled="disabled" />{{ t('factory_erp.lcs.partial_allowed') }}</label>
            <label class="flex items-center gap-2"><input v-model="form.transshipment_allowed" type="checkbox" class="size-4 accent-brand" :disabled="disabled" />{{ t('factory_erp.lcs.transshipment_allowed') }}</label>
        </div>
        <AppField v-slot="{ id }" :label="t('factory_erp.lcs.port_loading')">
            <input :id="id" v-model="form.port_loading" class="field-input" maxlength="100" :disabled="disabled" />
        </AppField>
        <AppField v-slot="{ id }" :label="t('factory_erp.lcs.port_discharge')">
            <input :id="id" v-model="form.port_discharge" class="field-input" maxlength="100" :disabled="disabled" />
        </AppField>
        <fieldset class="sm:col-span-3">
            <legend class="mb-1 text-[13px] font-medium text-fg">{{ t('factory_erp.lcs.documents') }}</legend>
            <div class="flex flex-wrap gap-2">
                <label v-for="key in documents" :key="key" class="flex items-center gap-2 rounded-lg border border-line px-2.5 py-1.5 text-[13px]">
                    <input type="checkbox" class="size-4 accent-brand" :checked="form.documents.includes(key)" :disabled="disabled" @change="toggle(key)" />{{ t(`factory_erp.lcs.docs.${key}`) }}
                </label>
            </div>
        </fieldset>
    </div>
</template>
