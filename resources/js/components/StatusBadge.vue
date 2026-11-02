<script setup>
import AppBadge from '@/components/AppBadge.vue';

/**
 * A status as a badge: the module names the tone of each status (its own
 * words for them come from its texts); the word always shows next to the
 * colour, so it reads without colour too.
 *
 *   <StatusBadge :status="invoice.status" :tones="{ draft: 'neutral', posted: 'ok' }">{{ t(`x.statuses.${invoice.status}`) }}</StatusBadge>
 */
defineProps({
    status: { type: String, default: '' },
    tones: { type: Object, default: () => ({}) },
});
</script>

<template>
    <AppBadge :tone="tones[status] ?? 'neutral'" dot><slot /></AppBadge>
</template>
