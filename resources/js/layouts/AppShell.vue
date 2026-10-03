<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { Clock } from 'lucide-vue-next';
import SidebarNav from './SidebarNav.vue';
import AppHeader from './AppHeader.vue';
import ModeBanner from './ModeBanner.vue';
import LegalBanner from './LegalBanner.vue';
import DeletionBanner from './DeletionBanner.vue';
import TwoFactorBanner from './TwoFactorBanner.vue';
import AppDrawer from '@/components/AppDrawer.vue';
import AppButton from '@/components/AppButton.vue';
import CommandPalette from '@/components/CommandPalette.vue';
import { api } from '@/lib/http';
import { loadMenu } from '@/lib/menu';
import { on } from '@/lib/events';
import { can, currentOrganization, enterContext, hasOfflineData, isPortalMember, session } from '@/lib/session';
import { readPref, writePref } from '@/lib/storage';
import { formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { i18n, t } from '@/lib/i18n';

const route = useRoute();
const mobileNav = ref(false);
const palette = ref(false);
const menu = ref([]);
const quickActions = ref([]);
// Sidebar collapsed to icons (desktop), remembered on this browser.
const collapsed = ref(readPref('sidebar') === 'collapsed');

function toggleSidebar() {
    collapsed.value = !collapsed.value;
    writePref('sidebar', collapsed.value ? 'collapsed' : null);
}
const pendingApprovals = ref(0);
const now = ref(Date.now());
const offlineOn = ref(false);

async function loadShellData() {
    const org = currentOrganization();
    // Portal members have no module menu: their portal is their whole app.
    if (!org || isPortalMember()) {
        menu.value = [];
        quickActions.value = [];
        pendingApprovals.value = 0;
        return;
    }
    try {
        const response = await loadMenu();
        menu.value = response.data;
        quickActions.value = response.quick_actions ?? [];
    } catch {
        menu.value = [];
        quickActions.value = [];
    }
    refreshApprovals();
}

async function refreshApprovals() {
    const org = currentOrganization();
    if (!org || !can('rules.approve')) {
        pendingApprovals.value = 0;
        return;
    }
    try {
        const { data } = await api(`/api/organizations/${org.id}/rule-approvals`);
        pendingApprovals.value = data.length;
    } catch {
        pendingApprovals.value = 0;
    }
}

// Offline work (Phase 7-2): its code loads only on a browser set up for it.
async function startOffline() {
    if (!hasOfflineData()) return;
    try {
        const { initOffline } = await import('@/lib/offline/index');
        await initOffline();
        offlineOn.value = true;
    } catch {
        offlineOn.value = false;
    }
}

// Session expiry: warn in the last 10 minutes, offer to continue.
const minutesLeft = computed(() => {
    const expires = session.me?.context?.expires_at;
    if (!expires) return null;
    return Math.max(0, Math.ceil((new Date(expires).getTime() - now.value) / 60000));
});
const expiringSoon = computed(() => minutesLeft.value !== null && minutesLeft.value <= 10);
const renewing = ref(false);

async function renew() {
    const context = session.me?.context;
    if (!context) return;
    renewing.value = true;
    try {
        await enterContext(context.type === 'partner' ? { partner_id: context.id } : { organization_id: context.id });
        toast.success(t('core.session.renewed'));
    } catch (error) {
        toast.error(error.message);
    } finally {
        renewing.value = false;
    }
}

function onKeydown(event) {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        palette.value = !palette.value;
    }
}

let timer;
let offApprovals;
let offOffline;

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    timer = setInterval(() => (now.value = Date.now()), 30000);
    offApprovals = on('approvals-changed', refreshApprovals);
    offOffline = on('offline-changed', startOffline);
    loadShellData();
    startOffline();
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    clearInterval(timer);
    offApprovals?.();
    offOffline?.();
});

watch(() => session.me?.context?.id, () => {
    loadShellData();
    startOffline();
});
watch(() => i18n.locale, loadShellData);
watch(() => route.path, () => (mobileNav.value = false));
</script>

<template>
    <div
        class="min-h-dvh transition-[grid-template-columns] duration-300 ease-[var(--ease-soft)] lg:grid print:block"
        :class="collapsed ? 'lg:grid-cols-[80px_minmax(0,1fr)]' : 'lg:grid-cols-[272px_minmax(0,1fr)]'"
    >
        <aside class="sticky top-0 z-40 hidden h-dvh border-e border-side-line lg:block print:hidden">
            <SidebarNav :menu="menu" :pending-approvals="pendingApprovals" :collapsed="collapsed" collapsible @toggle="toggleSidebar" />
        </aside>

        <AppDrawer :open="mobileNav" side="start" width="max-w-[300px]" :title="t('core.nav.label')" @close="mobileNav = false">
            <template #header><span class="sr-only">{{ t('core.nav.label') }}</span></template>
            <SidebarNav :menu="menu" :pending-approvals="pendingApprovals" @navigate="mobileNav = false" />
        </AppDrawer>

        <div class="flex min-w-0 flex-col">
            <AppHeader :quick-actions="quickActions" :offline="offlineOn" :simple="isPortalMember()" @open-nav="mobileNav = true" @open-palette="palette = true" />

            <ModeBanner class="print:hidden" />
            <LegalBanner class="print:hidden" />
            <DeletionBanner class="print:hidden" />
            <TwoFactorBanner class="print:hidden" />

            <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0 -translate-y-1">
                <!-- Support time is set by the grant, not renewed here. -->
                <div v-if="expiringSoon && !session.me?.context?.support" class="border-b border-warn/25 bg-warn-soft px-4 py-2.5 sm:px-6 lg:px-8" role="status">
                    <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-3 gap-y-2 text-[13px] text-fg-2">
                        <Clock class="size-4 shrink-0 text-warn" aria-hidden="true" />
                        <span class="flex-1">{{ t('core.session.expiring', { minutes: formatNumber(minutesLeft) }) }}</span>
                        <AppButton size="sm" :loading="renewing" @click="renew">{{ t('core.session.stay') }}</AppButton>
                    </div>
                </div>
            </Transition>

            <main id="main" class="flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:py-8 print:p-0">
                <div class="mx-auto max-w-6xl">
                    <RouterView v-slot="{ Component, route: current }">
                        <Transition enter-active-class="transition duration-200 ease-[var(--ease-soft)]" enter-from-class="opacity-0 translate-y-1" mode="out-in">
                            <!-- Keyed by language too: switching it reloads the page's server data. -->
                            <component :is="Component" :key="`${current.path}:${i18n.locale}`" />
                        </Transition>
                    </RouterView>
                </div>
            </main>
        </div>

        <CommandPalette :open="palette" @close="palette = false" />
    </div>
</template>
