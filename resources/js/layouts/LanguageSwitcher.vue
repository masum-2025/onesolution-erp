<script setup>
import { computed } from 'vue';
import { Check, Languages } from 'lucide-vue-next';
import AppMenu from '@/components/AppMenu.vue';
import { i18n, setLocale, t } from '@/lib/i18n';

/**
 * Language of the screen. Languages are shown by their own name (English,
 * বাংলা), never by a country flag: one language is spoken in many countries.
 */
const items = computed(() =>
    i18n.locales.map((locale) => ({
        label: t(`core.languages.${locale}`),
        icon: locale === i18n.locale ? Check : null,
        onSelect: () => setLocale(locale),
    })),
);
</script>

<template>
    <AppMenu :items="items" width="w-44" :label="t('core.language')">
        <template #trigger="{ toggle, attrs }">
            <button
                type="button"
                v-bind="attrs"
                class="flex h-10 items-center gap-2 rounded-xl border border-line bg-surface px-3 text-[13px] font-semibold text-fg-2 shadow-xs transition hover:border-line-strong hover:text-fg"
                @click="toggle(false)"
                @keydown.down.prevent="toggle(true)"
            >
                <Languages class="size-4 text-muted" aria-hidden="true" />
                <span>{{ t(`core.languages_short.${i18n.locale}`) }}</span>
            </button>
        </template>
    </AppMenu>
</template>
