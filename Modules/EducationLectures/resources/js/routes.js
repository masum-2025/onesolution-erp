/**
 * Class lecture screens (children of the app shell). Each page is its own
 * chunk, fetched when opened; texts load from the "education_lectures" namespace.
 */
const meta = { context: 'organization', ns: ['education_lectures'], module: 'education_lectures' };

export default [
    { path: 'lectures', name: 'lectures-list', component: () => import('./pages/LecturesPage.vue'), meta },
    // A student's lectures in the client's portal (B2B2C); with a record, a parent looks at their child's.
    { path: 'portal/lectures/:record?', name: 'lectures-portal', component: () => import('./pages/PortalLecturesPage.vue'), meta: { ...meta, portal: true } },
];
