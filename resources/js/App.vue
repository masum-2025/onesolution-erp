<script setup>
import { defineAsyncComponent, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import AppToaster from './components/AppToaster.vue';
import ConfirmHost from './components/ConfirmHost.vue';
import { navigation } from './router';
import { on } from './lib/events';
import { stepUp } from './lib/stepUp';

// Loaded the first time a sensitive action asks for the second step again (Phase 8-1).
const StepUpDialog = defineAsyncComponent(() => import('./components/StepUpDialog.vue'));
import { loadMe, session } from './lib/session';
import { resetCaches } from './lib/cache';
import { toast } from './lib/toast';
import { t } from './lib/i18n';

const router = useRouter();

// The server ended the session (expired, signed out elsewhere): back to sign-in.
const offAuth = on('unauthenticated', () => {
    const wasSignedIn = session.me !== null;
    session.me = null;
    resetCaches();
    if (router.currentRoute.value.name !== 'login') {
        router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } });
        if (wasSignedIn) toast.info(t('core.session.ended'));
    }
});

// The chosen organization is no longer available (expired or access removed).
const offContext = on('context-lost', () => {
    if (session.me) session.me.context = null;
    resetCaches();
    if (router.currentRoute.value.name !== 'choose') {
        router.push({ name: 'choose' });
        toast.info(t('core.session.context_ended'));
    }
});

// New language: server data (names, labels) must be fetched again in it.
const offLocale = on('locale-changed', () => {
    resetCaches();
    if (session.me) loadMe().catch(() => null);
});

onBeforeUnmount(() => {
    offAuth();
    offContext();
    offLocale();
});
</script>

<template>
    <div
        class="pointer-events-none fixed inset-x-0 top-0 z-[70] h-0.5 origin-left bg-brand transition-[opacity,transform] duration-500 ease-out rtl:origin-right"
        :class="navigation.pending ? 'scale-x-75 opacity-100' : 'scale-x-100 opacity-0'"
        aria-hidden="true"
    />
    <RouterView />
    <AppToaster />
    <ConfirmHost />
    <StepUpDialog v-if="stepUp.open || stepUp.resolve" />
</template>
