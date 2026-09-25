<script setup>
import { computed } from 'vue';
import { brand } from '@/lib/brand';

const props = defineProps({
    size: { type: String, default: 'md' }, // sm | md | lg
    withName: Boolean,
});

const initial = computed(() => (brand.name || '·').trim().charAt(0).toUpperCase());
const SIZES = { sm: 'size-7 rounded-lg text-[13px]', md: 'size-8 rounded-[10px] text-[14px]', lg: 'size-11 rounded-[14px] text-[19px]' };
</script>

<template>
    <span class="inline-flex min-w-0 items-center gap-2.5">
        <span class="brand-mark shrink-0" :class="SIZES[props.size]" aria-hidden="true">{{ initial }}</span>
        <span v-if="withName" class="truncate text-[14.5px] font-semibold tracking-[-0.01em] text-fg">{{ brand.name }}</span>
    </span>
</template>
