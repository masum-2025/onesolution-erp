<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import { Check, ChevronsUpDown, Search } from 'lucide-vue-next';
import OrgTypeIcon from './OrgTypeIcon.vue';
import { visibleOrganizations } from '@/lib/organizations';
import { t } from '@/lib/i18n';

/**
 * Choose one of the organizations visible in the current context.
 * `allow(org)` can limit the choices (e.g. valid parents for a move).
 */
const props = defineProps({
    modelValue: { type: String, default: null },
    allow: { type: Function, default: null },
    label: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    compact: Boolean,
    invalid: Boolean,
    inputId: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue', 'select']);

const list = ref([]);
const open = ref(false);
const query = ref('');
const active = ref(0);
const root = ref(null);
const search = ref(null);
const listId = useId();

onMounted(async () => {
    try {
        list.value = await visibleOrganizations();
    } catch {
        list.value = [];
    }
});

const minDepth = computed(() => Math.min(...list.value.map((org) => org.depth), 0));
const selected = computed(() => list.value.find((org) => org.id === props.modelValue) ?? null);

const choices = computed(() => {
    const needle = query.value.trim().toLocaleLowerCase();
    return list.value
        .filter((org) => (props.allow ? props.allow(org) : true))
        .filter((org) => !needle || org.display_name.toLocaleLowerCase().includes(needle));
});

watch(query, () => (active.value = 0));

async function toggle() {
    open.value = !open.value;
    if (open.value) {
        query.value = '';
        active.value = Math.max(0, choices.value.findIndex((org) => org.id === props.modelValue));
        document.addEventListener('pointerdown', onOutside, true);
        await nextTick();
        search.value?.focus();
    } else {
        document.removeEventListener('pointerdown', onOutside, true);
    }
}

function close() {
    open.value = false;
    document.removeEventListener('pointerdown', onOutside, true);
}

function onOutside(event) {
    if (!root.value?.contains(event.target)) close();
}

function choose(org) {
    emit('update:modelValue', org.id);
    emit('select', org);
    close();
    root.value?.querySelector('button')?.focus();
}

function onKeydown(event) {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = Math.min(active.value + 1, choices.value.length - 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = Math.max(active.value - 1, 0);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        if (choices.value[active.value]) choose(choices.value[active.value]);
    } else if (event.key === 'Escape') {
        event.stopPropagation();
        close();
    }
}

onBeforeUnmount(() => document.removeEventListener('pointerdown', onOutside, true));
</script>

<template>
    <div ref="root" class="relative">
        <button
            :id="inputId ?? undefined"
            type="button"
            class="field-input flex items-center gap-2.5 text-start"
            :class="compact ? 'h-9 min-h-9 w-auto max-w-[280px]' : ''"
            :aria-invalid="invalid || undefined"
            :aria-expanded="open"
            aria-haspopup="listbox"
            :aria-label="label || undefined"
            @click="toggle"
        >
            <template v-if="selected">
                <OrgTypeIcon :type="selected.type" size="sm" />
                <span class="min-w-0 flex-1 truncate text-fg">{{ selected.display_name }}</span>
            </template>
            <span v-else class="min-w-0 flex-1 truncate text-faint">{{ placeholder || t('core.org_picker.placeholder') }}</span>
            <ChevronsUpDown class="size-4 shrink-0 text-faint" aria-hidden="true" />
        </button>

        <Transition enter-active-class="transition duration-150 ease-[var(--ease-soft)]" enter-from-class="opacity-0 -translate-y-1" leave-active-class="transition duration-100" leave-to-class="opacity-0">
            <div v-if="open" class="absolute start-0 z-50 mt-1.5 w-[min(360px,calc(100vw-2rem))] overflow-hidden rounded-xl border border-line bg-raised shadow-pop" @keydown="onKeydown">
                <div class="flex items-center gap-2 border-b border-line px-3">
                    <Search class="size-4 shrink-0 text-faint" aria-hidden="true" />
                    <input
                        ref="search"
                        v-model="query"
                        type="search"
                        class="h-10 w-full bg-transparent text-[13.5px] text-fg outline-none placeholder:text-faint"
                        :placeholder="t('core.org_picker.search')"
                        role="combobox"
                        :aria-controls="listId"
                        aria-expanded="true"
                        :aria-activedescendant="choices[active] ? `${listId}-${choices[active].id}` : undefined"
                    />
                </div>
                <ul :id="listId" role="listbox" class="max-h-72 overflow-y-auto p-1">
                    <li
                        v-for="(org, index) in choices"
                        :id="`${listId}-${org.id}`"
                        :key="org.id"
                        role="option"
                        :aria-selected="org.id === modelValue"
                        class="flex cursor-pointer items-center gap-2.5 rounded-lg py-1.5 pe-2.5 text-[13.5px]"
                        :class="index === active ? 'bg-subtle text-fg' : 'text-fg-2'"
                        :style="{ paddingInlineStart: `${0.625 + (org.depth - minDepth) * 1}rem` }"
                        @mouseenter="active = index"
                        @click="choose(org)"
                    >
                        <OrgTypeIcon :type="org.type" size="sm" />
                        <span class="min-w-0 flex-1 truncate">{{ org.display_name }}</span>
                        <Check v-if="org.id === modelValue" class="size-4 shrink-0 text-brand-text" aria-hidden="true" />
                    </li>
                    <li v-if="choices.length === 0" class="px-3 py-6 text-center text-[13px] text-muted">{{ t('core.org_picker.empty') }}</li>
                </ul>
            </div>
        </Transition>
    </div>
</template>
