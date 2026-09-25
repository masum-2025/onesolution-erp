<script setup>
const props = defineProps({
    tabs: { type: Array, required: true }, // [{ key, label, count? }]
    modelValue: { type: String, required: true },
    label: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

function move(event, index) {
    const step = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;
    if (!step) return;
    const rtl = document.documentElement.dir === 'rtl';
    const next = props.tabs[(index + (rtl ? -step : step) + props.tabs.length) % props.tabs.length];
    emit('update:modelValue', next.key);
    event.currentTarget.parentElement.querySelector(`[data-tab="${next.key}"]`)?.focus();
}
</script>

<template>
    <div class="-mb-px flex gap-1 overflow-x-auto border-b border-line" role="tablist" :aria-label="label">
        <button
            v-for="(tab, index) in tabs"
            :key="tab.key"
            type="button"
            role="tab"
            :data-tab="tab.key"
            :aria-selected="modelValue === tab.key"
            :tabindex="modelValue === tab.key ? 0 : -1"
            class="relative inline-flex h-10 shrink-0 items-center gap-2 px-3 text-[13.5px] font-medium transition-colors"
            :class="modelValue === tab.key ? 'text-fg' : 'text-muted hover:text-fg'"
            @click="emit('update:modelValue', tab.key)"
            @keydown="move($event, index)"
        >
            {{ tab.label }}
            <span
                v-if="tab.count !== undefined && tab.count !== null"
                class="tabular rounded-full px-1.5 text-[11px] leading-[18px]"
                :class="modelValue === tab.key ? 'bg-brand-soft text-brand-text' : 'bg-subtle text-muted'"
            >
                {{ tab.count }}
            </span>
            <span
                class="absolute inset-x-2 -bottom-px h-0.5 rounded-full transition-opacity"
                :class="modelValue === tab.key ? 'bg-brand opacity-100' : 'opacity-0'"
                aria-hidden="true"
            />
        </button>
    </div>
</template>
