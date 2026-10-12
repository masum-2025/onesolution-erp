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
    { path: 'exams/:id', name: 'exams-exam', component: () => import('./pages/ExamPage.vue'), meta },
];
