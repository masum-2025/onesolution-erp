<script setup>
import { computed, reactive, watch } from 'vue';
import RuleValueInput from './RuleValueInput.vue';
import { isOrdered } from '@/lib/ruleValues';
import { t } from '@/lib/i18n';

/**
 * Limits for the units below: minimum, maximum and/or an allowed list.
 * Emits {min?, max?, allowed?} or undefined when nothing valid is set.
 */
const props = defineProps({
    rule: { type: Object, required: true },
    modelValue: { type: Object, default: null },
});

const emit = defineEmits(['update:modelValue']);

const state = reactive({ useMin: false, useMax: false, useAllowed: false, min: null, max: null, allowed: [] });

const ordered = computed(() => isOrdered(props.rule.type));
const hasOptions = computed(() => Array.isArray(props.rule.options) && props.rule.options.length > 0);

watch(
    () => props.rule.key,
    () => {
        const current = props.modelValue ?? {};
        Object.assign(state, {
            useMin: current.min !== undefined && current.min !== null,
            useMax: current.max !== undefined && current.max !== null,
            useAllowed: Array.isArray(current.allowed),
            min: current.min ?? props.rule.value,
            max: current.max ?? props.rule.value,
            allowed: Array.isArray(current.allowed) ? [...current.allowed] : (props.rule.options ?? []).map((option) => option.value),
        });
        publish();
    },
    { immediate: true },
);

function publish() {
    const bounds = {};
    if (ordered.value && state.useMin) {
        if (state.min === undefined) return emit('update:modelValue', undefined);
        bounds.min = state.min;
    }
    if (ordered.value && state.useMax) {
        if (state.max === undefined) return emit('update:modelValue', undefined);
        bounds.max = state.max;
    }
    if (hasOptions.value && state.useAllowed) {
        if (state.allowed.length === 0) return emit('update:modelValue', undefined);
        bounds.allowed = [...state.allowed];
    }
    emit('update:modelValue', Object.keys(bounds).length ? bounds : undefined);
}

function toggleAllowed(value) {
    state.allowed = state.allowed.includes(value) ? state.allowed.filter((item) => item !== value) : [...state.allowed, value];
    publish();
}
</script>

<template>
    <div class="space-y-4">
        <template v-if="ordered">
            <div class="rounded-xl border border-line p-3.5">
                <label class="flex items-center gap-2.5 text-[13.5px] font-medium text-fg">
                    <input v-model="state.useMin" type="checkbox" class="size-4 rounded accent-brand" @change="publish" />
                    {{ t('rules.bounds.set_min') }}
                </label>
                <div v-if="state.useMin" class="mt-3">
                    <RuleValueInput v-model="state.min" :rule="rule" @update:model-value="publish" />
                </div>
            </div>
            <div class="rounded-xl border border-line p-3.5">
                <label class="flex items-center gap-2.5 text-[13.5px] font-medium text-fg">
                    <input v-model="state.useMax" type="checkbox" class="size-4 rounded accent-brand" @change="publish" />
                    {{ t('rules.bounds.set_max') }}
                </label>
                <div v-if="state.useMax" class="mt-3">
                    <RuleValueInput v-model="state.max" :rule="rule" @update:model-value="publish" />
                </div>
            </div>
        </template>

        <div v-if="hasOptions" class="rounded-xl border border-line p-3.5">
            <label class="flex items-center gap-2.5 text-[13.5px] font-medium text-fg">
                <input v-model="state.useAllowed" type="checkbox" class="size-4 rounded accent-brand" @change="publish" />
                {{ t('rules.bounds.set_allowed') }}
            </label>
            <div v-if="state.useAllowed" class="mt-3 flex flex-wrap gap-2">
                <button
                    v-for="option in rule.options"
                    :key="option.value"
                    type="button"
                    :aria-pressed="state.allowed.includes(option.value)"
                    class="h-8 rounded-full border px-3 text-[12.5px] font-medium transition-colors"
                    :class="state.allowed.includes(option.value) ? 'border-brand bg-brand-soft text-brand-text' : 'border-line-strong bg-surface text-fg-2 hover:bg-subtle'"
                    @click="toggleAllowed(option.value)"
                >
                    {{ option.label }}
                </button>
            </div>
        </div>

        <p v-if="!ordered && !hasOptions" class="text-[13px] text-muted">{{ t('rules.bounds.not_supported') }}</p>
    </div>
</template>
