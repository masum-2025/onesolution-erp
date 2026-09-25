<script setup>
import { computed, ref, watch } from 'vue';
import { brand } from '@/lib/brand';

/**
 * The brand's symbol (mark image) with an optional name. Without a mark, or
 * when the image cannot load, the first letter on the brand color is shown.
 */
const props = defineProps({
    size: { type: String, default: 'md' }, // sm | md | lg
    withName: Boolean,
});

const failed = ref(false);
watch(() => brand.mark_url, () => (failed.value = false));

const initial = computed(() => (brand.name || '·').trim().charAt(0).toUpperCase());
const LETTER = { sm: 'size-7 rounded-lg text-[13px]', md: 'size-8 rounded-[10px] text-[14px]', lg: 'size-11 rounded-[14px] text-[19px]' };
const IMAGE = { sm: 'size-7', md: 'size-8', lg: 'size-11' };
</script>

<template>
    <span class="inline-flex min-w-0 items-center gap-2.5">
        <img
            v-if="brand.mark_url && !failed"
            :src="brand.mark_url"
            alt=""
            class="shrink-0 object-contain"
            :class="IMAGE[props.size]"
            decoding="async"
            @error="failed = true"
        />
        <span v-else class="brand-mark shrink-0" :class="LETTER[props.size]" aria-hidden="true">{{ initial }}</span>
        <span v-if="withName" class="truncate text-[14.5px] font-semibold tracking-[-0.01em] text-fg">{{ brand.name }}</span>
    </span>
</template>
