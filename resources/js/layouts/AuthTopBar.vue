<script setup>
import { computed } from 'vue';
import { Moon, Sun } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import BrandMark from '@/components/BrandMark.vue';
import { i18n, setLocale, t } from '@/lib/i18n';
import { setTheme, theme } from '@/lib/theme';

const languages = computed(() => i18n.locales.map((locale) => ({ value: locale, label: t(`core.languages.${locale}`) })));
</script>

<template>
    <div class="flex items-center justify-between gap-3">
        <BrandMark with-name />
        <div class="flex items-center gap-1.5">
            <AppSegmented :model-value="i18n.locale" :options="languages" :label="t('core.language')" size="sm" @update:model-value="setLocale" />
            <AppButton
                variant="ghost"
                size="icon-sm"
                :icon="theme.dark ? Sun : Moon"
                :aria-label="theme.dark ? t('core.palette.light_mode') : t('core.palette.dark_mode')"
                @click="setTheme(theme.dark ? 'light' : 'dark')"
            />
        </div>
    </div>
</template>
