<script setup>
import { computed, ref, watch } from 'vue';
import { Plus, Save, Trash2 } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { factoryApi } from '../api';
import { useFactorySetup } from '../setup';
import { bpToPercent, milliToText, percentToBp, quantityToMilli } from '../lib';

/**
 * A style's bill of materials: each line an Inventory item, how much per
 * dozen or piece (as the style keeps it), wastage %, and maybe only one
 * size and/or colour. Saved as a whole; the server checks every line again.
 */
const props = defineProps({ styleRecord: { type: Object, required: true }, editable: Boolean });
const emit = defineEmits(['saved']);
const api = factoryApi(currentOrganization().id);
const setup = useFactorySetup();

const rows = ref([]);
watch(() => props.styleRecord, (style) => {
    rows.value = (style.bom ?? []).map((line) => ({
        item_id: line.item_id, consumption: milliToText(line.consumption_milli), wastage: bpToPercent(line.wastage_bp),
        size: line.size ?? '', color: line.color ?? '', note: line.note ?? '',
    }));
}, { immediate: true });
const add = () => rows.value.push({ item_id: '', consumption: '', wastage: bpToPercent(setup.rules.value.default_wastage_bp ?? 500), size: '', color: '', note: '' });
const per = computed(() => t(`factory_erp.bom.per_${props.styleRecord.consumption_basis}`));
const bad = (row) => !row.item_id || quantityToMilli(row.consumption) === null || (row.wastage !== '' && percentToBp(row.wastage) === null);
const ready = computed(() => rows.value.every((row) => !bad(row)));
const busy = ref(false);

async function save() {
    busy.value = true;
    try {
        const { data } = await api.saveBom(props.styleRecord.id, {
            base_version: props.styleRecord.version,
            lines: rows.value.map((row) => ({
                item_id: row.item_id, consumption_milli: quantityToMilli(row.consumption), wastage_bp: row.wastage === '' ? null : percentToBp(row.wastage),
                size: row.size || null, color: row.color || null, note: row.note.trim() || null,
            })),
        });
        toast.success(t('factory_erp.bom.saved'));
        emit('saved', data);
    } catch (error) {
        toast.error(error.code === 'version_conflict' ? t('factory_erp.conflict') : error.message);
        if (error.code === 'version_conflict') emit('saved', null);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section class="card">
        <header class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-line px-5 py-3">
            <div class="min-w-0 flex-1">
                <h2 class="text-[14px] font-semibold text-fg">{{ t('factory_erp.bom.title') }}</h2>
                <p class="text-[12.5px] text-muted">{{ t('factory_erp.bom.text', { per }) }}</p>
            </div>
            <template v-if="editable">
                <AppButton size="sm" :icon="Plus" @click="add">{{ t('factory_erp.bom.add') }}</AppButton>
                <AppButton size="sm" variant="primary" :icon="Save" :loading="busy" :disabled="!ready" @click="save">{{ t('factory_erp.save') }}</AppButton>
            </template>
        </header>
        <EmptyState v-if="!rows.length" compact :icon="Plus" :title="t('factory_erp.bom.empty_title')" :text="t('factory_erp.bom.empty_text')" />
        <div v-else class="overflow-x-auto">
            <table class="w-full min-w-[760px] text-[13px]">
                <thead class="bg-subtle/60 text-[11.5px] text-muted">
                    <tr>
                        <th scope="col" class="px-4 py-2 text-start font-medium">{{ t('factory_erp.bom.item') }}</th>
                        <th scope="col" class="px-2 py-2 text-end font-medium">{{ t('factory_erp.bom.consumption', { per }) }}</th>
                        <th scope="col" class="px-2 py-2 text-end font-medium">{{ t('factory_erp.bom.wastage') }}</th>
                        <th scope="col" class="px-2 py-2 text-start font-medium">{{ t('factory_erp.bom.only_size') }}</th>
                        <th scope="col" class="px-2 py-2 text-start font-medium">{{ t('factory_erp.bom.only_color') }}</th>
                        <th scope="col" class="px-2 py-2 text-start font-medium">{{ t('factory_erp.bom.note') }}</th>
                        <th v-if="editable" scope="col" class="w-10" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="(row, index) in rows" :key="index">
                        <td class="px-4 py-1.5">
                            <select v-if="editable" v-model="row.item_id" class="field-input" :aria-label="t('factory_erp.bom.item')">
                                <option value="" disabled>{{ t('factory_erp.bom.pick_item') }}</option>
                                <option v-for="item in setup.data.value?.items ?? []" :key="item.id" :value="item.id">{{ item.sku }} · {{ item.name }}</option>
                            </select>
                            <span v-else>{{ setup.itemName(row.item_id) }}</span>
                        </td>
                        <td class="px-2 py-1.5 text-end">
                            <span class="inline-flex items-center gap-1.5">
                                <input v-if="editable" v-model="row.consumption" inputmode="decimal" class="field-input tabular w-24 text-end" dir="ltr" :aria-label="t('factory_erp.bom.consumption', { per })" :aria-invalid="quantityToMilli(row.consumption) === null" />
                                <span v-else class="tabular">{{ row.consumption }}</span>
                                <span class="text-[11.5px] text-muted">{{ setup.unitCode(row.item_id) }}</span>
                            </span>
                        </td>
                        <td class="px-2 py-1.5 text-end">
                            <span class="inline-flex items-center gap-1">
                                <input v-if="editable" v-model="row.wastage" inputmode="decimal" class="field-input tabular w-16 text-end" dir="ltr" :aria-label="t('factory_erp.bom.wastage')" />
                                <span v-else class="tabular">{{ row.wastage }}</span>%
                            </span>
                        </td>
                        <td class="px-2 py-1.5">
                            <select v-if="editable" v-model="row.size" class="field-input w-24" :aria-label="t('factory_erp.bom.only_size')">
                                <option value="">{{ t('factory_erp.bom.all') }}</option>
                                <option v-for="size in styleRecord.sizes" :key="size" :value="size">{{ size }}</option>
                            </select>
                            <span v-else>{{ row.size || t('factory_erp.bom.all') }}</span>
                        </td>
                        <td class="px-2 py-1.5">
                            <select v-if="editable" v-model="row.color" class="field-input w-28" :aria-label="t('factory_erp.bom.only_color')">
                                <option value="">{{ t('factory_erp.bom.all') }}</option>
                                <option v-for="color in styleRecord.colors" :key="color" :value="color">{{ color }}</option>
                            </select>
                            <span v-else>{{ row.color || t('factory_erp.bom.all') }}</span>
                        </td>
                        <td class="px-2 py-1.5">
                            <input v-if="editable" v-model="row.note" class="field-input" maxlength="200" :aria-label="t('factory_erp.bom.note')" />
                            <span v-else class="text-muted">{{ row.note }}</span>
                        </td>
                        <td v-if="editable" class="px-2 py-1.5">
                            <AppButton size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('factory_erp.bom.remove')" @click="rows.splice(index, 1)" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
