<script setup>
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { ChevronDown, LogOut, Monitor, Moon, Palette, Sun, UserRound } from 'lucide-vue-next';
import AppMenu from '@/components/AppMenu.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import { logout, session } from '@/lib/session';
import { confirmSignOut } from '@/lib/signout';
import { i18n, setLocale, t } from '@/lib/i18n';
import { setTheme, theme } from '@/lib/theme';
import { toast } from '@/lib/toast';

/**
 * The person in the header: initials, name and what they are here (role,
 * partner role or membership). Opens theme, language, "My look", account
 * and sign out.
 */
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

const role = computed(() => {
    const context = session.me?.context;
    if (!context) return user.value?.email ?? user.value?.phone ?? '';
    if (context.type === 'partner') return t(`core.partner_roles.${context.role}`);
    return context.roles?.[0]?.name ?? t(`core.membership_types.${context.membership_type}`);
});

const themeOptions = computed(() => [
    { value: 'light', label: t('core.theme.light'), icon: Sun },
    { value: 'dark', label: t('core.theme.dark'), icon: Moon },
    { value: 'system', label: t('core.theme.system'), icon: Monitor },
]);

const languageOptions = computed(() => i18n.locales.map((locale) => ({ value: locale, label: t(`core.languages.${locale}`) })));

const items = computed(() => [
    { label: t('core.nav.account'), icon: UserRound, onSelect: () => router.push({ name: 'account' }) },
    { label: t('core.nav.appearance'), icon: Palette, onSelect: () => router.push({ name: 'appearance' }) },
    { divider: true },
    { label: t('core.auth.sign_out'), icon: LogOut, danger: true, onSelect: signOut },
]);

async function signOut() {
    if (!(await confirmSignOut())) return;
    await logout();
    await router.push({ name: 'login' });
    toast.success(t('core.auth.signed_out'));
}
</script>

<template>
    <AppMenu :items="items" align="end" width="w-[min(18rem,calc(100vw-2rem))]" :label="t('core.user_menu')">
        <template #trigger="{ toggle, attrs }">
            <button
                type="button"
                v-bind="attrs"
                class="flex h-10 items-center gap-2.5 rounded-xl border border-line bg-surface ps-1 pe-1 text-start shadow-xs transition hover:border-line-strong sm:pe-2.5"
                @click="toggle(false)"
                @keydown.down.prevent="toggle(true)"
            >
                <span class="avatar relative grid size-8 shrink-0 place-items-center rounded-[10px] text-[12.5px] font-bold text-brand-fg" aria-hidden="true">
                    {{ initials }}
                    <span class="absolute -end-0.5 -bottom-0.5 size-2.5 rounded-full bg-ok ring-2 ring-surface" />
                </span>
                <span class="hidden min-w-0 md:block">
                    <span class="block max-w-[11rem] truncate text-[13px] leading-tight font-semibold text-fg">{{ user?.name }}</span>
                    <span class="mt-0.5 block max-w-[11rem] truncate text-[12px] leading-tight text-muted">{{ role }}</span>
                </span>
                <ChevronDown class="hidden size-4 shrink-0 text-muted sm:block" aria-hidden="true" />
            </button>
        </template>
        <template #header>
            <div class="px-2.5 pt-2 pb-2">
                <p class="truncate text-[13.5px] font-semibold text-fg">{{ user?.name }}</p>
                <p class="truncate text-[12px] text-muted">{{ user?.email ?? user?.phone }}</p>
            </div>
            <div class="my-1 h-px bg-line" role="separator" />
            <div class="space-y-3 px-2 pt-2 pb-2.5">
                <div>
                    <p class="mb-1.5 text-[11px] font-semibold tracking-wider text-muted uppercase">{{ t('core.theme.label') }}</p>
                    <AppSegmented :model-value="theme.preference" :options="themeOptions" :label="t('core.theme.label')" size="sm" block @update:model-value="setTheme" />
                </div>
                <div>
                    <p class="mb-1.5 text-[11px] font-semibold tracking-wider text-muted uppercase">{{ t('core.language') }}</p>
                    <AppSegmented :model-value="i18n.locale" :options="languageOptions" :label="t('core.language')" size="sm" block @update:model-value="setLocale" />
                </div>
            </div>
            <div class="my-1 h-px bg-line" role="separator" />
        </template>
    </AppMenu>
</template>
