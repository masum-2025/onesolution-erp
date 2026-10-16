<script setup>
import { computed } from 'vue';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { piecesOf, sheetTotals } from '../lib';

/**
 * An order sheet: sizes across, colours down, pieces in each cell, totals
 * per size, per colour and in all. Read-only when the order cannot change.
 * v-model is {"size|color": text typed}; numbers are checked by the page.
 */
const props = defineProps({
    sizes: { type: Array, required: true },
    colors: { type: Array, required: true },
    modelValue: { type: Object, required: true },
    readonly: Boolean,
});
const emit = defineEmits(['update:modelValue']);
const cells = computed(() => Object.fromEntries(Object.entries(props.modelValue).map(([key, text]) => [key, piecesOf(text) ?? 0])));
const bad = (key) => piecesOf(props.modelValue[key] ?? '') === null;
const totals = computed(() => sheetTotals(props.sizes, props.colors, cells.value));
const set = (key, value) => emit('update:modelValue', { ...props.modelValue, [key]: value });
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full min-w-max text-[13px]">
            <thead>
                <tr class="text-[12px] text-muted">
                    <th scope="col" class="px-2 py-2 text-start font-medium">{{ t('factory_erp.sheet.color') }}</th>
                    <th v-for="size in sizes" :key="size" scope="col" class="px-2 py-2 text-center font-semibold text-fg-2">{{ size }}</th>
                    <th scope="col" class="px-2 py-2 text-end font-medium">{{ t('factory_erp.sheet.total') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                <tr v-for="color in colors" :key="color">
                    <th scope="row" class="whitespace-nowrap px-2 py-1.5 text-start font-medium text-fg">{{ color }}</th>
                    <td v-for="size in sizes" :key="size" class="px-1 py-1">
                        <span v-if="readonly" class="tabular block text-center">{{ cells[`${size}|${color}`] ? formatNumber(cells[`${size}|${color}`]) : '·' }}</span>
                        <input
                            v-else
                            :value="modelValue[`${size}|${color}`] ?? ''"
                            inputmode="numeric"
                            class="field-input tabular w-20 text-center"
                            :class="bad(`${size}|${color}`) ? 'border-bad' : ''"
                            :aria-invalid="bad(`${size}|${color}`)"
                            :aria-label="t('factory_erp.sheet.cell', { size, color })"
                            @input="set(`${size}|${color}`, $event.target.value)"
                        />
                    </td>
                    <td class="tabular px-2 py-1.5 text-end font-semibold">{{ formatNumber(totals.byColor[color]) }}</td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-line">
                    <th scope="row" class="px-2 py-2 text-start text-[12px] font-medium text-muted">{{ t('factory_erp.sheet.total') }}</th>
                    <td v-for="size in sizes" :key="size" class="tabular px-2 py-2 text-center font-semibold">{{ formatNumber(totals.bySize[size]) }}</td>
                    <td class="tabular px-2 py-2 text-end text-[15px] font-bold text-fg">{{ formatNumber(totals.total) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</template>
