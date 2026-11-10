/**
 * The partner console's screens (children of the app shell).
 *
 * FE-2: fetched only when someone works in a partner's console (/api/me
 * context "partner", see modules.js), so a company's staff never load them.
 * Each page is its own lazy chunk; the API checks access on every call.
 */
export default [
    {
        path: 'partner/support',
        name: 'partner-support',
        component: () => import('./pages/partner/PartnerSupportPage.vue'),
        meta: { context: 'partner', ns: ['partner', 'trust'] },
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
        path: 'partner/addons',
        name: 'partner-addons',
        component: () => import('./pages/partner/PartnerAddonsPage.vue'),
        meta: { context: 'partner', ns: ['partner', 'addons'] },
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
        path: 'partner/meta-app',
        name: 'partner-meta-app',
        component: () => import('./pages/partner/PartnerMetaAppPage.vue'),
        meta: { context: 'partner', ns: ['partner'] },
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
        path: 'partner/languages',
        name: 'partner-languages',
        component: () => import('./pages/partner/PartnerLanguagesPage.vue'),
        meta: { context: 'partner', ns: ['partner', 'languages'] },
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
    // The partner's company website (MKT-2).
    {
        path: 'partner/website',
        name: 'partner-website',
        component: () => import('./pages/partner/website/WebsiteOverviewPage.vue'),
        meta: { context: 'partner', ns: ['partner', 'website'] },
    },
    {
        path: 'partner/website/pages',
        name: 'partner-website-pages',
        component: () => import('./pages/partner/website/WebsitePagesPage.vue'),
        meta: { context: 'partner', ns: ['partner', 'website'] },
    },
    {
        path: 'partner/website/pages/:id',
        name: 'partner-website-page',
        component: () => import('./pages/partner/website/WebsitePageEditorPage.vue'),
        meta: { context: 'partner', ns: ['partner', 'website'] },
    },
    {
        path: 'partner/website/customize',
        name: 'partner-website-customize',
        component: () => import('./pages/partner/website/WebsiteCustomizePage.vue'),
        meta: { context: 'partner', ns: ['partner', 'website'] },
    },
    {
        path: 'partner/website/media',
        name: 'partner-website-media',
        component: () => import('./pages/partner/website/WebsiteMediaPage.vue'),
        meta: { context: 'partner', ns: ['partner', 'website'] },
    },
    {
        path: 'partner/website/leads',
        name: 'partner-website-leads',
        component: () => import('./pages/partner/website/WebsiteLeadsPage.vue'),
        meta: { context: 'partner', ns: ['partner', 'website'] },
    },
    {
        path: 'partner/website/settings',
        name: 'partner-website-settings',
        component: () => import('./pages/partner/website/WebsiteSettingsPage.vue'),
        meta: { context: 'partner', ns: ['partner', 'website'] },
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
];
