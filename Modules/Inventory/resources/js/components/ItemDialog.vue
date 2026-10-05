<script setup>
import { computed, reactive, ref } from 'vue';
import { Package } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { inventoryApi } from '../api';
import { amountToMinor, milliToText, minorToText, quantityToMilli } from '../lib';

/**
 * Add or change an item: SKU, barcode, name in each language, unit,
 * category, kept in stock or not, batches, sale price, reorder level and
 * quantity. Once an item has moved, the server keeps its unit and kind.
 */
const props = defineProps({ item: { type: Object, default: null }, currency: { type: String, default: null } });
const emit = defineEmits(['close', 'saved']);
const inventory = inventoryApi(currentOrganization().id);
const units = useResource(() => inventory.list('units'));
const categories = useResource(() => inventory.list('categories'));
const activeUnits = computed(() => (units.data.value?.data ?? []).filter((unit) => unit.is_active || unit.id === props.item?.unit_id));
const unitDecimals = computed(() => (units.data.value?.data ?? []).find((unit) => unit.id === form.unit_id)?.decimals ?? 3);

const form = reactive({
    sku: props.item?.sku ?? '',
    barcode: props.item?.barcode ?? '',
    name: { en: props.item?.names.en ?? '', bn: props.item?.names.bn ?? '' },
    unit_id: props.item?.unit_id ?? '',
    category_id: props.item?.category_id ?? '',
    kind: props.item?.kind ?? 'stock',
    track_batches: props.item?.track_batches ?? false,
    price: minorToText(props.item?.sale_price_minor ?? null, props.currency),
    reorder: milliToText(props.item?.reorder_level_milli ?? null),
    reorder_quantity: milliToText(props.item?.reorder_quantity_milli ?? null),
    description: props.item?.description ?? '',
    is_active: props.item?.is_active ?? true,
});
const errors = ref({});
const saving = ref(false);

async function save() {
    const bad = {};
    const price = form.price.trim() === '' ? null : amountToMinor(form.price, props.currency);
    if (form.price.trim() !== '' && price === null) bad.sale_price_minor = [t('inventory.validation.amount')];
    const reorder = form.reorder.trim() === '' ? null : quantityToMilli(form.reorder, unitDecimals.value);
    if (form.reorder.trim() !== '' && reorder === null) bad.reorder_level_milli = [t('inventory.validation.quantity', { decimals: unitDecimals.value })];
    const reorderQuantity = form.reorder_quantity.trim() === '' ? null : quantityToMilli(form.reorder_quantity, unitDecimals.value);
    if (form.reorder_quantity.trim() !== '' && reorderQuantity === null) bad.reorder_quantity_milli = [t('inventory.validation.quantity', { decimals: unitDecimals.value })];
    if (Object.keys(bad).length) {
        errors.value = bad;
        return;
    }
    const body = {
        sku: form.sku.trim(), barcode: form.barcode.trim() || null, name: Object.fromEntries(Object.entries(form.name).filter(([, value]) => value?.trim())),
        unit_id: form.unit_id, category_id: form.category_id || null, kind: form.kind, track_batches: form.kind === 'stock' && form.track_batches,
        sale_price_minor: price, reorder_level_milli: reorder, reorder_quantity_milli: reorderQuantity, description: form.description.trim() || null,
    };
    saving.value = true;
    errors.value = {};
    try {
        const { data } = props.item
            ? await inventory.update('items', props.item.id, { ...body, is_active: form.is_active, base_version: props.item.version })
            : await inventory.create('items', body);
        toast.success(t('inventory.items.saved'));
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
    <AppDialog open :title="item ? t('inventory.items.edit') : t('inventory.items.add')" :icon="Package" size="lg" @close="emit('close')">
        <form id="inventory-item" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
            <AppField v-slot="{ id }" :label="t('inventory.items.sku')" :error="errors.sku?.[0]">
                <input :id="id" v-model="form.sku" class="field-input font-mono" dir="ltr" maxlength="40" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('inventory.items.barcode')" :hint="t('inventory.items.barcode_hint')" :error="errors.barcode?.[0]" optional>
                <input :id="id" v-model="form.barcode" class="field-input font-mono" dir="ltr" maxlength="64" inputmode="numeric" />
            </AppField>
            <div class="sm:col-span-2">
                <TranslatedFields v-model="form.name" :label="t('inventory.common.name')" :errors="errors" required :maxlength="120" />
            </div>
            <AppField v-slot="{ id }" :label="t('inventory.items.unit')" :error="errors.unit_id?.[0]">
                <select :id="id" v-model="form.unit_id" class="field-input">
                    <option value="" disabled>{{ t('inventory.items.choose_unit') }}</option>
                    <option v-for="unit in activeUnits" :key="unit.id" :value="unit.id">{{ unit.name }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('inventory.items.category')" :error="errors.category_id?.[0]" optional>
                <select :id="id" v-model="form.category_id" class="field-input">
                    <option value="">—</option>
                    <option v-for="category in categories.data.value?.data ?? []" :key="category.id" :value="category.id">{{ category.name }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('inventory.items.kind')" :error="errors.kind?.[0]">
                <select :id="id" v-model="form.kind" class="field-input">
                    <option value="stock">{{ t('inventory.kinds.stock') }}</option>
                    <option value="non_stock">{{ t('inventory.kinds.non_stock') }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('inventory.items.price')" :error="errors.sale_price_minor?.[0]" optional>
                <input :id="id" v-model="form.price" inputmode="decimal" class="field-input tabular text-end" dir="ltr" />
            </AppField>
            <template v-if="form.kind === 'stock'">
                <AppField v-slot="{ id }" :label="t('inventory.items.reorder')" :hint="t('inventory.items.reorder_hint')" :error="errors.reorder_level_milli?.[0]" optional>
                    <input :id="id" v-model="form.reorder" inputmode="decimal" class="field-input tabular text-end" dir="ltr" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('inventory.items.reorder_quantity')" :error="errors.reorder_quantity_milli?.[0]" optional>
                    <input :id="id" v-model="form.reorder_quantity" inputmode="decimal" class="field-input tabular text-end" dir="ltr" />
                </AppField>
                <div class="sm:col-span-2">
                    <AppSwitch v-model="form.track_batches" :label="t('inventory.items.track_batches')" show-label />
                </div>
            </template>
            <AppField v-slot="{ id }" :label="t('inventory.items.description')" :error="errors.description?.[0]" optional class="sm:col-span-2">
                <textarea :id="id" v-model="form.description" rows="2" class="field-input" maxlength="500" />
            </AppField>
            <div v-if="item" class="sm:col-span-2">
                <AppSwitch v-model="form.is_active" :label="t('inventory.common.active')" show-label />
            </div>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('inventory.common.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="inventory-item" :loading="saving" :disabled="!form.sku.trim() || !form.name.en?.trim() || !form.unit_id">{{ t('inventory.common.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
