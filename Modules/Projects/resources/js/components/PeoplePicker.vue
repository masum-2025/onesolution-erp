<script setup>
import { computed } from 'vue';
import { Star } from 'lucide-vue-next';
import { t } from '@/lib/i18n';
import { initials } from '../lib';

/**
 * The people a task is for: tick any number of them; one ticked person may
 * lead it (the star). Only people who work on projects here are offered.
 */
const props = defineProps({
    people: { type: Array, default: () => [] },
    modelValue: { type: Object, required: true }, // { ids: [], lead: null }
    error: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue']);

const chosen = computed(() => new Set(props.modelValue.ids));
function toggle(id) {
    const ids = chosen.value.has(id) ? props.modelValue.ids.filter((item) => item !== id) : [...props.modelValue.ids, id];
    emit('update:modelValue', { ids, lead: ids.includes(props.modelValue.lead) ? props.modelValue.lead : null });
}
function lead(id) {
    emit('update:modelValue', { ids: chosen.value.has(id) ? props.modelValue.ids : [...props.modelValue.ids, id], lead: props.modelValue.lead === id ? null : id });
}
</script>

<template>
    <fieldset>
        <legend class="mb-1.5 text-[13px] font-medium text-fg-2">{{ t('projects.people.title') }}</legend>
        <p v-if="!people.length" class="text-[12.5px] text-muted">{{ t('projects.people.none') }}</p>
        <ul v-else class="max-h-56 divide-y divide-line overflow-y-auto rounded-xl border border-line">
            <li v-for="person in people" :key="person.id" class="flex items-center gap-3 px-3 py-2">
                <input :id="`person-${person.id}`" type="checkbox" class="size-4" :checked="chosen.has(person.id)" @change="toggle(person.id)" />
                <span class="grid size-7 shrink-0 place-items-center rounded-full bg-subtle text-[11px] font-semibold" aria-hidden="true">{{ initials(person.name) }}</span>
                <label :for="`person-${person.id}`" class="min-w-0 flex-1 truncate text-[13.5px]">{{ person.name }}</label>
                <button type="button" class="rounded-md p-1 transition hover:bg-subtle" :class="modelValue.lead === person.id ? 'text-warn' : 'text-faint'"
                    :aria-pressed="modelValue.lead === person.id" :aria-label="t('projects.people.lead', { name: person.name })" @click="lead(person.id)">
                    <Star class="size-4" :fill="modelValue.lead === person.id ? 'currentColor' : 'none'" aria-hidden="true" />
                </button>
            </li>
        </ul>
        <p class="mt-1 text-[12px] text-muted">{{ t('projects.people.hint') }}</p>
        <p v-if="error" class="mt-1 text-[12.5px] text-bad" role="alert">{{ error }}</p>
    </fieldset>
</template>
