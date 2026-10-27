<script setup>
import { computed } from 'vue';
import AppField from '@/components/AppField.vue';
import { t } from '@/lib/i18n';
import { departmentsOf, placeOf, workPlaces } from '../lib';

/**
 * Where someone works, chosen in two steps: the branch (or the company), then
 * optionally a department of it. The value is one unit id, as the server
 * takes it: the department if one is chosen, else the branch. Closed units
 * are not offered; the server refuses them too.
 */
const props = defineProps({
    units: { type: Array, required: true }, // organizations as /api/organizations gives them
    modelValue: { type: String, default: '' },
    error: { type: String, default: null },
    placeLabel: { type: String, default: '' },
    optional: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const places = computed(() => workPlaces(props.units));
const place = computed(() => placeOf(props.units, props.modelValue));
const departments = computed(() => departmentsOf(props.units, place.value));
const department = computed(() => (props.modelValue && props.modelValue !== place.value ? props.modelValue : ''));
const indent = (unit) => '— '.repeat(Math.max(0, unit.depth - (places.value[0]?.depth ?? 0)));
</script>

<template>
    <div class="grid gap-4 sm:col-span-2 sm:grid-cols-2">
        <AppField v-slot="{ id }" :label="placeLabel || t('hrm.fields.branch')" :error="error" :optional="optional">
            <select :id="id" :value="place" class="field-input" @change="emit('update:modelValue', $event.target.value)">
                <option v-if="optional || !place" value="">{{ optional ? t('hrm.fields.no_change') : t('hrm.fields.choose') }}</option>
                <option v-for="unit in places" :key="unit.id" :value="unit.id">{{ indent(unit) }}{{ unit.display_name }}</option>
            </select>
        </AppField>
        <AppField v-slot="{ id }" :label="t('hrm.fields.department')" :hint="place && !departments.length ? t('hrm.fields.no_departments') : ''" optional>
            <select :id="id" :value="department" class="field-input" :disabled="!place || !departments.length" @change="emit('update:modelValue', $event.target.value || place)">
                <option value="">{{ t('hrm.fields.no_department') }}</option>
                <option v-for="unit in departments" :key="unit.id" :value="unit.id">{{ unit.display_name }}</option>
            </select>
        </AppField>
    </div>
</template>
