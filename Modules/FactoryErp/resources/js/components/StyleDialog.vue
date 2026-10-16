<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Shirt } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import OwnFields from '@/components/OwnFields.vue';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { factoryApi } from '../api';
import { useFactorySetup } from '../setup';
import { fieldToInput, fieldsToApi } from '../lib';
import BuyerPicker from './BuyerPicker.vue';

/**
 * Making or changing a style: buyer (CRM), style number and name, kind of
 * product, sizes and colours (in the order written, comma between), a
 * note and the company's own fields. A size or colour an order uses stays;
 * the server says so.
 */
const props = defineProps({ open: Boolean, style: { type: Object, default: null } });
const emit = defineEmits(['close', 'saved', 'conflict']);
const api = factoryApi(currentOrganization().id);
const setup = useFactorySetup();

const form = reactive({ buyer_id: '', code: '', name: '', product_type: '', sizes: '', colors: '', finished_item_id: '', note: '', extra: {} });
// Finished pieces go into the store as one of the company's Inventory items.
const finishedItems = computed(() => Object.values(setup.items.value).sort((a, b) => a.sku.localeCompare(b.sku)));
const errors = ref({});
const busy = ref(false);
const fields = computed(() => setup.fields('style'));
const list = (text) => text.split(/[,،\n]/).map((value) => value.trim()).filter(Boolean);

watch(() => props.open, (open) => {
    if (!open) return;
    errors.value = {};
    const style = props.style;
    Object.assign(form, {
        buyer_id: style?.buyer_id ?? '', code: style?.code ?? '', name: style?.name ?? '', product_type: style?.product_type ?? '',
        sizes: (style?.sizes ?? ['S', 'M', 'L', 'XL']).join(', '), colors: (style?.colors ?? []).join(', '), note: style?.note ?? '',
        finished_item_id: style?.finished_item_id ?? '',
        extra: Object.fromEntries(fields.value.map((field) => [field.key, fieldToInput(field, style?.extra?.[field.key], setup.rules.value.order_currency)])),
    });
});
const ready = computed(() => form.buyer_id && form.code.trim() && form.name.trim().length >= 2 && list(form.sizes).length && list(form.colors).length);

async function save() {
    const { values, errors: fieldErrors } = fieldsToApi(fields.value, form.extra, setup.rules.value.order_currency);
    if (Object.keys(fieldErrors).length) {
        errors.value = Object.fromEntries(Object.keys(fieldErrors).map((key) => [`extra.${key}`, [t('factory_erp.fields.bad_value')]]));
        return;
    }
    busy.value = true;
    errors.value = {};
    const body = { buyer_id: form.buyer_id, code: form.code.trim(), name: form.name.trim(), product_type: form.product_type.trim() || null, sizes: list(form.sizes), colors: list(form.colors), finished_item_id: form.finished_item_id || null, note: form.note.trim() || null, extra: values };
    try {
        const { data } = props.style ? await api.updateStyle(props.style.id, { ...body, base_version: props.style.version }) : await api.createStyle(body);
        toast.success(t('factory_erp.styles.saved'));
        emit('saved', data);
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('factory_erp.conflict'));
            emit('conflict');
            return;
        }
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AppDialog :open="open" size="lg" :icon="Shirt" :title="style ? t('factory_erp.styles.edit') : t('factory_erp.styles.new')" :description="t('factory_erp.styles.dialog_text')" @close="emit('close')">
        <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="ready && save()">
            <AppField v-slot="{ id }" class="sm:col-span-2" :label="t('factory_erp.fields_.buyer')" :error="errors.buyer_id?.[0] ?? null">
                <BuyerPicker :id="id" v-model="form.buyer_id" :can-add="setup.can('merchandise')" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('factory_erp.fields_.style_code')" :error="errors.code?.[0] ?? null">
                <input :id="id" v-model="form.code" class="field-input font-mono" dir="ltr" maxlength="40" placeholder="NW-2611" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('factory_erp.fields_.product_type')" optional>
                <input :id="id" v-model="form.product_type" class="field-input" maxlength="40" :placeholder="t('factory_erp.fields_.product_type_hint')" />
            </AppField>
            <AppField v-slot="{ id }" class="sm:col-span-2" :label="t('factory_erp.fields_.style_name')" :error="errors.name?.[0] ?? null">
                <input :id="id" v-model="form.name" class="field-input" maxlength="150" />
            </AppField>
            <AppField v-slot="{ id, describedby }" :label="t('factory_erp.fields_.sizes')" :hint="t('factory_erp.fields_.list_hint')" :error="errors.sizes?.[0] ?? null">
                <input :id="id" v-model="form.sizes" class="field-input" dir="ltr" :aria-describedby="describedby" placeholder="S, M, L, XL" />
            </AppField>
            <AppField v-slot="{ id, describedby }" :label="t('factory_erp.fields_.colors')" :hint="t('factory_erp.fields_.list_hint')" :error="errors.colors?.[0] ?? null">
                <input :id="id" v-model="form.colors" class="field-input" :aria-describedby="describedby" placeholder="Navy, Red" />
            </AppField>
            <AppField v-slot="{ id, describedby }" class="sm:col-span-2" :label="t('factory_erp.fields_.finished_item')" :hint="t('factory_erp.fields_.finished_item_hint')" :error="errors.finished_item_id?.[0] ?? null" optional>
                <select :id="id" v-model="form.finished_item_id" class="field-input" :aria-describedby="describedby">
                    <option value="">{{ t('factory_erp.fields_.finished_item_none') }}</option>
                    <option v-for="item in finishedItems" :key="item.id" :value="item.id">{{ item.sku }} · {{ item.name }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" class="sm:col-span-2" :label="t('factory_erp.fields_.note')" optional>
                <textarea :id="id" v-model="form.note" class="field-input min-h-16" maxlength="2000" />
            </AppField>
            <div v-if="fields.length" class="sm:col-span-2">
                <OwnFields v-model="form.extra" :fields="fields" :errors="errors" :currency="setup.rules.value.order_currency" />
            </div>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('factory_erp.cancel') }}</AppButton>
            <AppButton variant="primary" :loading="busy" :disabled="!ready" @click="save">{{ t('factory_erp.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
