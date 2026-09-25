<script setup>
import { computed } from 'vue';
import { RotateCw, TriangleAlert, WifiOff } from 'lucide-vue-next';
import AppButton from './AppButton.vue';
import { t } from '@/lib/i18n';

const props = defineProps({
    error: { type: Object, default: null },
    compact: Boolean,
});

defineEmits(['retry']);

const offline = computed(() => props.error?.code === 'network');
</script>

<template>
    <div class="flex flex-col items-center text-center" :class="compact ? 'px-4 py-8' : 'px-6 py-14'" role="alert">
        <div class="mb-4 grid size-12 place-items-center rounded-2xl bg-bad-soft text-bad" aria-hidden="true">
            <component :is="offline ? WifiOff : TriangleAlert" class="size-5" />
        </div>
        <h3 class="text-[15px] font-semibold text-fg">{{ offline ? t('core.states.offline_title') : t('core.states.error_title') }}</h3>
        <p class="mt-1.5 max-w-sm text-[13px] leading-relaxed text-muted">{{ error?.message ?? t('core.errors.server') }}</p>
        <AppButton class="mt-5" :icon="RotateCw" @click="$emit('retry')">{{ t('core.actions.retry') }}</AppButton>
    </div>
</template>
