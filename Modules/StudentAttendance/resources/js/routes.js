/**
 * Students' attendance screens (children of the app shell). Each page is its
 * own chunk, fetched when opened; texts load from the "student_attendance" namespace.
 */
const meta = { context: 'organization', ns: ['student_attendance'], module: 'student_attendance' };

export default [
    { path: 'student-attendance', name: 'student-attendance-today', component: () => import('./pages/TodayPage.vue'), meta },
    // One section's sheet of a day (?period= by period); made for a phone.
    { path: 'student-attendance/sheet/:section/:date', name: 'student-attendance-sheet', component: () => import('./pages/SheetPage.vue'), meta },
    { path: 'student-attendance/register', name: 'student-attendance-register', component: () => import('./pages/RegisterPage.vue'), meta },
    { path: 'student-attendance/periods', name: 'student-attendance-periods', component: () => import('./pages/PeriodsPage.vue'), meta },
    { path: 'student-attendance/holidays', name: 'student-attendance-holidays', component: () => import('./pages/HolidaysPage.vue'), meta },
    // A student's attendance in the client's portal (B2B2C); with a record, a parent looks at their child's.
    { path: 'portal/student-attendance/:record?', name: 'student-attendance-portal', component: () => import('./pages/PortalAttendancePage.vue'), meta: { ...meta, portal: true } },
];
