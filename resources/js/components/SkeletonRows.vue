<script setup>
import { t } from '@/lib/i18n';

defineProps({
    rows: { type: Number, default: 5 },
    avatar: Boolean,
});
</script>

<template>
    <div class="divide-y divide-line" role="status" :aria-label="t('core.states.loading')">
        <div v-for="row in rows" :key="row" class="flex items-center gap-3.5 px-4 py-3.5 sm:px-5">
            <div v-if="avatar" class="skeleton size-9 shrink-0 rounded-xl" />
            <div class="min-w-0 flex-1 space-y-2">
                <div class="skeleton h-3.5" :style="{ width: `${38 + ((row * 17) % 35)}%` }" />
                <div class="skeleton h-3 opacity-70" :style="{ width: `${22 + ((row * 23) % 30)}%` }" />
            </div>
            <div class="skeleton hidden h-6 w-20 sm:block" />
        </div>
    </div>
</template>
