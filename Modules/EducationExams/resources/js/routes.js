/**
 * Exam screens (children of the app shell). Each page is its own chunk,
 * fetched when opened; texts load from the "education_exams" namespace.
 * Fixed paths come before /exams/:id.
 */
const meta = { context: 'organization', ns: ['education_exams'], module: 'education_exams' };

export default [
    { path: 'exams', name: 'exams-list', component: () => import('./pages/ExamsPage.vue'), meta },
    { path: 'exams/my-sheets', name: 'exams-my-sheets', component: () => import('./pages/MySheetsPage.vue'), meta },
    { path: 'exams/grading', name: 'exams-grading', component: () => import('./pages/GradingPage.vue'), meta },
    { path: 'exams/sheets/:id', name: 'exams-sheet', component: () => import('./pages/MarkSheetPage.vue'), meta },
    { path: 'exams/combinations', name: 'exams-combinations', component: () => import('./pages/CombinationsPage.vue'), meta },
    { path: 'exams/combinations/:id', name: 'exams-combination', component: () => import('./pages/CombinationPage.vue'), meta },
    { path: 'exams/corrections', name: 'exams-corrections', component: () => import('./pages/CorrectionsPage.vue'), meta },
    { path: 'exams/students', name: 'exams-students', component: () => import('./pages/TranscriptPage.vue'), meta },
    { path: 'exams/students/:id', name: 'exams-transcript', component: () => import('./pages/TranscriptPage.vue'), meta },
    { path: 'exams/report-cards/:type(exam|combination)/:id', name: 'exams-report-cards', component: () => import('./pages/ReportCardsPage.vue'), meta },
    { path: 'exams/:id', name: 'exams-exam', component: () => import('./pages/ExamPage.vue'), meta },
    // A family's results in the client's portal (B2B2C): portal members may open these.
    { path: 'portal/results/:record', name: 'exams-portal', component: () => import('./pages/PortalResultsPage.vue'), meta: { ...meta, portal: true } },
    { path: 'portal/results/:record/cards/:id', name: 'exams-portal-card', component: () => import('./pages/PortalReportCardPage.vue'), meta: { ...meta, portal: true } },
];
