<script setup>
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Bell, CheckCheck } from 'lucide-vue-next';
import AppMenu from '@/components/AppMenu.vue';
import { attentionItems, attentionTotal, refreshAttention } from '@/lib/attention';
import { on } from '@/lib/events';
import { session } from '@/lib/session';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * The header bell: work waiting for this person here (lib/attention.js), as
 * counts with the screen that handles it. The number on the bell is always
 * shown as a number, never a dot alone.
 */
const REFRESH_MS = 120000;

const router = useRouter();
const total = attentionTotal;

const menuItems = computed(() => {
    if (!attentionItems.value.length) return [{ label: t('core.attention.empty'), icon: CheckCheck, disabled: true }];
    return attentionItems.value.map((item) => ({
        label: item.label,
        icon: item.icon,
        hint: formatNumber(item.count),
        onSelect: () => router.push(item.path),
    }));
});

const refresh = refreshAttention;

let timer;
let offApprovals;

function onVisible() {
    if (document.visibilityState === 'visible') refresh();
}

onMounted(() => {
    refresh();
    timer = setInterval(() => document.visibilityState === 'visible' && refresh(), REFRESH_MS);
    offApprovals = on('approvals-changed', refresh);
    document.addEventListener('visibilitychange', onVisible);
});

onBeforeUnmount(() => {
    clearInterval(timer);
    offApprovals?.();
    document.removeEventListener('visibilitychange', onVisible);
});

watch(() => session.me?.context?.id, refresh);
</script>

<template>
    <AppMenu :items="menuItems" width="w-[min(20rem,calc(100vw-2rem))]" :label="total ? t('core.attention.label_count', { count: total }) : t('core.attention.label')">
        <template #trigger="{ toggle, attrs }">
            <button
                type="button"
                v-bind="attrs"
                class="relative grid size-10 place-items-center rounded-xl border border-line bg-surface text-fg-2 shadow-xs transition hover:border-line-strong hover:text-fg"
                @click="toggle(false)"
                @keydown.down.prevent="toggle(true)"
            >
                <Bell class="size-[18px]" :class="total && 'bell-ring'" aria-hidden="true" />
                <span
                    v-if="total"
                    class="tabular absolute -end-1.5 -top-1.5 min-w-[20px] rounded-full bg-bad px-1 text-center text-[11px] leading-5 font-bold text-canvas ring-2 ring-canvas"
                    aria-hidden="true"
                >
                    {{ total > 99 ? '99+' : formatNumber(total) }}
                </span>
            </button>
        </template>
        <template #header>
            <p class="px-2.5 pt-2 pb-1.5 text-[12px] font-semibold text-fg">{{ t('core.attention.title') }}</p>
        </template>
    </AppMenu>
</template>
