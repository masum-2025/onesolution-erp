/**
 * Education screens (children of the app shell). Each page is its own chunk,
 * fetched when opened; texts load from the "education" namespace.
 */
const meta = { context: 'organization', ns: ['education'], module: 'education' };

export default [
    { path: 'education', name: 'education', component: () => import('./pages/OverviewPage.vue'), meta },
    { path: 'education/students', name: 'education-students', component: () => import('./pages/StudentsPage.vue'), meta },
    { path: 'education/students/:id', name: 'education-student', component: () => import('./pages/StudentPage.vue'), meta },
    { path: 'education/import', name: 'education-import', component: () => import('./pages/ImportPage.vue'), meta },
    { path: 'education/sections', name: 'education-sections', component: () => import('./pages/SectionsPage.vue'), meta },
    { path: 'education/sections/:id', name: 'education-section', component: () => import('./pages/SectionPage.vue'), meta },
    { path: 'education/admissions', name: 'education-admissions', component: () => import('./pages/AdmissionsPage.vue'), meta },
    { path: 'education/admissions/:id', name: 'education-admission', component: () => import('./pages/AdmissionPage.vue'), meta },
    { path: 'education/promotions', name: 'education-promotions', component: () => import('./pages/PromotionsPage.vue'), meta },
    { path: 'education/promotions/:id', name: 'education-promotion', component: () => import('./pages/PromotionPage.vue'), meta },
    { path: 'education/structure', name: 'education-structure', component: () => import('./pages/StructurePage.vue'), meta },
    { path: 'education/fields', name: 'education-fields', component: () => import('./pages/FieldsPage.vue'), meta },
];
