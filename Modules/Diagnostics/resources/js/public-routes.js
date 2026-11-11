/**
 * Diagnostics pages anyone may open, signed in or not, without the app shell.
 * Always part of the first load (FE-1): the signed-in screens in routes.js are
 * loaded only where the module is on.
 */
export default [
    // A lab report from its time-limited link (SMS or copied).
    { path: '/lab-report/:organization/:token', name: 'diagnostics-public-report', component: () => import('./pages/PublicReportPage.vue'), meta: { public: true, outside: true, ns: ['diagnostics'], module: 'diagnostics' } },
];
