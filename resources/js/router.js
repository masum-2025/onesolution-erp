import { reactive } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import { loadNamespaces } from './lib/i18n';
import { loadMe, session } from './lib/session';

/*
 * Every screen is its own lazy chunk; its translations load with it (meta.ns).
 * meta.context says which context a screen needs: 'organization' or 'partner'.
 * Guards only steer the UI: the API checks access on every call.
 */

const AppShell = () => import('./layouts/AppShell.vue');

const routes = [
    { path: '/login', name: 'login', component: () => import('./pages/auth/LoginPage.vue'), meta: { guest: true, ns: ['auth'] } },
    { path: '/choose', name: 'choose', component: () => import('./pages/auth/ChooseContextPage.vue'), meta: { ns: ['auth'] } },
    {
        path: '/',
        component: AppShell,
        children: [
            { path: '', name: 'home', component: () => import('./pages/OverviewPage.vue'), meta: { context: 'organization', ns: ['home', 'orgs'] } },
            {
                path: 'organizations',
                name: 'organizations',
                component: () => import('./pages/organizations/OrganizationsPage.vue'),
                meta: { context: 'organization', ns: ['orgs', 'packaging'] },
            },
            {
                path: 'organizations/:id',
                name: 'organization',
                component: () => import('./pages/organizations/OrganizationPage.vue'),
                meta: { context: 'organization', ns: ['orgs', 'access', 'packaging'] },
            },
            { path: 'audit-log', name: 'audit-log', component: () => import('./pages/trust/AuditLogPage.vue'), meta: { context: 'organization', ns: ['trust'] } },
            { path: 'support-access', name: 'support-access', component: () => import('./pages/trust/SupportAccessPage.vue'), meta: { context: 'organization', ns: ['trust'] } },
            { path: 'export', name: 'export', component: () => import('./pages/trust/ExportPage.vue'), meta: { context: 'organization', ns: ['trust'] } },
            {
                path: 'partner/support',
                name: 'partner-support',
                component: () => import('./pages/partner/PartnerSupportPage.vue'),
                meta: { context: 'partner', ns: ['partner', 'trust'] },
            },
            { path: 'modules', name: 'modules', component: () => import('./pages/modules/ModulesPage.vue'), meta: { context: 'organization', ns: ['modules'] } },
            { path: 'roles', name: 'roles', component: () => import('./pages/access/RolesPage.vue'), meta: { context: 'organization', ns: ['access'] } },
            { path: 'rules', name: 'rules', component: () => import('./pages/rules/RulesPage.vue'), meta: { context: 'organization', ns: ['rules'] } },
            { path: 'approvals', name: 'approvals', component: () => import('./pages/rules/ApprovalsPage.vue'), meta: { context: 'organization', ns: ['rules'] } },
            {
                path: 'apps/:module/:item',
                name: 'module-app',
                component: () => import('./pages/ModuleAppPage.vue'),
                meta: { context: 'organization', ns: ['modules'] },
            },
            {
                path: 'partner/organizations',
                name: 'partner-organizations',
                component: () => import('./pages/partner/PartnerOrganizationsPage.vue'),
                meta: { context: 'partner', ns: ['partner', 'orgs', 'packaging'] },
            },
            {
                path: 'partner/brand',
                name: 'partner-brand',
                component: () => import('./pages/partner/PartnerBrandPage.vue'),
                // The preview reuses the sign-in texts.
                meta: { context: 'partner', ns: ['partner', 'auth'] },
            },
            {
                path: 'partner/domains',
                name: 'partner-domains',
                component: () => import('./pages/partner/PartnerDomainsPage.vue'),
                meta: { context: 'partner', ns: ['partner'] },
            },
            {
                path: 'partner/modules',
                name: 'partner-modules',
                component: () => import('./pages/partner/PartnerModulesPage.vue'),
                meta: { context: 'partner', ns: ['partner', 'modules'] },
            },
            {
                path: 'partner/rules',
                name: 'partner-rules',
                component: () => import('./pages/partner/PartnerRulesPage.vue'),
                meta: { context: 'partner', ns: ['partner', 'rules'] },
            },
            { path: ':pathMatch(.*)*', name: 'not-found', component: () => import('./pages/NotFoundPage.vue') },
        ],
    },
];

/** Top progress bar state while a screen and its data chunk load. */
export const navigation = reactive({ pending: false });

export const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: (to, from, saved) => saved ?? (to.path !== from.path ? { top: 0 } : undefined),
});

function homeFor(context) {
    if (!context) return { name: 'choose' };
    return context.type === 'partner' ? { name: 'partner-organizations' } : { name: 'home' };
}

router.beforeEach(async (to) => {
    navigation.pending = true;

    if (!session.ready) await loadMe().catch(() => null);

    const me = session.me;
    const namespaces = loadNamespaces(['core', ...(to.meta.ns ?? [])]);

    if (to.meta.guest) {
        await namespaces;
        return me ? homeFor(me.context) : true;
    }

    if (!me) {
        await namespaces;
        return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} };
    }

    if (to.meta.context && me.context?.type !== to.meta.context) {
        await namespaces;
        return me.context ? homeFor(me.context) : { name: 'choose', query: { redirect: to.fullPath } };
    }

    // A suspended provider after the grace period: only the export screen works (the API agrees).
    if (me.context?.mode === 'export_only' && to.meta.context === 'organization' && to.name !== 'export') {
        await loadNamespaces(['core', 'trust']);
        return { name: 'export' };
    }

    await namespaces;
    return true;
});

router.afterEach(() => {
    navigation.pending = false;
});

router.onError(() => {
    navigation.pending = false;
});
