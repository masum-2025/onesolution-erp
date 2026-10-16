/**
 * Teacher feedback screens (children of the app shell). Each page is its own
 * chunk, fetched when opened; texts load from the "teacher_feedback" namespace.
 */
const meta = { context: 'organization', ns: ['teacher_feedback'], module: 'teacher_feedback' };

export default [
    { path: 'teacher-feedback', name: 'tfb-rounds', component: () => import('./pages/RoundsPage.vue'), meta },
    { path: 'teacher-feedback/mine', name: 'tfb-mine', component: () => import('./pages/MyFeedbackPage.vue'), meta },
    { path: 'teacher-feedback/rounds/:id', name: 'tfb-round', component: () => import('./pages/RoundPage.vue'), meta },
    // Rating in the client's portal (B2B2C); with a record, a parent rates their child's teachers.
    { path: 'portal/teacher-feedback/:record?', name: 'tfb-portal', component: () => import('./pages/PortalFeedbackPage.vue'), meta: { ...meta, portal: true } },
];
