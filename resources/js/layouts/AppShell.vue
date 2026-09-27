<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ChevronRight, Clock, Menu, Moon, Search, Sun } from 'lucide-vue-next';
import SidebarNav from './SidebarNav.vue';
import ModeBanner from './ModeBanner.vue';
import LegalBanner from './LegalBanner.vue';
import DeletionBanner from './DeletionBanner.vue';
import AppDrawer from '@/components/AppDrawer.vue';
import AppButton from '@/components/AppButton.vue';
import BrandMark from '@/components/BrandMark.vue';
import CommandPalette from '@/components/CommandPalette.vue';
import { api } from '@/lib/http';
import { cached } from '@/lib/cache';
import { on } from '@/lib/events';
import { can, currentOrganization, enterContext, isPortalMember, session } from '@/lib/session';
import { setTheme, theme } from '@/lib/theme';
import { formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { i18n, t } from '@/lib/i18n';

const route = useRoute();
const mobileNav = ref(false);
const palette = ref(false);
const menu = ref([]);
const pendingApprovals = ref(0);
const now = ref(Date.now());

const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);

const breadcrumb = computed(() => {
    const context = session.me?.context;
    if (!context) return [];
    if (context.type === 'partner') return [{ id: context.id, name: context.name }];
    return context.path ?? [];
});

async function loadShellData() {
    const org = currentOrganization();
    // Portal members have no module menu: their portal is their whole app.
    if (!org || isPortalMember()) {
        menu.value = [];
        pendingApprovals.value = 0;
        return;
    }
    try {
        menu.value = (await cached('menu', () => api('/api/menu'))).data;
    } catch {
        menu.value = [];
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

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    timer = setInterval(() => (now.value = Date.now()), 30000);
    offApprovals = on('approvals-changed', refreshApprovals);
    loadShellData();
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    clearInterval(timer);
    offApprovals?.();
});

watch(() => session.me?.context?.id, loadShellData);
watch(() => i18n.locale, loadShellData);
watch(() => route.path, () => (mobileNav.value = false));
</script>

<template>
    <div class="min-h-dvh lg:grid lg:grid-cols-[272px_minmax(0,1fr)] print:block">
        <aside class="sticky top-0 hidden h-dvh border-e border-line bg-canvas lg:block print:hidden">
            <SidebarNav :menu="menu" :pending-approvals="pendingApprovals" />
        </aside>

        <AppDrawer :open="mobileNav" side="start" width="max-w-[300px]" :title="t('core.nav.label')" @close="mobileNav = false">
            <template #header><span class="sr-only">{{ t('core.nav.label') }}</span></template>
            <SidebarNav :menu="menu" :pending-approvals="pendingApprovals" @navigate="mobileNav = false" />
        </AppDrawer>

        <div class="flex min-w-0 flex-col">
            <header class="glass sticky top-0 z-30 flex h-14 items-center gap-2 border-b border-line px-3 sm:px-6 lg:px-8 print:hidden">
                <AppButton variant="ghost" size="icon" class="lg:hidden" :icon="Menu" :aria-label="t('core.nav.open')" @click="mobileNav = true" />
                <BrandMark size="sm" class="lg:hidden" />

                <nav class="hidden min-w-0 flex-1 items-center gap-1.5 text-[13px] sm:flex" :aria-label="t('core.breadcrumb')">
                    <template v-for="(crumb, index) in breadcrumb" :key="crumb.id">
                        <ChevronRight v-if="index > 0" class="size-3.5 shrink-0 text-faint rtl:rotate-180" aria-hidden="true" />
                        <span class="truncate" :class="index === breadcrumb.length - 1 ? 'font-medium text-fg' : 'text-muted'">{{ crumb.name }}</span>
                    </template>
                </nav>
                <div class="flex-1 sm:hidden" />

                <button
                    type="button"
                    class="flex h-9 items-center gap-2 rounded-[9px] border border-line bg-surface px-2.5 text-[13px] text-muted shadow-xs transition hover:border-line-strong hover:text-fg sm:w-64"
                    :aria-label="t('core.palette.open_label')"
                    @click="palette = true"
                >
                    <Search class="size-4 shrink-0" aria-hidden="true" />
                    <span class="hidden flex-1 text-start sm:block">{{ t('core.palette.trigger') }}</span>
                    <span class="hidden items-center gap-0.5 sm:flex">
                        <span class="kbd">{{ isMac ? '⌘' : 'Ctrl' }}</span><span class="kbd">K</span>
                    </span>
                </button>
                <AppButton
                    variant="ghost"
                    size="icon"
                    :icon="theme.dark ? Sun : Moon"
                    :aria-label="theme.dark ? t('core.palette.light_mode') : t('core.palette.dark_mode')"
                    @click="setTheme(theme.dark ? 'light' : 'dark')"
                />
            </header>

            <ModeBanner class="print:hidden" />
            <LegalBanner class="print:hidden" />
            <DeletionBanner class="print:hidden" />

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
