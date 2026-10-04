/**
 * Payroll screens (children of the app shell). Each page is its own chunk,
 * fetched when opened; texts load from the "payroll" namespace.
 */
const meta = { context: 'organization', ns: ['payroll'], module: 'payroll' };

export default [
    { path: 'payroll', name: 'payroll', component: () => import('./pages/RunsPage.vue'), meta },
    { path: 'payroll/runs/:id', name: 'payroll-run', component: () => import('./pages/RunPage.vue'), meta },
    { path: 'payroll/runs/:run/slips/:slip', name: 'payroll-slip', component: () => import('./pages/SlipPage.vue'), meta: { ...meta, slip: 'staff' } },
    { path: 'payroll/employees', name: 'payroll-employees', component: () => import('./pages/EmployeesPage.vue'), meta },
    { path: 'payroll/employees/:id', name: 'payroll-employee', component: () => import('./pages/EmployeePayPage.vue'), meta },
    { path: 'payroll/components', name: 'payroll-components', component: () => import('./pages/ComponentsPage.vue'), meta },
    { path: 'payroll/structures', name: 'payroll-structures', component: () => import('./pages/StructuresPage.vue'), meta },
    { path: 'payroll/me', name: 'payroll-me', component: () => import('./pages/MySlipsPage.vue'), meta },
    { path: 'payroll/me/slips/:slip', name: 'payroll-my-slip', component: () => import('./pages/SlipPage.vue'), meta: { ...meta, slip: 'mine' } },

    // An employee's own payslips in the client's portal (B2B2C).
    { path: 'portal/payslips', name: 'payroll-portal', component: () => import('./pages/MySlipsPage.vue'), meta: { ...meta, portal: true } },
    { path: 'portal/payslips/:slip', name: 'payroll-portal-slip', component: () => import('./pages/SlipPage.vue'), meta: { ...meta, portal: true, slip: 'portal' } },
];
