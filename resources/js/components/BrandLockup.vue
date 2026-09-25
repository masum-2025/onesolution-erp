<script setup>
import { computed, ref, watch } from 'vue';
import BrandMark from './BrandMark.vue';
import { brand, taglineFor } from '@/lib/brand';
import { i18n } from '@/lib/i18n';
import { theme } from '@/lib/theme';

/**
 * The full brand lockup: logo image with the tagline under it. The logo is
 * made for light backgrounds, so dark mode (or a brand without a logo) shows
 * the mark, the name and the tagline as text instead.
 */
defineProps({
    align: { type: String, default: 'start' }, // start | center
});

const failed = ref(false);
watch(() => brand.logo_url, () => (failed.value = false));

const showLogo = computed(() => brand.logo_url && !failed.value && !theme.dark);
const tagline = computed(() => taglineFor(i18n.locale));
</script>

<template>
    <div class="flex flex-col gap-2" :class="align === 'center' ? 'items-center text-center' : 'items-start'">
        <img
            v-if="showLogo"
            :src="brand.logo_url"
            :alt="brand.name"
            class="h-24 w-auto object-contain"
            decoding="async"
            @error="failed = true"
        />
        <BrandMark v-else size="lg" with-name class="[&>span:last-child]:text-[18px]" />
        <p v-if="tagline" class="text-[13.5px] font-medium tracking-[0.01em] text-ok">{{ tagline }}</p>
    </div>
</template>
