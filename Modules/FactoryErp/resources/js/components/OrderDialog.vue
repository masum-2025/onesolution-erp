<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { ClipboardList } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import OwnFields from '@/components/OwnFields.vue';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { factoryApi } from '../api';
import { useFactorySetup } from '../setup';
import { amountToMinor, fieldsToApi, minorToText } from '../lib';

/**
 * A new buyer's order: the style (its buyer comes with it), the buyer's PO
 * number, ship date, currency and price per piece, and own fields. The
 * pieces by size and colour are entered on the order's page next.
 */
const props = defineProps({ open: Boolean, styleId: { type: String, default: '' } });
const emit = defineEmits(['close']);
const api = factoryApi(currentOrganization().id);
const setup = useFactorySetup();
const router = useRouter();

const styles = ref([]);
const buyers = ref({});
onMounted(async () => {
    try {
        const answer = await api.styles({ active: 1 });
        styles.value = answer.data;
        buyers.value = answer.meta.buyers ?? {};
    } catch {
        styles.value = [];
    }
});

const form = reactive({ style_id: '', po_number: '', ship_on: '', currency: '', price: '', extra: {} });
const errors = ref({});
const busy = ref(false);
let opId = '';
const fields = computed(() => setup.fields('order'));
watch(() => props.open, (open) => {
    if (!open) return;
    errors.value = {};
    opId = crypto.randomUUID?.() ?? `${Date.now()}-${Math.random()}`;
    Object.assign(form, { style_id: props.styleId || '', po_number: '', ship_on: '', currency: setup.rules.value.order_currency ?? 'USD', price: '', extra: Object.fromEntries(fields.value.map((field) => [field.key, ''])) });
});
const price = computed(() => (form.price.trim() === '' ? 0 : amountToMinor(form.price, form.currency)));
const ready = computed(() => form.style_id && form.ship_on && /^[A-Z]{3}$/.test(form.currency) && price.value !== null);

async function save() {
    const { values, errors: fieldErrors } = fieldsToApi(fields.value, form.extra, form.currency);
    if (Object.keys(fieldErrors).length) {
        errors.value = Object.fromEntries(Object.keys(fieldErrors).map((key) => [`extra.${key}`, [t('factory_erp.fields.bad_value')]]));
        return;
    }
    busy.value = true;
    errors.value = {};
    try {
        const { data } = await api.createOrder({
            style_id: form.style_id, po_number: form.po_number.trim() || null, ship_on: form.ship_on, currency: form.currency,
            unit_price_minor: price.value, extra: values, op_id: opId,
        });
        toast.success(t('factory_erp.orders.made', { number: data.number }));
        emit('close');
        router.push({ name: 'fac-order', params: { id: data.id } });
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AppDialog :open="open" size="lg" :icon="ClipboardList" :title="t('factory_erp.orders.new')" :description="t('factory_erp.orders.dialog_text')" @close="emit('close')">
        <p v-if="!styles.length" class="rounded-xl bg-warn-soft px-4 py-3 text-[13px] text-warn">{{ t('factory_erp.orders.no_styles') }}</p>
        <form v-else class="grid gap-4 sm:grid-cols-2" @submit.prevent="ready && save()">
            <AppField v-slot="{ id }" class="sm:col-span-2" :label="t('factory_erp.fields_.style')" :error="errors.style_id?.[0] ?? null">
                <select :id="id" v-model="form.style_id" class="field-input">
                    <option value="" disabled>{{ t('factory_erp.orders.pick_style') }}</option>
                    <option v-for="style in styles" :key="style.id" :value="style.id">{{ style.code }} · {{ style.name }}<template v-if="buyers[style.buyer_id]"> ({{ buyers[style.buyer_id].name }})</template></option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('factory_erp.fields_.po_number')" optional>
                <input :id="id" v-model="form.po_number" class="field-input font-mono" dir="ltr" maxlength="60" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('factory_erp.fields_.ship_on')" :error="errors.ship_on?.[0] ?? null">
                <input :id="id" v-model="form.ship_on" type="date" class="field-input" :min="setup.data.value?.today" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('factory_erp.fields_.currency')" :error="errors.currency?.[0] ?? null">
                <input :id="id" v-model="form.currency" class="field-input font-mono uppercase" dir="ltr" maxlength="3" @input="form.currency = form.currency.toUpperCase()" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('factory_erp.fields_.unit_price', { currency: form.currency })" :error="(price === null ? t('factory_erp.fields_.price_bad') : null) ?? errors.unit_price_minor?.[0] ?? null">
                <input :id="id" v-model="form.price" inputmode="decimal" class="field-input tabular" dir="ltr" :placeholder="minorToText(0, form.currency)" />
            </AppField>
            <div v-if="fields.length" class="sm:col-span-2">
                <OwnFields v-model="form.extra" :fields="fields" :errors="errors" :currency="form.currency" />
            </div>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('factory_erp.cancel') }}</AppButton>
            <AppButton v-if="styles.length" variant="primary" :loading="busy" :disabled="!ready" @click="save">{{ t('factory_erp.orders.make') }}</AppButton>
            <AppButton v-else variant="primary" :to="{ name: 'fac-styles', query: { new: 1 } }" @click="emit('close')">{{ t('factory_erp.styles.new') }}</AppButton>
        </template>
    </AppDialog>
</template>
