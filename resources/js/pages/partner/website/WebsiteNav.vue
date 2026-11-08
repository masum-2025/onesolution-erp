<script setup>
import { RouterLink, useRoute } from 'vue-router';
import { t } from '@/lib/i18n';

/** The website screens side by side: overview, pages, settings. */
const route = useRoute();
const LINKS = [
    { to: '/partner/website', key: 'overview' },
    { to: '/partner/website/customize', key: 'customize' },
    { to: '/partner/website/pages', key: 'pages' },
    { to: '/partner/website/media', key: 'media' },
    { to: '/partner/website/leads', key: 'leads' },
    { to: '/partner/website/settings', key: 'settings' },
];
</script>

<template>
    <nav class="-mb-px mb-5 flex gap-1 overflow-x-auto border-b border-line" :aria-label="t('website.nav.label')">
        <RouterLink
            v-for="link in LINKS"
            :key="link.key"
            :to="link.to"
            class="relative inline-flex h-10 shrink-0 items-center px-3 text-[13.5px] font-medium transition-colors"
            :class="route.path === link.to ? 'text-brand-text after:absolute after:inset-x-2 after:bottom-0 after:h-0.5 after:rounded-full after:bg-brand' : 'text-muted hover:text-fg'"
            :aria-current="route.path === link.to ? 'page' : undefined"
        >
            {{ t(`website.nav.${link.key}`) }}
        </RouterLink>
    </nav>
</template>
