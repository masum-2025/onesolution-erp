<script setup>
import { computed } from 'vue';
import { RouterLink } from 'vue-router';

const props = defineProps({
    variant: { type: String, default: 'secondary' }, // primary | secondary | ghost | danger | danger-soft
    size: { type: String, default: 'md' }, // sm | md | lg | icon | icon-sm
    type: { type: String, default: 'button' },
    to: { type: [String, Object], default: null },
    icon: { type: [Object, Function], default: null },
    iconEnd: { type: [Object, Function], default: null },
    loading: Boolean,
    disabled: Boolean,
    block: Boolean,
});

const VARIANTS = {
    primary:
        'bg-brand text-brand-fg hover:bg-brand-hover shadow-[inset_0_1px_0_rgb(255_255_255/0.16),0_1px_2px_rgb(16_16_20/0.14)]',
    secondary: 'bg-surface text-fg border border-line-strong hover:bg-subtle shadow-xs',
    ghost: 'text-fg-2 hover:bg-subtle hover:text-fg',
    danger: 'bg-bad text-white hover:brightness-95 shadow-[inset_0_1px_0_rgb(255_255_255/0.16),0_1px_2px_rgb(16_16_20/0.14)]',
    'danger-soft': 'text-bad hover:bg-bad-soft',
};

const SIZES = {
    sm: 'h-8 px-2.5 text-[13px] gap-1.5',
    md: 'h-9 px-3.5 text-[13.5px] gap-2',
    lg: 'h-11 px-5 text-[14.5px] gap-2',
    icon: 'size-9 justify-center',
    'icon-sm': 'size-8 justify-center',
};

const classes = computed(() => [
    'relative inline-flex shrink-0 items-center justify-center rounded-[9px] font-medium whitespace-nowrap select-none',
    'transition-[background-color,border-color,color,box-shadow,filter] duration-150 active:translate-y-px',
    'disabled:pointer-events-none disabled:opacity-55 aria-disabled:pointer-events-none aria-disabled:opacity-55',
    VARIANTS[props.variant] ?? VARIANTS.secondary,
    SIZES[props.size] ?? SIZES.md,
    props.block ? 'w-full' : '',
]);
</script>

<template>
    <component
        :is="to ? RouterLink : 'button'"
        :to="to ?? undefined"
        :type="to ? undefined : type"
        :disabled="to ? undefined : disabled || loading"
        :aria-disabled="to && disabled ? 'true' : undefined"
        :aria-busy="loading || undefined"
        :class="classes"
    >
        <svg v-if="loading" class="size-4 shrink-0 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
        </svg>
        <component :is="icon" v-else-if="icon" class="size-4 shrink-0" aria-hidden="true" />
        <slot />
        <component :is="iconEnd" v-if="iconEnd" class="size-4 shrink-0 opacity-70" aria-hidden="true" />
    </component>
</template>
