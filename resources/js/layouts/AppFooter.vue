<script setup>
import { brand } from '@/lib/brand';
import { t } from '@/lib/i18n';

/**
 * The foot of every page in the app: the brand's name (runtime data, the
 * partner's for a white-label), the terms and privacy pages, and "powered
 * by" where the brand shows it (branding.show_powered_by). Not printed.
 */
const year = new Date().getFullYear();
</script>

<template>
    <footer class="flex flex-wrap items-center justify-center gap-x-4 gap-y-1 px-4 pb-5 pt-2 text-[12px] text-faint print:hidden">
        <span>© {{ year }} {{ brand.name }}</span>
        <RouterLink :to="{ name: 'legal', params: { kind: 'terms' } }" class="hover:text-muted hover:underline">{{ t('core.footer.terms') }}</RouterLink>
        <RouterLink :to="{ name: 'legal', params: { kind: 'privacy' } }" class="hover:text-muted hover:underline">{{ t('core.footer.privacy') }}</RouterLink>
        <span v-if="brand.powered_by">{{ t('core.powered_by', { name: brand.powered_by }) }}</span>
    </footer>
</template>
