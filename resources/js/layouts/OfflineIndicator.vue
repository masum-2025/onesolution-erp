<script setup>
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { CloudCheck, CloudUpload, RefreshCw, TriangleAlert, WifiOff } from 'lucide-vue-next';
import { offline, syncNow } from '@/lib/offline/index';
import { formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Header status for offline work (Phase 7-2), loaded only on a browser set
 * up for it: no connection, changes waiting, changes that need a look.
 * A click syncs; with problems waiting it opens the account page instead.
 */
const router = useRouter();

const problems = computed(() => offline.outcomes.length);
const icon = computed(() => {
    if (offline.syncing) return RefreshCw;
    if (!offline.online) return WifiOff;
    if (problems.value) return TriangleAlert;
    return offline.pending ? CloudUpload : CloudCheck;
});
const label = computed(() => {
    if (offline.syncing) return t('core.offline.syncing');
    if (problems.value) return t('core.offline.needs_look');
    if (offline.pending) return t('core.offline.pending', { count: offline.pending, changes: formatNumber(offline.pending) });
    return offline.online ? t('core.offline.synced') : t('core.offline.offline');
});
const tone = computed(() => {
    if (problems.value) return 'text-warn';
    if (!offline.online) return 'text-muted';
    return offline.pending ? 'text-brand-text' : 'text-faint';
});

async function onClick() {
    if (problems.value) {
        router.push({ name: 'account', hash: '#offline' });
        return;
    }
    try {
        const summary = await syncNow();
        if (!summary) return;
        const notice = t(`core.offline.status.${summary.status}`);
        summary.status === 'ok' ? toast.success(notice) : toast.error(notice);
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <button
        v-if="offline.enabled || !offline.online"
        type="button"
        class="relative grid size-9 place-items-center rounded-[9px] transition hover:bg-subtle"
        :class="tone"
        :aria-label="`${t('core.offline.indicator')}: ${label}`"
        :title="label"
        :disabled="offline.syncing"
        @click="onClick"
    >
        <component :is="icon" class="size-[18px]" :class="offline.syncing ? 'animate-spin' : ''" aria-hidden="true" />
        <span
            v-if="offline.pending && !offline.syncing"
            class="absolute -top-0.5 -end-0.5 grid min-w-4 place-items-center rounded-full bg-brand px-1 text-[10px] leading-4 font-semibold text-brand-fg"
            aria-hidden="true"
        >{{ formatNumber(offline.pending) }}</span>
    </button>
</template>
