/**
 * HRM screens (children of the app shell). Each page is its own chunk,
 * fetched when opened; texts load from this module's "hrm" namespace.
 */
const meta = { context: 'organization', ns: ['hrm'], module: 'hrm' };

export default [
    { path: 'hrm', name: 'hrm', component: () => import('./pages/EmployeesPage.vue'), meta },
    { path: 'hrm/new', name: 'hrm-hire', component: () => import('./pages/HirePage.vue'), meta },
    { path: 'hrm/positions', name: 'hrm-positions', component: () => import('./pages/PositionsPage.vue'), meta },
    { path: 'hrm/org-chart', name: 'hrm-org-chart', component: () => import('./pages/OrgChartPage.vue'), meta },
    { path: 'hrm/fields', name: 'hrm-fields', component: () => import('./pages/FieldsPage.vue'), meta },
    { path: 'hrm/import', name: 'hrm-import', component: () => import('./pages/ImportPage.vue'), meta },
    { path: 'hrm/employees/:id', name: 'hrm-employee', component: () => import('./pages/EmployeePage.vue'), meta },
];
