<script setup>
import { computed, ref, toRef, useId } from 'vue';
import { X } from 'lucide-vue-next';
import { useModal } from '@/lib/useModal';
import { appearance } from '@/lib/appearance';
import { brand } from '@/lib/brand';
import { t } from '@/lib/i18n';

/**
 * Every window in the app. How it opens is the person's window style
 * (rule ui.dialog_style, see lib/appearance.js):
 *  - center: a box in the middle (a sheet from the bottom on phones);
 *  - panel: a large panel from the end edge with a round close button on
 *    its edge, the content on a card (long forms); its colours are the
 *    person's form-colour template (rule ui.dialog_tone, CSS .dialog-sheet/.dialog-card);
 *  - premium: a centred card with a soft brand glow, a gradient icon tile,
 *    a frosted footer and a springy entrance (none with reduced motion).
 * Confirmations (tone warn/bad, or `confirm`) always open in the middle:
 * a yes/no never takes the whole screen.
 */
const props = defineProps({
    open: Boolean,
    title: { type: String, required: true },
    description: { type: String, default: '' },
    size: { type: String, default: 'md' }, // sm | md | lg
    icon: { type: [Object, Function], default: null },
    tone: { type: String, default: 'brand' }, // brand | bad | warn
    // Always a centred box, whatever the window style.
    confirm: Boolean,
    // Confirmations can open on top of a drawer or another dialog.
    layer: { type: String, default: 'z-50' },
    // A sample of one style whatever the person's ("My look" shows the side-panel colours this way).
    forceStyle: { type: String, default: null },
});

const emit = defineEmits(['close']);

const panel = ref(null);
const titleId = useId();
const { onKeydown } = useModal(toRef(props, 'open'), panel, () => emit('close'));

// Every form window opens to the same width in its style, whatever the form's size;
// confirmations keep their own small sizes (a yes/no never needs a wide box).
const CONFIRM_WIDTHS = { sm: 'sm:max-w-md', md: 'sm:max-w-lg', lg: 'sm:max-w-2xl' };
const isConfirm = computed(() => props.confirm || props.tone !== 'brand');
const CENTER_WIDTH = 'sm:max-w-2xl';
const PANEL_WIDTH = 'sm:w-[min(1120px,88vw)]';
const PREMIUM_WIDTH = 'sm:max-w-2xl';
const style = computed(() => (isConfirm.value ? 'center' : (props.forceStyle ?? appearance.dialog)));
const showBand = computed(() => appearance.dialog_band !== 'off' && brand.band_colors?.length > 0);
const TONES = { brand: 'bg-brand-soft text-brand-text', bad: 'bg-bad-soft text-bad', warn: 'bg-warn-soft text-warn' };
const close = () => emit('close');
</script>

<template>
    <Teleport to="body">
        <!-- Side panel (Worksuit-like) -->
        <template v-if="style === 'panel'">
            <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0" leave-active-class="transition duration-200" leave-to-class="opacity-0">
                <div v-if="open" class="fixed inset-0 bg-[rgb(10_10_14/0.5)]" :class="layer" aria-hidden="true" @click="close" />
            </Transition>
            <Transition
                enter-active-class="transition duration-300 ease-[var(--ease-soft)] motion-reduce:transition-none"
                enter-from-class="translate-x-full rtl:-translate-x-full"
                leave-active-class="transition duration-200 ease-in motion-reduce:transition-none"
                leave-to-class="translate-x-full rtl:-translate-x-full"
            >
                <div
                    v-if="open"
                    ref="panel"
                    role="dialog"
                    aria-modal="true"
                    :aria-labelledby="titleId"
                    tabindex="-1"
                    class="dialog-sheet fixed inset-y-0 end-0 flex w-full flex-col shadow-dialog outline-none"
                    :class="[layer, PANEL_WIDTH]"
                    @keydown="onKeydown"
                >
                    <button
                        type="button"
                        class="absolute start-3 top-3 z-10 grid size-10 place-items-center rounded-full bg-brand text-brand-fg shadow-lg ring-4 ring-[var(--dialog-sheet)] transition hover:scale-105 sm:-start-5 sm:top-5"
                        :aria-label="t('core.actions.close')"
                        @click="close"
                    >
                        <X class="size-4" aria-hidden="true" />
                    </button>
                    <header class="px-5 pt-16 pb-4 sm:px-8 sm:pt-6">
                        <h2 :id="titleId" class="text-[20px] font-semibold tracking-[-0.01em] text-fg">{{ title }}</h2>
                        <p v-if="description" class="mt-1 max-w-3xl text-[13px] leading-relaxed text-muted">{{ description }}</p>
                        <!-- The brand band, as under the app header (rule ui.dialog_band). -->
                        <div v-if="showBand" class="mt-4 flex h-1 overflow-hidden rounded-full" data-dialog-band aria-hidden="true">
                            <span v-for="(color, index) in brand.band_colors" :key="index" class="flex-1" :style="{ background: color }" />
                        </div>
                    </header>
                    <div class="min-h-0 flex-1 overflow-y-auto px-5 pb-6 sm:px-8">
                        <div class="dialog-card rounded-xl p-5 sm:p-6">
                            <slot />
                        </div>
                    </div>
                    <footer
                        v-if="$slots.footer"
                        class="flex flex-col-reverse gap-2 border-t border-line bg-surface px-5 py-3.5 pb-[max(0.875rem,env(safe-area-inset-bottom))] sm:flex-row sm:justify-end sm:px-8"
                    >
                        <slot name="footer" />
                    </footer>
                </div>
            </Transition>
        </template>

        <!-- Premium card -->
        <Transition
            v-else-if="style === 'premium'"
            enter-active-class="transition duration-300 ease-[var(--ease-soft)]"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-150"
            leave-to-class="opacity-0"
        >
            <div v-if="open" class="fixed inset-0 flex items-end justify-center sm:items-center sm:p-6" :class="layer" @keydown="onKeydown">
                <div
                    class="absolute inset-0 bg-[color-mix(in_oklab,var(--brand)_16%,rgb(10_10_14/0.55))] backdrop-blur-md"
                    aria-hidden="true"
                    @click="close"
                />
                <div
                    ref="panel"
                    role="dialog"
                    aria-modal="true"
                    :aria-labelledby="titleId"
                    tabindex="-1"
                    class="dialog-premium relative flex max-h-[92dvh] w-full flex-col overflow-hidden rounded-t-[22px] bg-raised outline-none ring-1 ring-[color-mix(in_oklab,var(--brand)_28%,transparent)] shadow-[0_30px_80px_-20px_rgb(10_10_14/0.45),0_0_0_1px_rgb(255_255_255/0.04)_inset] sm:rounded-[22px]"
                    :class="PREMIUM_WIDTH"
                >
                    <div class="pointer-events-none absolute inset-x-0 top-0 h-32 bg-[radial-gradient(120%_100%_at_0%_0%,color-mix(in_oklab,var(--brand)_26%,transparent),transparent_70%)]" aria-hidden="true" />
                    <div class="relative mx-auto mt-2.5 h-1 w-10 rounded-full bg-line-strong sm:hidden" aria-hidden="true" />
                    <header class="relative flex items-start gap-4 px-5 pt-5 pb-4 sm:px-7 sm:pt-7">
                        <div
                            class="grid size-12 shrink-0 place-items-center rounded-2xl bg-[linear-gradient(135deg,var(--brand),color-mix(in_oklab,var(--brand)_60%,#000))] text-brand-fg shadow-[0_10px_24px_-8px_color-mix(in_oklab,var(--brand)_70%,transparent)]"
                            aria-hidden="true"
                        >
                            <component :is="icon" v-if="icon" class="size-6" />
                            <span v-else class="size-2.5 rounded-full bg-current" />
                        </div>
                        <div class="min-w-0 flex-1 pt-1">
                            <h2 :id="titleId" class="text-[18px] font-bold tracking-[-0.015em] text-fg">{{ title }}</h2>
                            <p v-if="description" class="mt-1 text-[13px] leading-relaxed text-muted">{{ description }}</p>
                        </div>
                        <button
                            type="button"
                            class="grid size-9 shrink-0 place-items-center rounded-full border border-line bg-surface/70 text-muted backdrop-blur transition hover:rotate-90 hover:text-fg motion-reduce:hover:rotate-0"
                            :aria-label="t('core.actions.close')"
                            @click="close"
                        >
                            <X class="size-4" aria-hidden="true" />
                        </button>
                    </header>
                    <div class="mx-5 h-px bg-[linear-gradient(90deg,transparent,var(--c-line-strong),transparent)] sm:mx-7" aria-hidden="true" />
                    <div class="relative min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-7">
                        <slot />
                    </div>
                    <footer
                        v-if="$slots.footer"
                        class="relative flex flex-col-reverse gap-2 border-t border-line/70 bg-raised/75 px-5 py-4 pb-[max(1rem,env(safe-area-inset-bottom))] backdrop-blur-md sm:flex-row sm:justify-end sm:px-7"
                    >
                        <slot name="footer" />
                    </footer>
                </div>
            </div>
        </Transition>

        <!-- Centred box (and every confirmation) -->
        <Transition
            v-else
            enter-active-class="transition duration-200 ease-[var(--ease-soft)]"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-150"
            leave-to-class="opacity-0"
        >
            <div v-if="open" class="fixed inset-0 flex items-end justify-center sm:items-center sm:p-6" :class="layer" @keydown="onKeydown">
                <div class="absolute inset-0 bg-[rgb(10_10_14/0.42)] backdrop-blur-[2px]" aria-hidden="true" @click="close" />
                <div
                    ref="panel"
                    role="dialog"
                    aria-modal="true"
                    :aria-labelledby="titleId"
                    tabindex="-1"
                    class="relative flex max-h-[92dvh] w-full animate-rise flex-col overflow-hidden rounded-t-2xl border border-line bg-raised shadow-dialog outline-none sm:rounded-2xl"
                    :class="isConfirm ? CONFIRM_WIDTHS[size] : CENTER_WIDTH"
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
                            @click="close"
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

<style scoped>
/* The premium card settles with a little spring; reduced motion keeps the fade only. */
.dialog-premium {
    animation: dialog-premium-in 420ms cubic-bezier(0.2, 1.25, 0.35, 1) both;
}

@keyframes dialog-premium-in {
    from {
        opacity: 0;
        transform: translateY(14px) scale(0.94);
    }
    to {
        opacity: 1;
        transform: none;
    }
}

@media (prefers-reduced-motion: reduce) {
    .dialog-premium {
        animation: none;
    }
}
</style>
