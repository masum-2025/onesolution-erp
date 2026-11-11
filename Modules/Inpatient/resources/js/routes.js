import schema from './schema';

/**
 * Inpatient screens (children of the app shell). Each page is its own chunk;
 * texts load from the "inpatient" namespace (and "generated" for the wards
 * list, made by make:erp-module).
 */
const meta = { context: 'organization', ns: ['inpatient'], module: 'inpatient' };
const generated = { ...meta, ns: ['inpatient', 'generated'] };
const listPage = () => import('@/components/generated/GeneratedListPage.vue');

export default [
    { path: 'inpatient/board', name: 'inpatient-board', component: () => import('./pages/BoardPage.vue'), meta },
    { path: 'inpatient/admit', name: 'inpatient-admit', component: () => import('./pages/AdmitPage.vue'), meta },
    { path: 'inpatient/admissions', name: 'inpatient-admissions', component: () => import('./pages/AdmissionsPage.vue'), meta },
    { path: 'inpatient/admissions/:id', name: 'inpatient-admission', component: () => import('./pages/AdmissionPage.vue'), meta },
    { path: 'inpatient/admissions/:id/discharge', name: 'inpatient-discharge-paper', component: () => import('./pages/DischargePaperPage.vue'), meta },
    // A patient's own discharge papers in the client's portal (B2B2C): portal members may open this.
    { path: 'portal/discharges', name: 'inpatient-portal', component: () => import('./pages/PortalDischargesPage.vue'), meta: { ...meta, portal: true } },
    { path: 'inpatient/beds', name: 'inpatient-beds', component: () => import('./pages/BedsPage.vue'), meta },
    ...schema.entities.map((entity) => ({
        path: `${schema.slug}/${entity.path}`,
        name: `${schema.key}-${entity.name}`,
        component: listPage,
        props: { schema, entity: entity.name },
        meta: generated,
    })),
];
