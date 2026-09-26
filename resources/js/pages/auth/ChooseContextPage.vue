<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ChevronRight, DoorOpen, Search, Star } from 'lucide-vue-next';
import AuthTopBar from '@/layouts/AuthTopBar.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import OrgTypeIcon from '@/components/OrgTypeIcon.vue';
import { enterContext, logout, session } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

const router = useRouter();
const route = useRoute();
const query = ref('');
const entering = ref(null);

const organizations = computed(() =>
    [...(session.me?.contexts?.organizations ?? [])].sort((a, b) => Number(b.is_primary) - Number(a.is_primary) || a.name.localeCompare(b.name)),
);
const partners = computed(() => session.me?.contexts?.partners ?? []);
const total = computed(() => organizations.value.length + partners.value.length);

const match = (name) => !query.value.trim() || name.toLocaleLowerCase().includes(query.value.trim().toLocaleLowerCase());
const shownOrganizations = computed(() => organizations.value.filter((item) => match(item.name)));
const shownPartners = computed(() => partners.value.filter((item) => match(item.name)));

function safeRedirect(target) {
    return typeof target === 'string' && target.startsWith('/') && !target.startsWith('//') ? target : null;
}

async function choose(key, target, fallback) {
    entering.value = key;
    try {
        await enterContext(target);
        const redirect = safeRedirect(route.query.redirect);
        const partnerTarget = redirect?.startsWith('/partner');
        const fits = redirect && (target.partner_id ? partnerTarget : !partnerTarget);
        await router.push(fits ? redirect : fallback);
    } catch (error) {
        toast.error(error.message);
        entering.value = null;
    }
}

async function signOut() {
    await logout();
    router.push({ name: 'login' });
}

onMounted(() => {
    // One place to go and the user just signed in: go straight there.
    if (route.query.auto === '1' && total.value === 1) {
        const org = organizations.value[0];
        const partner = partners.value[0];
        if (org) choose(org.organization_id, { organization_id: org.organization_id }, '/');
        else choose(partner.partner_id, { partner_id: partner.partner_id }, '/partner/organizations');
    }
});
</script>

<template>
    <div class="min-h-dvh bg-canvas px-5 py-5 sm:px-10">
        <AuthTopBar class="mx-auto max-w-5xl" />

        <div class="mx-auto mt-10 w-full max-w-[520px] animate-rise sm:mt-16">
            <h1 class="text-[24px] leading-tight font-semibold tracking-[-0.02em] text-fg">{{ t('auth.choose.title') }}</h1>
            <p class="mt-2 text-[14px] text-muted">
                {{ t('auth.choose.signed_in_as', { email: session.me?.user?.email ?? session.me?.user?.phone ?? '' }) }}
            </p>

            <div class="card mt-7 overflow-hidden">
                <div v-if="total > 6" class="flex items-center gap-2 border-b border-line px-4">
                    <Search class="size-4 shrink-0 text-faint" aria-hidden="true" />
                    <input
                        v-model="query"
                        type="search"
                        class="h-12 w-full bg-transparent text-[14px] text-fg outline-none placeholder:text-faint"
                        :placeholder="t('auth.choose.search')"
                        :aria-label="t('auth.choose.search')"
                    />
                </div>

                <EmptyState v-if="total === 0" :icon="DoorOpen" :title="t('auth.choose.none_title')" :text="t('auth.choose.none_text')">
                    <AppButton @click="signOut">{{ t('core.auth.sign_out') }}</AppButton>
                </EmptyState>

                <template v-else>
                    <section v-if="shownOrganizations.length">
                        <h2 class="px-4 pt-3.5 pb-1.5 text-[11px] font-semibold tracking-wider text-faint uppercase sm:px-5">{{ t('auth.choose.organizations') }}</h2>
                        <ul class="px-2 pb-2">
                            <li v-for="item in shownOrganizations" :key="item.organization_id">
                                <button
                                    type="button"
                                    class="group flex w-full items-center gap-3.5 rounded-xl px-2.5 py-2.5 text-start transition-colors hover:bg-subtle disabled:opacity-60 sm:px-3"
                                    :disabled="entering !== null"
                                    @click="choose(item.organization_id, { organization_id: item.organization_id }, '/')"
                                >
                                    <OrgTypeIcon :type="item.type" size="lg" />
                                    <span class="min-w-0 flex-1">
                                        <span class="flex items-center gap-2">
                                            <span class="truncate text-[14px] font-medium text-fg">{{ item.name }}</span>
                                            <Star v-if="item.is_primary" class="size-3.5 shrink-0 fill-current text-warn" :aria-label="t('auth.choose.primary')" />
                                        </span>
                                        <span class="mt-0.5 flex items-center gap-1.5 text-[12.5px] text-muted">
                                            {{ t(`core.org_types.${item.type}`) }}
                                            <span aria-hidden="true">·</span>
                                            {{ t(`core.membership_types.${item.membership_type}`) }}
                                        </span>
                                    </span>
                                    <svg v-if="entering === item.organization_id" class="size-4 animate-spin text-muted" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3" />
                                        <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                                    </svg>
                                    <ChevronRight v-else class="size-4 shrink-0 text-faint transition group-hover:translate-x-0.5 group-hover:text-fg-2 rtl:rotate-180 rtl:group-hover:-translate-x-0.5" aria-hidden="true" />
                                </button>
                            </li>
                        </ul>
                    </section>

                    <section v-if="shownPartners.length" :class="shownOrganizations.length ? 'border-t border-line' : ''">
                        <h2 class="px-4 pt-3.5 pb-1.5 text-[11px] font-semibold tracking-wider text-faint uppercase sm:px-5">{{ t('auth.choose.partners') }}</h2>
                        <ul class="px-2 pb-2">
                            <li v-for="item in shownPartners" :key="item.partner_id">
                                <button
                                    type="button"
                                    class="group flex w-full items-center gap-3.5 rounded-xl px-2.5 py-2.5 text-start transition-colors hover:bg-subtle disabled:opacity-60 sm:px-3"
                                    :disabled="entering !== null"
                                    @click="choose(item.partner_id, { partner_id: item.partner_id }, '/partner/organizations')"
                                >
                                    <OrgTypeIcon type="partner" size="lg" />
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-[14px] font-medium text-fg">{{ item.name }}</span>
                                        <span class="mt-0.5 block text-[12.5px] text-muted">{{ t('auth.choose.partner_console') }}</span>
                                    </span>
                                    <AppBadge tone="neutral">{{ t(`core.partner_roles.${item.role}`) }}</AppBadge>
                                    <ChevronRight class="size-4 shrink-0 text-faint rtl:rotate-180" aria-hidden="true" />
                                </button>
                            </li>
                        </ul>
                    </section>

                    <p v-if="!shownOrganizations.length && !shownPartners.length" class="px-5 py-10 text-center text-[13.5px] text-muted">
                        {{ t('auth.choose.no_match') }}
                    </p>
                </template>
            </div>

            <div class="mt-5 flex justify-center">
                <AppButton variant="ghost" size="sm" @click="signOut">{{ t('auth.choose.different_account') }}</AppButton>
            </div>
        </div>
    </div>
</template>
