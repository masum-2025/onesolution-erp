/**
 * Recruitment screens (children of the app shell). Each page is its own
 * chunk, fetched when opened; texts load from the "recruitment" namespace.
 */
const meta = { context: 'organization', ns: ['recruitment'], module: 'recruitment' };

export default [
    { path: 'recruitment/openings', name: 'recruitment-openings', component: () => import('./pages/OpeningsPage.vue'), meta },
    { path: 'recruitment/openings/:id', name: 'recruitment-pipeline', component: () => import('./pages/PipelinePage.vue'), meta },
    { path: 'recruitment/interviews', name: 'recruitment-interviews', component: () => import('./pages/InterviewsPage.vue'), meta },
    { path: 'recruitment/settings', name: 'recruitment-settings', component: () => import('./pages/SettingsPage.vue'), meta },
    { path: 'recruitment/letter', name: 'recruitment-letter', component: () => import('./pages/LetterPage.vue'), meta },
    { path: 'recruitment/offers/:id', name: 'recruitment-offer', component: () => import('./pages/OfferPage.vue'), meta },
];
