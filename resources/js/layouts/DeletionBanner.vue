<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { Trash2 } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import { formatDate } from '@/lib/format';
import { session } from '@/lib/session';
import { t } from '@/lib/i18n';

/**
 * While an account deletion waits (Phase 5C-3), every screen says when it
 * happens and where to cancel it. My account shows it in full itself.
 */
const route = useRoute();
const due = computed(() => session.me?.user?.deletion_due_at ?? null);
</script>

<template>
    <div v-if="due && route.name !== 'account'" class="border-b border-bad/20 bg-bad-soft px-4 py-2.5 sm:px-6 lg:px-8" role="status">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-3 gap-y-2 text-[13px] text-fg-2">
            <Trash2 class="size-4 shrink-0 text-bad" aria-hidden="true" />
            <span class="flex-1">{{ t('core.deletion.pending', { date: formatDate(due) }) }}</span>
            <AppButton size="sm" to="/account">{{ t('core.deletion.review') }}</AppButton>
        </div>
    </div>
</template>
