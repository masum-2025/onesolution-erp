import schema from './schema';

/**
 * Patient billing screens (children of the app shell). Each page is its own
 * chunk; texts load from the "patient_billing" namespace (and "generated" for
 * the services and panels lists, made by make:erp-module).
 */
const meta = { context: 'organization', ns: ['patient_billing'], module: 'patient_billing' };
const generated = { ...meta, ns: ['patient_billing', 'generated'] };
const listPage = () => import('@/components/generated/GeneratedListPage.vue');

export default [
    { path: 'patient-billing/bills', name: 'patient-billing-bills', component: () => import('./pages/BillsPage.vue'), meta },
    { path: 'patient-billing/bills/:id', name: 'patient-billing-bill', component: () => import('./pages/BillPage.vue'), meta },
    { path: 'patient-billing/bills/:id/receipt', name: 'patient-billing-receipt', component: () => import('./pages/ReceiptPage.vue'), meta },
    { path: 'patient-billing/approvals', name: 'patient-billing-approvals', component: () => import('./pages/ApprovalsPage.vue'), meta },
    { path: 'patient-billing/cash', name: 'patient-billing-cash', component: () => import('./pages/CashPage.vue'), meta },
    // A patient's own bills in the client's portal (B2B2C): portal members may open this.
    { path: 'portal/patient-bills', name: 'patient-billing-portal', component: () => import('./pages/PortalBillsPage.vue'), meta: { ...meta, portal: true } },
    ...schema.entities.map((entity) => ({
        path: `${schema.slug}/${entity.path}`,
        name: `${schema.key}-${entity.name}`,
        component: listPage,
        props: { schema, entity: entity.name },
        meta: generated,
    })),
];
