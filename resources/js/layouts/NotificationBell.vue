<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Bell, CheckCheck, CircleAlert, FileText, Info, KeyRound, TriangleAlert } from 'lucide-vue-next';
import AppMenu from '@/components/AppMenu.vue';
import { api } from '@/lib/http';
import { on } from '@/lib/events';
import { currentOrganization, isPortalMember, session } from '@/lib/session';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * The header bell: work waiting for this person here, as counts with the
 * screen that handles it. From /api/attention (platform and module items)
 * plus what /api/me already says (terms to accept, second step to set up).
 * The number on the bell is always shown as a number, never a dot alone.
 */
const REFRESH_MS = 120000;
const TONE_ICONS = { info: Info, warn: TriangleAlert, bad: CircleAlert };

const router = useRouter();
const remote = ref([]);

const local = computed(() => {
    const me = session.me;
    const items = [];
    if (me?.context?.legal_pending) {
        items.push({ key: 'legal', label: t('core.attention.legal'), count: me.context.legal_pending, path: '/provider', tone: 'warn', icon: FileText });
    }
    if (me?.user?.two_factor?.setup_due_at && !me.user.two_factor.enabled) {
        items.push({ key: 'two_factor', label: t('core.attention.two_factor'), count: 1, path: '/account#security', tone: 'warn', icon: KeyRound });
    }
    return items;
});

const items = computed(() => [...local.value, ...remote.value]);
const total = computed(() => items.value.reduce((sum, item) => sum + item.count, 0));

const menuItems = computed(() => {
    if (!items.value.length) return [{ label: t('core.attention.empty'), icon: CheckCheck, disabled: true }];
    return items.value.map((item) => ({
        label: item.label,
        icon: item.icon ?? TONE_ICONS[item.tone] ?? Info,
        hint: formatNumber(item.count),
        onSelect: () => router.push(item.path),
    }));
});

async function refresh() {
    if (!currentOrganization() || isPortalMember() || session.me?.context?.support) {
        remote.value = [];
        return;
    }
    try {
        remote.value = (await api('/api/attention')).data;
    } catch {
        // The bell is a convenience: on failure it shows what it already knows.
    }
}

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
