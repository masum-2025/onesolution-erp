<script setup>
import { defineAsyncComponent } from 'vue';
import { Menu, Search } from 'lucide-vue-next';
import BrandMark from '@/components/BrandMark.vue';
import { brand } from '@/lib/brand';
import LanguageSwitcher from './LanguageSwitcher.vue';
import NotificationBell from './NotificationBell.vue';
import QuickCreateMenu from './QuickCreateMenu.vue';
import UserMenu from './UserMenu.vue';
import { i18n, t } from '@/lib/i18n';

const OfflineIndicator = defineAsyncComponent(() => import('./OfflineIndicator.vue'));

/**
 * The top bar: search (opens the command palette), "New", the bell,
 * language and the person. On phones: menu, brand, search, bell, person.
 */
defineProps({
    quickActions: { type: Array, default: () => [] },
    offline: Boolean,
    // Portal members: no "New", no bell (their portal is their whole app).
    simple: Boolean,
});

const emit = defineEmits(['open-nav', 'open-palette']);

const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);
</script>

<template>
    <header class="glass sticky top-0 z-30 print:hidden" :class="!brand.band_colors?.length && 'border-b border-line'">
        <div class="flex h-16 items-center gap-2 px-3 sm:gap-3 sm:px-6 lg:px-8">
            <button
                type="button"
                class="grid size-10 place-items-center rounded-xl text-fg-2 transition hover:bg-subtle lg:hidden"
                :aria-label="t('core.nav.open')"
                @click="emit('open-nav')"
            >
                <Menu class="size-5" aria-hidden="true" />
            </button>
            <BrandMark size="sm" class="lg:hidden" />

            <button
                type="button"
                class="group flex h-10 min-w-0 items-center gap-2.5 rounded-xl border border-line bg-surface px-3 text-[13.5px] text-muted shadow-xs transition hover:border-line-strong hover:text-fg max-sm:ms-auto max-sm:w-10 max-sm:justify-center max-sm:px-0 sm:flex-1 sm:max-w-xl"
                :aria-label="t('core.palette.open_label')"
                @click="emit('open-palette')"
            >
                <Search class="size-[18px] shrink-0 transition-colors group-hover:text-brand-text" aria-hidden="true" />
                <span class="hidden flex-1 truncate text-start sm:block">{{ t('core.palette.trigger') }}</span>
                <span class="hidden items-center gap-1 sm:flex" aria-hidden="true">
                    <span class="kbd">{{ isMac ? '⌘' : 'Ctrl' }}</span><span class="kbd">K</span>
                </span>
            </button>

            <div class="hidden flex-1 sm:block" />

            <OfflineIndicator v-if="offline" />
            <QuickCreateMenu v-if="!simple" :actions="quickActions" />
            <div v-if="!simple && quickActions.length" class="hidden h-6 w-px bg-line sm:block" aria-hidden="true" />
            <NotificationBell v-if="!simple" :key="i18n.locale" />
            <LanguageSwitcher class="hidden sm:block" />
            <UserMenu />
        </div>
        <!-- The brand band: the brand's colors side by side along the bottom edge (decoration only). -->
        <div v-if="brand.band_colors?.length" class="flex h-1" aria-hidden="true">
            <span v-for="(color, index) in brand.band_colors" :key="index" class="flex-1" :style="{ background: color }" />
        </div>
    </header>
</template>
