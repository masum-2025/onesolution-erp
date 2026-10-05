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
    { path: 'payroll/me/bonuses/:line', name: 'payroll-my-bonus', component: () => import('./pages/BonusSlipPage.vue'), meta: { ...meta, slip: 'mine' } },
    { path: 'payroll/loans', name: 'payroll-loans', component: () => import('./pages/LoansPage.vue'), meta },
    { path: 'payroll/loans/:id', name: 'payroll-loan', component: () => import('./pages/LoanPage.vue'), meta },
    { path: 'payroll/bonuses', name: 'payroll-bonuses', component: () => import('./pages/BonusesPage.vue'), meta },
    { path: 'payroll/bonuses/:id', name: 'payroll-bonus', component: () => import('./pages/BonusPage.vue'), meta },
    { path: 'payroll/settlements', name: 'payroll-settlements', component: () => import('./pages/SettlementsPage.vue'), meta },
    { path: 'payroll/settlements/:id', name: 'payroll-settlement', component: () => import('./pages/SettlementPage.vue'), meta },
    { path: 'payroll/settlements/:id/print', name: 'payroll-settlement-print', component: () => import('./pages/SettlementSlipPage.vue'), meta: { ...meta, slip: 'staff' } },
    { path: 'payroll/me/settlements/:id', name: 'payroll-my-settlement', component: () => import('./pages/SettlementSlipPage.vue'), meta: { ...meta, slip: 'mine' } },
    { path: 'payroll/fund', name: 'payroll-fund', component: () => import('./pages/FundPage.vue'), meta },

    // An employee's own payslips in the client's portal (B2B2C).
    { path: 'portal/payslips', name: 'payroll-portal', component: () => import('./pages/MySlipsPage.vue'), meta: { ...meta, portal: true } },
    { path: 'portal/payslips/:slip', name: 'payroll-portal-slip', component: () => import('./pages/SlipPage.vue'), meta: { ...meta, portal: true, slip: 'portal' } },
    { path: 'portal/bonuses/:line', name: 'payroll-portal-bonus', component: () => import('./pages/BonusSlipPage.vue'), meta: { ...meta, portal: true, slip: 'portal' } },
    { path: 'portal/settlements/:id', name: 'payroll-portal-settlement', component: () => import('./pages/SettlementSlipPage.vue'), meta: { ...meta, portal: true, slip: 'portal' } },
];
