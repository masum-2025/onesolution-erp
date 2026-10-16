<script setup>
import { computed, ref, watch } from 'vue';
import { ListOrdered, Plus, Save, Trash2 } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import { currentOrganization } from '@/lib/session';
import { formatDecimal, formatMoney } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { factoryApi } from '../api';
import { useFactorySetup } from '../setup';
import { amountToMinor, milliToText, minorToText, quantityToMilli } from '../lib';

/**
 * A style's operation bulletin: each sewing job, its machine, standard
 * minutes (SMV, up to three decimals) and the piece rate an operator is
 * paid for it. The style's SMV is their sum. Piece rates are pay: only
 * managers set or change them (the server checks; others see them
 * read-only). Saved as a whole.
 */
const props = defineProps({ styleRecord: { type: Object, required: true }, editable: Boolean });
const emit = defineEmits(['saved']);
const api = factoryApi(currentOrganization().id);
const setup = useFactorySetup();
const currency = computed(() => setup.currency.value);
const canRate = computed(() => setup.can('manage'));

const rows = ref([]);
watch(() => props.styleRecord, (style) => {
    rows.value = (style.operations ?? []).map((operation) => ({
        name: operation.name, machine: operation.machine ?? '', smv: milliToText(operation.smv_milli),
        rate: operation.piece_rate_minor === null ? '' : minorToText(operation.piece_rate_minor, currency.value),
    }));
}, { immediate: true });
const add = () => rows.value.push({ name: '', machine: '', smv: '', rate: '' });
const rateMinor = (row) => (row.rate.trim() === '' ? null : amountToMinor(row.rate, currency.value));
const bad = (row) => row.name.trim().length < 2 || quantityToMilli(row.smv) === null || (row.rate.trim() !== '' && rateMinor(row) === null);
const total = computed(() => rows.value.reduce((sum, row) => sum + (quantityToMilli(row.smv) ?? 0), 0));
const ready = computed(() => rows.value.every((row) => !bad(row)));
const busy = ref(false);

async function save() {
    busy.value = true;
    try {
        const { data } = await api.saveOperations(props.styleRecord.id, {
            base_version: props.styleRecord.version,
            lines: rows.value.map((row) => ({ name: row.name.trim(), machine: row.machine.trim() || null, smv_milli: quantityToMilli(row.smv), piece_rate_minor: rateMinor(row) })),
        });
        toast.success(t('factory_erp.operations.saved'));
        emit('saved', data);
    } catch (error) {
        toast.error(error.code === 'version_conflict' ? t('factory_erp.conflict') : error.status === 403 ? t('factory_erp.operations.rates_need_manager') : error.message);
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
                <h2 class="text-[14px] font-semibold text-fg">{{ t('factory_erp.operations.title') }}</h2>
                <p class="text-[12.5px] text-muted">{{ t('factory_erp.operations.text', { smv: formatDecimal(milliToText(total)) }) }}</p>
            </div>
            <template v-if="editable">
                <AppButton size="sm" :icon="Plus" @click="add">{{ t('factory_erp.operations.add') }}</AppButton>
                <AppButton size="sm" variant="primary" :icon="Save" :loading="busy" :disabled="!ready" @click="save">{{ t('factory_erp.save') }}</AppButton>
            </template>
        </header>
        <EmptyState v-if="!rows.length" compact :icon="ListOrdered" :title="t('factory_erp.operations.empty_title')" :text="t('factory_erp.operations.empty_text')" />
        <div v-else class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-[13px]">
                <thead class="bg-subtle/60 text-[11.5px] text-muted">
                    <tr>
                        <th scope="col" class="w-10 px-4 py-2 text-start font-medium">#</th>
                        <th scope="col" class="px-2 py-2 text-start font-medium">{{ t('factory_erp.operations.name') }}</th>
                        <th scope="col" class="px-2 py-2 text-start font-medium">{{ t('factory_erp.operations.machine') }}</th>
                        <th scope="col" class="px-2 py-2 text-end font-medium">{{ t('factory_erp.operations.smv') }}</th>
                        <th scope="col" class="px-2 py-2 text-end font-medium">{{ t('factory_erp.operations.rate', { currency: currency ?? '' }) }}</th>
                        <th v-if="editable" scope="col" class="w-10" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="(row, index) in rows" :key="index">
                        <td class="tabular px-4 py-1.5 text-muted">{{ formatDecimal(String(index + 1)) }}</td>
                        <td class="px-2 py-1.5">
                            <input v-if="editable" v-model="row.name" class="field-input" maxlength="120" :aria-label="t('factory_erp.operations.name')" :placeholder="t('factory_erp.operations.name_hint')" />
                            <span v-else class="font-medium text-fg">{{ row.name }}</span>
                        </td>
                        <td class="px-2 py-1.5">
                            <input v-if="editable" v-model="row.machine" class="field-input w-32" maxlength="40" :aria-label="t('factory_erp.operations.machine')" placeholder="SNLS" />
                            <span v-else class="text-muted">{{ row.machine }}</span>
                        </td>
                        <td class="px-2 py-1.5 text-end">
                            <input v-if="editable" v-model="row.smv" inputmode="decimal" class="field-input tabular w-24 text-end" dir="ltr" :aria-label="t('factory_erp.operations.smv')" :aria-invalid="quantityToMilli(row.smv) === null" />
                            <span v-else class="tabular">{{ formatDecimal(row.smv) }}</span>
                        </td>
                        <td class="px-2 py-1.5 text-end">
                            <input v-if="editable && canRate" v-model="row.rate" inputmode="decimal" class="field-input tabular w-24 text-end" dir="ltr" :aria-label="t('factory_erp.operations.rate', { currency: currency ?? '' })" />
                            <span v-else class="tabular">{{ row.rate === '' ? '—' : formatMoney({ amount: rateMinor(row), currency }) }}</span>
                        </td>
                        <td v-if="editable" class="px-2 py-1.5">
                            <AppButton size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('factory_erp.operations.remove')" @click="rows.splice(index, 1)" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
