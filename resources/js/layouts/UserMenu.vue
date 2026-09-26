<script setup>
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { ChevronsUpDown, LogOut, Monitor, Moon, Sun, UserRound } from 'lucide-vue-next';
import AppMenu from '@/components/AppMenu.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import { logout, session } from '@/lib/session';
import { i18n, setLocale, t } from '@/lib/i18n';
import { setTheme, theme } from '@/lib/theme';
import { toast } from '@/lib/toast';

const router = useRouter();
const user = computed(() => session.me?.user);

const initials = computed(() =>
    (user.value?.name ?? '?')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join(''),
);

const themeOptions = computed(() => [
    { value: 'light', label: t('core.theme.light'), icon: Sun },
    { value: 'dark', label: t('core.theme.dark'), icon: Moon },
    { value: 'system', label: t('core.theme.system'), icon: Monitor },
]);

const languageOptions = computed(() => i18n.locales.map((locale) => ({ value: locale, label: t(`core.languages.${locale}`) })));

const items = computed(() => [
    { label: t('core.nav.account'), icon: UserRound, onSelect: () => router.push({ name: 'account' }) },
    { label: t('core.auth.sign_out'), icon: LogOut, danger: true, onSelect: signOut },
]);

async function signOut() {
    await logout();
    await router.push({ name: 'login' });
    toast.success(t('core.auth.signed_out'));
}
</script>

<template>
    <AppMenu :items="items" align="start" placement="top" width="w-[min(17rem,calc(100vw-2rem))]" :label="t('core.user_menu')">
        <template #trigger="{ toggle, attrs }">
            <button type="button" v-bind="attrs" class="flex w-full items-center gap-2.5 rounded-xl p-1.5 text-start transition-colors hover:bg-subtle" @click="toggle(false)">
                <span class="grid size-8 shrink-0 place-items-center rounded-full bg-gradient-to-br from-subtle to-line-strong text-[12px] font-semibold text-fg-2 ring-1 ring-line" aria-hidden="true">
                    {{ initials }}
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[13px] leading-tight font-medium text-fg">{{ user?.name }}</span>
                    <span class="mt-0.5 block truncate text-[12px] leading-tight text-muted">{{ user?.email ?? user?.phone }}</span>
                </span>
                <ChevronsUpDown class="size-4 shrink-0 text-faint" aria-hidden="true" />
            </button>
        </template>
        <template #header>
            <div class="space-y-3 px-2 pt-2 pb-2.5">
                <div>
                    <p class="mb-1.5 text-[11px] font-semibold tracking-wider text-faint uppercase">{{ t('core.theme.label') }}</p>
                    <AppSegmented :model-value="theme.preference" :options="themeOptions" :label="t('core.theme.label')" size="sm" block @update:model-value="setTheme" />
                </div>
                <div>
                    <p class="mb-1.5 text-[11px] font-semibold tracking-wider text-faint uppercase">{{ t('core.language') }}</p>
                    <AppSegmented :model-value="i18n.locale" :options="languageOptions" :label="t('core.language')" size="sm" block @update:model-value="setLocale" />
                </div>
            </div>
            <div class="my-1 h-px bg-line" role="separator" />
        </template>
    </AppMenu>
</template>
