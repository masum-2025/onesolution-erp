/**
 * Attendance screens (children of the app shell). Each page is its own
 * chunk, fetched when opened; texts load from the "attendance" namespace.
 */
const meta = { context: 'organization', ns: ['attendance'], module: 'attendance' };

export default [
    { path: 'attendance', name: 'attendance', component: () => import('./pages/TodayPage.vue'), meta },
    { path: 'attendance/me', name: 'attendance-me', component: () => import('./pages/MyAttendancePage.vue'), meta },
    { path: 'attendance/month', name: 'attendance-month', component: () => import('./pages/MonthPage.vue'), meta },
    { path: 'attendance/corrections', name: 'attendance-corrections', component: () => import('./pages/CorrectionsPage.vue'), meta },
    { path: 'attendance/shifts', name: 'attendance-shifts', component: () => import('./pages/ShiftsPage.vue'), meta },
    { path: 'attendance/holidays', name: 'attendance-holidays', component: () => import('./pages/HolidaysPage.vue'), meta },
    { path: 'attendance/rosters', name: 'attendance-rosters', component: () => import('./pages/RostersPage.vue'), meta },

    // An employee's own days in the client's portal (B2B2C): portal members may open this.
    { path: 'portal/attendance', name: 'attendance-portal', component: () => import('./pages/PortalAttendancePage.vue'), meta: { ...meta, portal: true } },
];
