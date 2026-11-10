/**
 * Patients screens (children of the app shell). Each page is its own chunk;
 * texts load from the "patients" namespace.
 */
const meta = { context: 'organization', ns: ['patients'], module: 'patients' };

export default [
    { path: 'patients/find', name: 'patients-find', component: () => import('./pages/FindPage.vue'), meta },
    { path: 'patients/merges', name: 'patients-merges', component: () => import('./pages/MergesPage.vue'), meta },
    { path: 'patients/:id', name: 'patients-patient', component: () => import('./pages/PatientPage.vue'), meta },
    { path: 'patients/:id/card', name: 'patients-card', component: () => import('./pages/CardPage.vue'), meta },
    // A patient's own record and history in the client's portal (B2B2C): portal members may open this.
    { path: 'portal/patients', name: 'patients-portal', component: () => import('./pages/PortalPatientsPage.vue'), meta: { ...meta, portal: true } },
];
