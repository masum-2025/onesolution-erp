<script setup>
import { computed, ref, toRef, useId } from 'vue';
import { X } from 'lucide-vue-next';
import { useModal } from '@/lib/useModal';
import { t } from '@/lib/i18n';

const props = defineProps({
    open: Boolean,
    title: { type: String, default: '' },
    side: { type: String, default: 'end' }, // start | end
    width: { type: String, default: 'sm:max-w-[560px]' },
    labelledby: { type: String, default: null },
});

const emit = defineEmits(['close']);

const panel = ref(null);
const titleId = useId();
const { onKeydown } = useModal(toRef(props, 'open'), panel, () => emit('close'));

const position = computed(() => (props.side === 'start' ? 'start-0 border-e' : 'end-0 border-s'));
const hidden = computed(() =>
    props.side === 'start' ? '-translate-x-full rtl:translate-x-full' : 'translate-x-full rtl:-translate-x-full',
);
</script>

<template>
    <Teleport to="body">
        <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0" leave-active-class="transition duration-200" leave-to-class="opacity-0">
            <div v-if="open" class="fixed inset-0 z-40 bg-[rgb(10_10_14/0.36)] backdrop-blur-[1px]" aria-hidden="true" @click="emit('close')" />
        </Transition>
        <Transition
            enter-active-class="transition duration-300 ease-[var(--ease-soft)]"
            :enter-from-class="hidden"
            leave-active-class="transition duration-200 ease-in"
            :leave-to-class="hidden"
        >
            <aside
                v-if="open"
                ref="panel"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="labelledby ?? titleId"
                tabindex="-1"
                class="fixed inset-y-0 z-50 flex w-full flex-col border-line bg-raised shadow-dialog outline-none"
                :class="[position, width]"
                @keydown="onKeydown"
            >
                <slot name="header" :title-id="titleId">
                    <header class="flex items-center justify-between gap-3 border-b border-line px-5 py-3.5">
                        <h2 :id="titleId" class="text-[15px] font-semibold text-fg">{{ title }}</h2>
                        <button
                            type="button"
                            class="grid size-8 place-items-center rounded-lg text-muted transition hover:bg-subtle hover:text-fg"
                            :aria-label="t('core.actions.close')"
                            @click="emit('close')"
                        >
                            <X class="size-4" aria-hidden="true" />
                        </button>
                    </header>
                </slot>
                <div class="min-h-0 flex-1 overflow-y-auto">
                    <slot />
                </div>
                <slot name="footer" />
            </aside>
        </Transition>
    </Teleport>
</template>
