import { reactive } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import { loadNamespaces } from './lib/i18n';
import { loadMe, session } from './lib/session';
import { moduleRoutes } from './modules';

/*
 * Every screen is its own lazy chunk; its translations load with it (meta.ns).
 * meta.context says which context a screen needs: 'organization' or 'partner'.
 * Guards only steer the UI: the API checks access on every call.
 */

const AppShell = () => import('./layouts/AppShell.vue');

const routes = [
    { path: '/login', name: 'login', component: () => import('./pages/auth/LoginPage.vue'), meta: { guest: true, ns: ['auth', 'identity'] } },
    { path: '/invite/:token', name: 'invite', component: () => import('./pages/auth/InvitationPage.vue'), meta: { public: true, ns: ['auth', 'invite'] } },
    // Self-serve sign-up and recovery (Phase 5C); the terms are public to read before signing up.
    { path: '/signup', name: 'signup', component: () => import('./pages/auth/SignupPage.vue'), meta: { guest: true, ns: ['auth', 'identity'] } },
    { path: '/forgot', name: 'forgot', component: () => import('./pages/auth/ForgotPage.vue'), meta: { public: true, ns: ['auth', 'identity'] } },
    { path: '/legal/:kind(terms|privacy)', name: 'legal', component: () => import('./pages/auth/LegalPage.vue'), meta: { public: true, ns: ['identity'] } },
    { path: '/welcome', name: 'welcome', component: () => import('./pages/auth/WelcomePage.vue'), meta: { ns: ['identity'] } },
    { path: '/choose', name: 'choose', component: () => import('./pages/auth/ChooseContextPage.vue'), meta: { ns: ['auth'] } },
    // Joining a client's portal with an invitation link or code (Phase 5C-4), signed in or not.
    { path: '/portal/join/:key?', name: 'portal-join', component: () => import('./pages/portal/JoinPage.vue'), meta: { public: true, ns: ['auth', 'identity', 'portal'] } },
    {
        path: '/',
        component: AppShell,
        children: [
            { path: '', name: 'home', component: () => import('./pages/OverviewPage.vue'), meta: { context: 'organization', ns: ['home', 'orgs', 'dashboard'] } },
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
            // The person's own account: the same in every context.
            { path: 'account', name: 'account', component: () => import('./pages/account/AccountPage.vue'), meta: { ns: ['identity', 'offline', 'security'] } },
            // Every module's dashboard and settings page (UI-1b).
            { path: 'm/:module([a-z][a-z0-9_]+)', name: 'module-dashboard', component: () => import('./pages/modules/ModuleDashboardPage.vue'), meta: { context: 'organization', ns: ['dashboard'] } },
            { path: 'm/:module([a-z][a-z0-9_]+)/settings', name: 'module-settings', component: () => import('./pages/modules/ModuleSettingsPage.vue'), meta: { context: 'organization', ns: ['dashboard', 'rules'] } },
            // The person's own look of the app (template, color, readability).
            { path: 'account/appearance', name: 'appearance', component: () => import('./pages/account/AppearancePage.vue'), meta: { ns: ['appearance'] } },
            { path: 'audit-log', name: 'audit-log', component: () => import('./pages/trust/AuditLogPage.vue'), meta: { context: 'organization', ns: ['trust'] } },
            { path: 'support-access', name: 'support-access', component: () => import('./pages/trust/SupportAccessPage.vue'), meta: { context: 'organization', ns: ['trust'] } },
            { path: 'branding', name: 'brand', component: () => import('./pages/brand/ClientBrandPage.vue'), meta: { context: 'organization', ns: ['brand'] } },
            { path: 'provider', name: 'provider', component: () => import('./pages/provider/ProviderPage.vue'), meta: { context: 'organization', ns: ['provider'] } },
            { path: 'billing', name: 'billing', component: () => import('./pages/billing/BillingPage.vue'), meta: { context: 'organization', ns: ['billing'] } },
            { path: 'billing/invoices/:id', name: 'invoice', component: () => import('./pages/billing/InvoicePage.vue'), meta: { context: 'organization', ns: ['billing'] } },
            // B2B2C portal (Phase 5C-4): a member's own records, and the client's side.
            { path: 'portal', name: 'portal-home', component: () => import('./pages/portal/PortalHomePage.vue'), meta: { context: 'organization', portal: true, ns: ['portal'] } },
            { path: 'portal/records/:id', name: 'portal-record', component: () => import('./pages/portal/PortalRecordPage.vue'), meta: { context: 'organization', portal: true, ns: ['portal'] } },
            // Offline mode (Phase 7): devices and held changes.
            { path: 'offline', name: 'offline', component: () => import('./pages/offline/OfflineAdminPage.vue'), meta: { context: 'organization', ns: ['offline'] } },
            { path: 'portal-admin', name: 'portal-admin', component: () => import('./pages/portal/PortalAdminPage.vue'), meta: { context: 'organization', ns: ['portal'] } },
            // A client's own payment gateway accounts (Phase 6).
            { path: 'online-payments', name: 'online-payments', component: () => import('./pages/payments/MerchantAccountsPage.vue'), meta: { context: 'organization', ns: ['payments'] } },
            // A personal workspace becomes a company (Phase 5C-3).
            { path: 'upgrade', name: 'upgrade', component: () => import('./pages/billing/UpgradePage.vue'), meta: { context: 'organization', ns: ['billing'] } },
            // Where the payment page sends the person back (Phase 5C-2).
            { path: 'billing/payments/:id', name: 'payment', component: () => import('./pages/billing/PaymentStatusPage.vue'), meta: { context: 'organization', ns: ['billing'] } },
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
            // Screens of business modules (Modules/*/resources/js/routes.js).
            ...moduleRoutes,
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
                meta: { context: 'partner', ns: ['partner', 'orgs', 'packaging', 'billing'] },
            },
            {
                path: 'partner/plans',
                name: 'partner-plans',
                component: () => import('./pages/partner/PartnerPlansPage.vue'),
                meta: { context: 'partner', ns: ['partner', 'billing'] },
            },
            {
                path: 'partner/billing',
                name: 'partner-billing',
                component: () => import('./pages/partner/PartnerBillingPage.vue'),
                meta: { context: 'partner', ns: ['partner', 'billing'] },
            },
            {
                path: 'partner/billing/invoices/:id',
                name: 'partner-invoice',
                component: () => import('./pages/billing/InvoicePage.vue'),
                meta: { context: 'partner', ns: ['partner', 'billing'] },
            },
            {
                path: 'partner/api-keys',
                name: 'partner-api-keys',
                component: () => import('./pages/partner/PartnerApiKeysPage.vue'),
                meta: { context: 'partner', ns: ['partner'] },
            },
            {
                path: 'partner/transfers',
                name: 'partner-transfers',
                component: () => import('./pages/partner/PartnerTransfersPage.vue'),
                meta: { context: 'partner', ns: ['partner', 'provider'] },
            },
            {
                path: 'partner/legal',
                name: 'partner-legal',
                component: () => import('./pages/partner/PartnerLegalPage.vue'),
                meta: { context: 'partner', ns: ['partner', 'provider'] },
            },
            {
                path: 'partner/messaging',
                name: 'partner-messaging',
                component: () => import('./pages/partner/PartnerMessagingPage.vue'),
                meta: { context: 'partner', ns: ['partner', 'messaging'] },
            },
            {
                path: 'partner/templates',
                name: 'partner-templates',
                component: () => import('./pages/partner/PartnerTemplatesPage.vue'),
                meta: { context: 'partner', ns: ['partner', 'messaging'] },
            },
            {
                path: 'partner/templates/:key',
                name: 'partner-template',
                component: () => import('./pages/partner/PartnerTemplateEditorPage.vue'),
                meta: { context: 'partner', ns: ['partner', 'messaging'] },
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
    scrollBehavior: (to, from, saved) => {
        if (saved) return saved;
        // A section of a screen (e.g. /account#security): once the screen has drawn it.
        if (to.hash) return new Promise((resolve) => setTimeout(() => resolve({ el: to.hash, top: 72, behavior: 'smooth' }), 350));
        return to.path !== from.path ? { top: 0 } : undefined;
    },
});

function homeFor(context) {
    if (!context) return { name: 'choose' };
    if (context.type === 'partner') return { name: 'partner-organizations' };
    return context.membership_type === 'portal' ? { name: 'portal-home' } : { name: 'home' };
}

router.beforeEach(async (to) => {
    navigation.pending = true;

    if (!session.ready) await loadMe().catch(() => null);

    const me = session.me;
    const namespaces = loadNamespaces(['core', ...(to.meta.ns ?? [])]);

    // Open to anyone, signed in or not (an invitation link may be opened on a shared computer).
    if (to.meta.public) {
        await namespaces;
        return true;
    }

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

    // A portal member sees the portal and their own account only (the API agrees: portal_only).
    if (me.context?.membership_type === 'portal' && to.meta.context === 'organization' && !to.meta.portal) {
        await loadNamespaces(['core', 'portal']);
        return { name: 'portal-home' };
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
