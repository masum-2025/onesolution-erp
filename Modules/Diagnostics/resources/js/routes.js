import schema from './schema';

/**
 * Diagnostics screens (children of the app shell). Each page is its own
 * chunk; texts load from the "diagnostics" namespace (and "generated" for the
 * tests and referring doctors lists, made by make:erp-module).
 */
const meta = { context: 'organization', ns: ['diagnostics'], module: 'diagnostics' };
const generated = { ...meta, ns: ['diagnostics', 'generated'] };
const listPage = () => import('@/components/generated/GeneratedListPage.vue');

export default [
    { path: 'diagnostics/orders', name: 'diagnostics-orders', component: () => import('./pages/OrdersPage.vue'), meta },
    { path: 'diagnostics/orders/:id', name: 'diagnostics-order', component: () => import('./pages/OrderPage.vue'), meta },
    { path: 'diagnostics/orders/:id/labels', name: 'diagnostics-labels', component: () => import('./pages/LabelsPage.vue'), meta },
    { path: 'diagnostics/orders/:id/report', name: 'diagnostics-report', component: () => import('./pages/ReportPage.vue'), meta },
    { path: 'diagnostics/parameters', name: 'diagnostics-parameters', component: () => import('./pages/ParametersPage.vue'), meta },
    // A patient's own lab reports in the client's portal (B2B2C): portal members may open this.
    { path: 'portal/lab-reports', name: 'diagnostics-portal', component: () => import('./pages/PortalReportsPage.vue'), meta: { ...meta, portal: true } },
    ...schema.entities.map((entity) => ({
        path: `${schema.slug}/${entity.path}`,
        name: `${schema.key}-${entity.name}`,
        component: listPage,
        props: { schema, entity: entity.name },
        meta: generated,
    })),
];
