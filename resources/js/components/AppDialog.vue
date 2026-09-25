<script setup>
import { ref, toRef, useId } from 'vue';
import { X } from 'lucide-vue-next';
import { useModal } from '@/lib/useModal';
import { t } from '@/lib/i18n';

const props = defineProps({
    open: Boolean,
    title: { type: String, required: true },
    description: { type: String, default: '' },
    size: { type: String, default: 'md' }, // sm | md | lg
    icon: { type: [Object, Function], default: null },
    tone: { type: String, default: 'brand' }, // brand | bad | warn
    // Confirmations can open on top of a drawer or another dialog.
    layer: { type: String, default: 'z-50' },
});

const emit = defineEmits(['close']);

const panel = ref(null);
const titleId = useId();
const { onKeydown } = useModal(toRef(props, 'open'), panel, () => emit('close'));

const WIDTHS = { sm: 'sm:max-w-md', md: 'sm:max-w-lg', lg: 'sm:max-w-2xl' };
const TONES = { brand: 'bg-brand-soft text-brand-text', bad: 'bg-bad-soft text-bad', warn: 'bg-warn-soft text-warn' };
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200 ease-[var(--ease-soft)]"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-150"
            leave-to-class="opacity-0"
        >
            <div v-if="open" class="fixed inset-0 flex items-end justify-center sm:items-center sm:p-6" :class="layer" @keydown="onKeydown">
                <div class="absolute inset-0 bg-[rgb(10_10_14/0.42)] backdrop-blur-[2px]" aria-hidden="true" @click="emit('close')" />
                <div
                    ref="panel"
                    role="dialog"
                    aria-modal="true"
                    :aria-labelledby="titleId"
                    tabindex="-1"
                    class="relative flex max-h-[92dvh] w-full animate-rise flex-col overflow-hidden rounded-t-2xl border border-line bg-raised shadow-dialog outline-none sm:rounded-2xl"
                    :class="WIDTHS[size]"
                >
                    <div class="mx-auto mt-2 h-1 w-10 rounded-full bg-line-strong sm:hidden" aria-hidden="true" />
                    <header class="flex items-start gap-3.5 px-5 pt-4 pb-3 sm:px-6 sm:pt-5">
                        <div v-if="icon" class="grid size-10 shrink-0 place-items-center rounded-xl" :class="TONES[tone]">
                            <component :is="icon" class="size-5" aria-hidden="true" />
                        </div>
                        <div class="min-w-0 flex-1 pt-0.5">
                            <h2 :id="titleId" class="text-[15.5px] font-semibold tracking-[-0.01em] text-fg">{{ title }}</h2>
                            <p v-if="description" class="mt-1 text-[13px] leading-relaxed text-muted">{{ description }}</p>
                        </div>
                        <button
                            type="button"
                            class="-me-1.5 -mt-1 grid size-8 shrink-0 place-items-center rounded-lg text-muted transition hover:bg-subtle hover:text-fg"
                            :aria-label="t('core.actions.close')"
                            @click="emit('close')"
                        >
                            <X class="size-4" aria-hidden="true" />
                        </button>
                    </header>
                    <div class="min-h-0 flex-1 overflow-y-auto px-5 pb-5 sm:px-6">
                        <slot />
                    </div>
                    <footer
                        v-if="$slots.footer"
                        class="flex flex-col-reverse gap-2 border-t border-line bg-surface/60 px-5 py-3.5 pb-[max(0.875rem,env(safe-area-inset-bottom))] sm:flex-row sm:justify-end sm:px-6"
                    >
                        <slot name="footer" />
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
