<script setup>
import { nextTick, onBeforeUnmount, ref, useId } from 'vue';

/**
 * Dropdown menu. items: [{ label, icon?, onSelect, danger?, disabled?, hint? } | { divider: true } | { heading }]
 */
const props = defineProps({
    items: { type: Array, required: true },
    align: { type: String, default: 'end' }, // start | end
    placement: { type: String, default: 'bottom' }, // bottom | top
    width: { type: String, default: 'min-w-52' },
    label: { type: String, default: '' },
});

const open = ref(false);
const root = ref(null);
const menu = ref(null);
const id = useId();

function items() {
    return [...(menu.value?.querySelectorAll('[role="menuitem"]:not([disabled])') ?? [])];
}

async function toggle(focusFirst = false) {
    open.value = !open.value;
    if (open.value) {
        document.addEventListener('pointerdown', onOutside, true);
        await nextTick();
        if (focusFirst) items()[0]?.focus();
    } else {
        document.removeEventListener('pointerdown', onOutside, true);
    }
}

function close(returnFocus = true) {
    if (!open.value) return;
    open.value = false;
    document.removeEventListener('pointerdown', onOutside, true);
    if (returnFocus) root.value?.querySelector('[aria-haspopup]')?.focus();
}

function onOutside(event) {
    if (!root.value?.contains(event.target)) close(false);
}

function onKeydown(event) {
    const list = items();
    const index = list.indexOf(document.activeElement);
    if (event.key === 'Escape') {
        event.stopPropagation();
        close();
    } else if (event.key === 'ArrowDown') {
        event.preventDefault();
        list[(index + 1) % list.length]?.focus();
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        list[(index - 1 + list.length) % list.length]?.focus();
    } else if (event.key === 'Tab') {
        close(false);
    }
}

function select(item) {
    close();
    item.onSelect?.();
}

onBeforeUnmount(() => document.removeEventListener('pointerdown', onOutside, true));

defineExpose({ close });
</script>

<template>
    <div ref="root" class="relative" @keydown="onKeydown">
        <slot
            name="trigger"
            :open="open"
            :toggle="toggle"
            :attrs="{ 'aria-haspopup': 'menu', 'aria-expanded': open, 'aria-controls': id, 'aria-label': label || undefined }"
        />
        <Transition
            enter-active-class="transition duration-150 ease-[var(--ease-soft)]"
            enter-from-class="opacity-0 scale-[0.97]"
            leave-active-class="transition duration-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                :id="id"
                ref="menu"
                role="menu"
                class="absolute z-50 rounded-xl border border-line bg-raised p-1 shadow-pop"
                :class="[
                    width,
                    props.align === 'end' ? 'end-0' : 'start-0',
                    props.placement === 'top' ? 'bottom-full mb-1.5 origin-bottom' : 'top-full mt-1.5 origin-top',
                ]"
            >
                <slot name="header" />
                <template v-for="(item, index) in items" :key="index">
                    <div v-if="item.divider" class="my-1 h-px bg-line" role="separator" />
                    <div v-else-if="item.heading" class="px-2.5 pt-2 pb-1 text-[11px] font-semibold tracking-wide text-faint uppercase">
                        {{ item.heading }}
                    </div>
                    <button
                        v-else
                        type="button"
                        role="menuitem"
                        tabindex="-1"
                        :disabled="item.disabled"
                        class="flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-start text-[13.5px] transition-colors outline-none focus-visible:outline-none disabled:opacity-50"
                        :class="item.danger ? 'text-bad hover:bg-bad-soft focus:bg-bad-soft' : 'text-fg-2 hover:bg-subtle hover:text-fg focus:bg-subtle focus:text-fg'"
                        @click="select(item)"
                    >
                        <component :is="item.icon" v-if="item.icon" class="size-4 shrink-0 opacity-80" aria-hidden="true" />
                        <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                        <span v-if="item.hint" class="text-[12px] text-faint">{{ item.hint }}</span>
                    </button>
                </template>
                <slot name="footer" :close="close" />
            </div>
        </Transition>
    </div>
</template>
