<script setup>
import { computed } from 'vue';
import { formatMoney, formatNumber } from '@/lib/format';
import { has, t } from '@/lib/i18n';
import { currentOrganization } from '@/lib/session';

/** Read-only view of a table rule value (e.g. tax slabs). */
const props = defineProps({
    rule: { type: Object, required: true },
    rows: { type: Array, required: true },
});

const properties = computed(() => props.rule.schema?.items?.properties ?? {});
const columns = computed(() => Object.keys(Object.keys(properties.value).length ? properties.value : (props.rows[0] ?? {})));
const label = (key) => (has(`rules.columns.${key}`) ? t(`rules.columns.${key}`) : key.replace(/_/g, ' '));
const currency = computed(() => currentOrganization()?.settings?.currency_code ?? 'BDT');

function cell(column, value) {
    if (value === null || value === undefined) return t('rules.value.rest');
    if (properties.value[column]?.['x-format'] === 'money_minor') return formatMoney({ amount: value, currency: currency.value });
    return typeof value === 'number' ? formatNumber(value) : String(value);
}
</script>

<template>
    <div class="overflow-x-auto rounded-lg bg-surface ring-1 ring-line">
        <table class="w-full text-[12.5px]">
            <thead>
                <tr class="border-b border-line text-muted">
                    <th class="w-8 px-3 py-2 text-start font-medium">#</th>
                    <th v-for="column in columns" :key="column" class="px-3 py-2 text-start font-medium">{{ label(column) }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                <tr v-for="(row, index) in rows" :key="index">
                    <td class="tabular px-3 py-1.5 text-faint">{{ formatNumber(index + 1) }}</td>
                    <td v-for="column in columns" :key="column" class="tabular px-3 py-1.5 text-fg">{{ cell(column, row[column]) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
