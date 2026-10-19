<script setup>
import { ref, watch } from 'vue';
import { Search, X } from 'lucide-vue-next';
import AppField from '@/components/AppField.vue';
import { t } from '@/lib/i18n';

/**
 * Pick one employee of the unit by name or code (Leave's own people list:
 * name and code only). v-model is { id, name, code } or null.
 */
const props = defineProps({
    modelValue: { type: Object, default: null },
    search: { type: Function, required: true },
    label: { type: String, required: true },
    error: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue']);

const query = ref('');
const results = ref([]);
const loading = ref(false);
let timer;
let sequence = 0;

async function find() {
    const mine = ++sequence;
    loading.value = true;
    try {
        const response = await props.search(query.value.trim());
        if (mine === sequence) results.value = response.data ?? [];
    } catch {
        if (mine === sequence) results.value = [];
    } finally {
        if (mine === sequence) loading.value = false;
    }
}

watch(query, () => {
    clearTimeout(timer);
    timer = setTimeout(find, 250);
});
watch(() => props.modelValue, (value) => {
    if (!value) find();
}, { immediate: true });

function choose(person) {
    emit('update:modelValue', person);
    query.value = '';
}
</script>

<template>
    <AppField v-slot="{ id }" :label="label" :error="error">
        <div v-if="modelValue" class="flex items-center gap-2 rounded-[9px] border border-line-strong bg-surface px-3 py-2">
            <span class="min-w-0 flex-1 truncate text-[14px]">{{ modelValue.name }} <span class="font-mono text-[12px] text-muted" dir="ltr">{{ modelValue.code }}</span></span>
            <button type="button" class="grid size-7 place-items-center rounded-md text-muted hover:bg-subtle hover:text-fg" :aria-label="t('leave.common.change')" @click="emit('update:modelValue', null)">
                <X class="size-4" aria-hidden="true" />
            </button>
        </div>
        <div v-else>
            <div class="relative">
                <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
                <input :id="id" v-model="query" type="search" class="field-input ps-9" :placeholder="t('leave.common.search_people')" autocomplete="off" />
            </div>
            <ul class="mt-2 max-h-48 divide-y divide-line overflow-y-auto rounded-[9px] border border-line" :aria-busy="loading">
                <li v-for="person in results" :key="person.id">
                    <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-start text-[13.5px] hover:bg-subtle" @click="choose(person)">
                        <span class="min-w-0 flex-1 truncate">{{ person.name }}</span>
                        <span class="font-mono text-[12px] text-muted" dir="ltr">{{ person.code }}</span>
                    </button>
                </li>
                <li v-if="!loading && !results.length" class="px-3 py-2 text-[13px] text-muted">{{ t('leave.common.no_people') }}</li>
            </ul>
        </div>
    </AppField>
</template>
