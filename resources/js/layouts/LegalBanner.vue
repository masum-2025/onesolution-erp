<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { FileText } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import { session } from '@/lib/session';
import { t } from '@/lib/i18n';

/**
 * Tells the account owner that terms or a data processing agreement wait
 * for acceptance. It never blocks work.
 */
const route = useRoute();
const pending = computed(() => session.me?.context?.legal_pending ?? 0);
</script>

<template>
    <div v-if="pending > 0 && route.name !== 'provider'" class="border-b border-brand/20 bg-brand-soft px-4 py-2.5 sm:px-6 lg:px-8" role="status">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-3 gap-y-2 text-[13px] text-fg-2">
            <FileText class="size-4 shrink-0 text-brand-text" aria-hidden="true" />
            <span class="flex-1">{{ t('core.legal.pending', { count: pending }) }}</span>
            <AppButton size="sm" to="/provider">{{ t('core.legal.review') }}</AppButton>
        </div>
    </div>
</template>
