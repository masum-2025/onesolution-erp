<script setup>
import { t } from '@/lib/i18n';
import { indentOf } from '../api';

/**
 * The client's own companies and branches, ticked to share with the provider.
 * A shared company covers its branches (they are shown, not needed too).
 */
const props = defineProps({
    units: { type: Array, required: true },
    modelValue: { type: Array, required: true },
    error: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue']);

const covered = (unit) => props.modelValue.includes(unit.parent_id) && props.units.some((item) => item.id === unit.parent_id);
function toggle(id, on) {
    emit('update:modelValue', on ? [...new Set([...props.modelValue, id])] : props.modelValue.filter((item) => item !== id));
}
</script>

<template>
    <fieldset>
        <legend class="mb-1 text-[13px] font-medium text-fg-2">{{ t('business_network.units.title') }}</legend>
        <p class="mb-2 text-[12.5px] text-muted">{{ t('business_network.units.hint') }}</p>
        <ul class="max-h-72 divide-y divide-line overflow-y-auto rounded-xl border border-line">
            <li v-for="unit in units" :key="unit.id">
                <label class="flex cursor-pointer items-center gap-3 py-2.5 pe-3 text-[13.5px]" :style="{ paddingInlineStart: `${0.75 + indentOf(units, unit) * 1.25}rem` }">
                    <input type="checkbox" class="size-4 accent-brand" :checked="modelValue.includes(unit.id) || covered(unit)" :disabled="covered(unit)" @change="toggle(unit.id, $event.target.checked)" />
                    <span class="min-w-0 flex-1 truncate">{{ unit.name }}</span>
                    <span class="text-[12px] text-muted">{{ covered(unit) ? t('business_network.units.covered') : t(`business_network.units.type_${unit.type}`) }}</span>
                </label>
            </li>
        </ul>
        <p v-if="error" class="mt-1.5 text-[12.5px] text-bad" role="alert">{{ error }}</p>
    </fieldset>
</template>
