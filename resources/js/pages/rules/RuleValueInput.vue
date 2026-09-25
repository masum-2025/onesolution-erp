<script setup>
import { computed, ref, watch } from 'vue';
import { Plus, Trash2 } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import { currencyDigits, decimalStringToMinor, localeTag, minorToDecimalString, parseDuration } from '@/lib/format';
import { has, t } from '@/lib/i18n';
import { currentOrganization } from '@/lib/session';
import { schemaRange } from '@/lib/ruleValues';

/**
 * Edits one rule value in the exact shape the server expects. Emits the
 * parsed value, or `undefined` while the input is not valid yet.
 */
const props = defineProps({
    rule: { type: Object, required: true },
    modelValue: { type: null, default: null },
    id: { type: String, default: null },
    describedby: { type: String, default: undefined },
});

const emit = defineEmits(['update:modelValue']);

const type = computed(() => props.rule.type);
const schema = computed(() => props.rule.schema ?? {});
const range = computed(() => schemaRange(props.rule));

const draft = ref('');
const error = ref(null);

function publish(value, message = null) {
    error.value = message;
    emit('update:modelValue', message ? undefined : value);
}

function patternOk(value) {
    if (!schema.value.pattern) return true;
    try {
        return new RegExp(schema.value.pattern).test(value);
    } catch {
        return true;
    }
}

// --- integer / decimal / string -------------------------------------------
function onInteger(text) {
    draft.value = text;
    if (text === '') return props.rule.nullable ? publish(null) : publish(undefined, t('rules.input.required'));
    const value = Number(text);
    if (!Number.isInteger(value)) return publish(undefined, t('rules.input.whole_number'));
    if (range.value.min !== null && value < range.value.min) return publish(undefined, t('rules.input.min', { min: range.value.min }));
    if (range.value.max !== null && value > range.value.max) return publish(undefined, t('rules.input.max', { max: range.value.max }));
    publish(value);
}

function onDecimal(text) {
    draft.value = text;
    const value = text.trim();
    if (value === '' && props.rule.nullable) return publish(null);
    if (!/^-?\d{1,18}(\.\d{1,8})?$/.test(value)) return publish(undefined, t('rules.input.decimal'));
    if (!patternOk(value)) return publish(undefined, t('rules.input.format'));
    publish(value);
}

function onString(text) {
    draft.value = text;
    if (schema.value.minLength && text.length < schema.value.minLength) return publish(undefined, t('rules.input.min_length', { min: schema.value.minLength }));
    if (schema.value.maxLength && text.length > schema.value.maxLength) return publish(undefined, t('rules.input.max_length', { max: schema.value.maxLength }));
    if (!patternOk(text)) return publish(undefined, t('rules.input.format'));
    publish(text);
}

// --- multi_enum ----------------------------------------------------------
const selected = computed(() => (Array.isArray(props.modelValue) ? props.modelValue : []));

function toggleOption(value) {
    const next = selected.value.includes(value) ? selected.value.filter((item) => item !== value) : [...selected.value, value];
    const order = props.rule.options.map((option) => option.value);
    next.sort((a, b) => order.indexOf(a) - order.indexOf(b));
    if (schema.value.maxItems && next.length > schema.value.maxItems) return publish(undefined, t('rules.input.max_items', { max: schema.value.maxItems }));
    if (schema.value.minItems && next.length < schema.value.minItems) return publish(next, t('rules.input.min_items', { min: schema.value.minItems }));
    publish(next);
}

// --- duration -------------------------------------------------------------
const durationAmount = ref('');
const durationUnit = ref('minute');

function durationFrom(value) {
    const parts = parseDuration(value);
    if (!parts) return { amount: '', unit: 'minute' };
    const minutes = parts.day * 1440 + parts.hour * 60 + parts.minute;
    if (minutes > 0 && minutes % 1440 === 0) return { amount: String(minutes / 1440), unit: 'day' };
    if (minutes > 0 && minutes % 60 === 0) return { amount: String(minutes / 60), unit: 'hour' };
    return { amount: String(minutes), unit: 'minute' };
}

function onDuration() {
    const amount = Number(durationAmount.value);
    if (durationAmount.value === '' || !Number.isInteger(amount) || amount < 0) return publish(undefined, t('rules.input.whole_number'));
    const iso = durationUnit.value === 'day' ? `P${amount}D` : durationUnit.value === 'hour' ? `PT${amount}H` : `PT${amount}M`;
    publish(iso);
}

// --- money ----------------------------------------------------------------
const moneyAmount = ref('');
const moneyCurrency = ref('');

function onMoney() {
    if (moneyAmount.value.trim() === '' && props.rule.nullable) return publish(null);
    const currency = moneyCurrency.value.trim().toUpperCase();
    if (!/^[A-Z]{3}$/.test(currency)) return publish(undefined, t('rules.input.currency'));
    const minor = decimalStringToMinor(moneyAmount.value, currencyDigits(currency));
    if (minor === null) return publish(undefined, t('rules.input.amount', { digits: currencyDigits(currency) }));
    publish({ amount: minor, currency });
}

// --- date (MM-DD or YYYY-MM-DD) -------------------------------------------
const monthDay = computed(() => /MM-DD|\(0\[1-9\]\|1\[0-2\]\)-/.test(schema.value.pattern ?? '') || /^\d{2}-\d{2}$/.test(String(props.rule.default ?? '')));
const month = ref('01');
const day = ref('01');
const months = computed(() =>
    Array.from({ length: 12 }, (_, index) => ({
        value: String(index + 1).padStart(2, '0'),
        label: new Intl.DateTimeFormat(localeTag(), { month: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(2000, index, 1))),
    })),
);
const daysInMonth = computed(() => new Date(Date.UTC(2000, Number(month.value), 0)).getUTCDate());

function onMonthDay() {
    if (Number(day.value) > daysInMonth.value) day.value = String(daysInMonth.value).padStart(2, '0');
    publish(`${month.value}-${day.value}`);
}

// --- json -----------------------------------------------------------------
function onJson(text) {
    draft.value = text;
    try {
        publish(JSON.parse(text));
    } catch {
        publish(undefined, t('rules.input.json'));
    }
}

// --- table ----------------------------------------------------------------
const rows = ref([]);
const columns = computed(() => {
    const properties = schema.value.items?.properties;
    if (properties) return Object.entries(properties).map(([key, spec]) => ({ key, spec }));
    const first = Array.isArray(props.modelValue) ? props.modelValue[0] : null;
    return first ? Object.keys(first).map((key) => ({ key, spec: {} })) : [];
});

const columnLabel = (key) => (has(`rules.columns.${key}`) ? t(`rules.columns.${key}`) : key.replace(/_/g, ' '));
const columnTypes = (spec) => (Array.isArray(spec.type) ? spec.type : [spec.type ?? 'string']);
// Money columns (x-format: money_minor) are typed in normal units and stored in minor units.
const isMoney = (spec) => spec['x-format'] === 'money_minor';
const tableCurrency = computed(() => currentOrganization()?.settings?.currency_code ?? 'BDT');

function onTable() {
    const parsed = [];
    for (const row of rows.value) {
        const item = {};
        for (const { key, spec } of columns.value) {
            const raw = String(row[key] ?? '').trim();
            const types = columnTypes(spec);
            if (raw === '') {
                if (!types.includes('null')) return publish(undefined, t('rules.input.cell_required', { column: columnLabel(key) }));
                item[key] = null;
            } else if (isMoney(spec)) {
                const minor = decimalStringToMinor(raw, currencyDigits(tableCurrency.value));
                if (minor === null) return publish(undefined, t('rules.input.cell_number', { column: columnLabel(key) }));
                item[key] = minor;
            } else if (types.includes('integer')) {
                if (!/^-?\d+$/.test(raw)) return publish(undefined, t('rules.input.cell_number', { column: columnLabel(key) }));
                item[key] = Number(raw);
            } else {
                if (spec.pattern && !new RegExp(spec.pattern).test(raw)) return publish(undefined, t('rules.input.cell_format', { column: columnLabel(key) }));
                item[key] = raw;
            }
        }
        parsed.push(item);
    }
    publish(parsed);
}

function addRow() {
    rows.value.push(Object.fromEntries(columns.value.map(({ key }) => [key, ''])));
    onTable();
}

function removeRow(index) {
    rows.value.splice(index, 1);
    onTable();
}

// --- initial state from the incoming value --------------------------------
function load(value) {
    error.value = null;
    switch (type.value) {
        case 'integer':
        case 'decimal':
        case 'string':
            draft.value = value === null || value === undefined ? '' : String(value);
            break;
        case 'json':
            draft.value = JSON.stringify(value ?? {}, null, 2);
            break;
        case 'duration': {
            const parsed = durationFrom(value);
            durationAmount.value = parsed.amount;
            durationUnit.value = parsed.unit;
            break;
        }
        case 'money': {
            const currency = value?.currency ?? currentOrganization()?.settings?.currency_code ?? 'BDT';
            moneyCurrency.value = currency;
            moneyAmount.value = value?.amount !== undefined && value?.amount !== null ? minorToDecimalString(value.amount, currencyDigits(currency)) : '';
            break;
        }
        case 'date':
            if (monthDay.value) {
                const match = String(value ?? '01-01').match(/^(\d{2})-(\d{2})$/);
                month.value = match?.[1] ?? '01';
                day.value = match?.[2] ?? '01';
            } else {
                draft.value = value ?? '';
            }
            break;
        case 'table':
            rows.value = (Array.isArray(value) ? value : []).map((row) =>
                Object.fromEntries(
                    columns.value.map(({ key, spec }) => {
                        const cell = row?.[key];
                        if (cell === null || cell === undefined) return [key, ''];
                        return [key, isMoney(spec) ? minorToDecimalString(cell, currencyDigits(tableCurrency.value)) : String(cell)];
                    }),
                ),
            );
            break;
    }
}

watch(() => props.rule.key, () => load(props.modelValue), { immediate: true });

defineExpose({ error });
</script>

<template>
    <div>
        <!-- boolean -->
        <div v-if="type === 'boolean'" class="flex h-10 items-center">
            <AppSwitch :model-value="!!modelValue" :label="modelValue ? t('rules.value.on') : t('rules.value.off')" show-label @update:model-value="publish($event)" />
        </div>

        <!-- integer -->
        <input
            v-else-if="type === 'integer'"
            :id="id"
            :value="draft"
            type="number"
            inputmode="numeric"
            step="1"
            :min="range.min ?? undefined"
            :max="range.max ?? undefined"
            class="field-input tabular max-w-48"
            :aria-invalid="!!error || undefined"
            :aria-describedby="describedby"
            @input="onInteger($event.target.value)"
        />

        <!-- decimal -->
        <input
            v-else-if="type === 'decimal'"
            :id="id"
            :value="draft"
            inputmode="decimal"
            class="field-input tabular max-w-48"
            :aria-invalid="!!error || undefined"
            :aria-describedby="describedby"
            @input="onDecimal($event.target.value)"
        />

        <!-- enum -->
        <select v-else-if="type === 'enum'" :id="id" :value="modelValue" class="field-input" :aria-describedby="describedby" @change="publish($event.target.value)">
            <option v-for="option in rule.options ?? []" :key="option.value" :value="option.value">{{ option.label }}</option>
        </select>

        <!-- multi_enum -->
        <div v-else-if="type === 'multi_enum'" :id="id" class="flex flex-wrap gap-2" role="group" :aria-describedby="describedby">
            <button
                v-for="option in rule.options ?? []"
                :key="option.value"
                type="button"
                :aria-pressed="selected.includes(option.value)"
                class="h-9 rounded-full border px-3.5 text-[13px] font-medium transition-colors"
                :class="selected.includes(option.value) ? 'border-brand bg-brand-soft text-brand-text' : 'border-line-strong bg-surface text-fg-2 hover:bg-subtle'"
                @click="toggleOption(option.value)"
            >
                {{ option.label }}
            </button>
        </div>

        <!-- duration -->
        <div v-else-if="type === 'duration'" class="flex max-w-sm gap-2">
            <input
                :id="id"
                v-model="durationAmount"
                type="number"
                min="0"
                step="1"
                inputmode="numeric"
                class="field-input tabular"
                :aria-invalid="!!error || undefined"
                :aria-describedby="describedby"
                @input="onDuration"
            />
            <select v-model="durationUnit" class="field-input w-36" :aria-label="t('rules.input.unit')" @change="onDuration">
                <option value="minute">{{ t('rules.input.minutes') }}</option>
                <option value="hour">{{ t('rules.input.hours') }}</option>
                <option value="day">{{ t('rules.input.days') }}</option>
            </select>
        </div>

        <!-- money -->
        <div v-else-if="type === 'money'" class="flex max-w-sm gap-2">
            <input
                :id="id"
                v-model="moneyAmount"
                inputmode="decimal"
                class="field-input tabular"
                :placeholder="rule.nullable ? t('rules.input.empty_is_none') : '0.00'"
                :aria-invalid="!!error || undefined"
                :aria-describedby="describedby"
                @input="onMoney"
            />
            <input v-model="moneyCurrency" maxlength="3" class="field-input w-24 uppercase" :aria-label="t('rules.input.currency_label')" @input="onMoney" />
        </div>

        <!-- time -->
        <input
            v-else-if="type === 'time'"
            :id="id"
            :value="modelValue"
            type="time"
            class="field-input tabular max-w-40"
            :aria-describedby="describedby"
            @input="publish($event.target.value || undefined, $event.target.value ? null : t('rules.input.required'))"
        />

        <!-- date -->
        <div v-else-if="type === 'date' && monthDay" class="flex max-w-sm gap-2">
            <select :id="id" v-model="month" class="field-input" :aria-label="t('rules.input.month')" :aria-describedby="describedby" @change="onMonthDay">
                <option v-for="item in months" :key="item.value" :value="item.value">{{ item.label }}</option>
            </select>
            <select v-model="day" class="field-input w-24" :aria-label="t('rules.input.day')" @change="onMonthDay">
                <option v-for="n in daysInMonth" :key="n" :value="String(n).padStart(2, '0')">{{ n }}</option>
            </select>
        </div>
        <input
            v-else-if="type === 'date'"
            :id="id"
            :value="draft"
            type="date"
            class="field-input max-w-48"
            :aria-describedby="describedby"
            @input="publish($event.target.value || undefined, $event.target.value ? null : t('rules.input.required'))"
        />

        <!-- table -->
        <div v-else-if="type === 'table'" class="overflow-hidden rounded-xl border border-line">
            <div class="overflow-x-auto">
                <table class="w-full text-[13px]">
                    <thead class="bg-subtle text-start text-[12px] text-muted">
                        <tr>
                            <th class="w-10 px-3 py-2 text-start font-medium">#</th>
                            <th v-for="column in columns" :key="column.key" class="px-2 py-2 text-start font-medium">
                                {{ columnLabel(column.key) }}<span v-if="isMoney(column.spec)"> ({{ tableCurrency }})</span>
                            </th>
                            <th class="w-10" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="(row, index) in rows" :key="index">
                            <td class="tabular px-3 text-faint">{{ index + 1 }}</td>
                            <td v-for="column in columns" :key="column.key" class="px-1.5 py-1.5">
                                <input
                                    v-model="row[column.key]"
                                    class="field-input tabular h-9 min-h-9"
                                    :inputmode="isMoney(column.spec) ? 'decimal' : columnTypes(column.spec).includes('integer') ? 'numeric' : 'text'"
                                    :placeholder="columnTypes(column.spec).includes('null') ? t('rules.input.empty_means_rest') : ''"
                                    :aria-label="`${columnLabel(column.key)} ${index + 1}`"
                                    @input="onTable"
                                />
                            </td>
                            <td class="px-1.5">
                                <AppButton variant="ghost" size="icon-sm" :icon="Trash2" :aria-label="t('rules.input.remove_row', { row: index + 1 })" @click="removeRow(index)" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="border-t border-line bg-surface p-2">
                <AppButton variant="ghost" size="sm" :icon="Plus" @click="addRow">{{ t('rules.input.add_row') }}</AppButton>
            </div>
        </div>

        <!-- json -->
        <textarea
            v-else-if="type === 'json'"
            :id="id"
            :value="draft"
            rows="8"
            spellcheck="false"
            class="field-input font-mono text-[12.5px]"
            :aria-invalid="!!error || undefined"
            :aria-describedby="describedby"
            @input="onJson($event.target.value)"
        />

        <!-- string -->
        <input
            v-else
            :id="id"
            :value="draft"
            class="field-input"
            :aria-invalid="!!error || undefined"
            :aria-describedby="describedby"
            @input="onString($event.target.value)"
        />

        <p v-if="error" class="mt-1.5 text-[12.5px] text-bad" role="alert">{{ error }}</p>
    </div>
</template>
