<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { ShieldAlert } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import { formatDate } from '@/lib/format';
import { session } from '@/lib/session';
import { t } from '@/lib/i18n';

/**
 * While the organization requires two-step sign-in and the person has not
 * set it up (Phase 8-1): until when, and where to do it. My account shows
 * the full picture itself.
 */
const route = useRoute();
const due = computed(() => session.me?.user?.two_factor?.setup_due_at ?? null);
</script>

<template>
    <div v-if="due && route.name !== 'account'" class="border-b border-warn/25 bg-warn-soft px-4 py-2.5 sm:px-6 lg:px-8" role="status">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-3 gap-y-2 text-[13px] text-fg-2">
            <ShieldAlert class="size-4 shrink-0 text-warn" aria-hidden="true" />
            <span class="flex-1">{{ t('core.two_factor.required_by', { date: formatDate(due) }) }}</span>
            <AppButton size="sm" to="/account#security">{{ t('core.two_factor.set_up') }}</AppButton>
        </div>
    </div>
</template>
