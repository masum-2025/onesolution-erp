/**
 * Appraisal screens (children of the app shell). Each page is its own
 * chunk, fetched when opened; texts load from the "appraisals" namespace.
 */
const meta = { context: 'organization', ns: ['appraisals'], module: 'appraisals' };

export default [
    { path: 'appraisals/me', name: 'appraisals-me', component: () => import('./pages/MyAppraisalsPage.vue'), meta },
    { path: 'appraisals/team', name: 'appraisals-team', component: () => import('./pages/TeamPage.vue'), meta },
    { path: 'appraisals/cycles', name: 'appraisals-cycles', component: () => import('./pages/CyclesPage.vue'), meta },
    { path: 'appraisals/cycles/:id', name: 'appraisals-cycle', component: () => import('./pages/CyclePage.vue'), meta },
    { path: 'appraisals/reviews/:id', name: 'appraisals-review', component: () => import('./pages/ReviewPage.vue'), meta },
    { path: 'appraisals/competencies', name: 'appraisals-competencies', component: () => import('./pages/CompetenciesPage.vue'), meta },
];
