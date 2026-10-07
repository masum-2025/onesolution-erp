/**
 * CRM screens (children of the app shell). Each page is its own chunk,
 * fetched when opened; texts load from the "crm" namespace.
 */
const meta = { context: 'organization', ns: ['crm'], module: 'crm' };

export default [
    { path: 'crm', name: 'crm', component: () => import('./pages/ContactsPage.vue'), meta },
    { path: 'crm/contacts', name: 'crm-contacts', component: () => import('./pages/ContactsPage.vue'), meta },
    { path: 'crm/contacts/:id', name: 'crm-contact', component: () => import('./pages/ContactPage.vue'), meta },
    { path: 'crm/deals', name: 'crm-deals', component: () => import('./pages/DealsPage.vue'), meta },
    { path: 'crm/tasks', name: 'crm-tasks', component: () => import('./pages/TasksPage.vue'), meta },
    { path: 'crm/quotes', name: 'crm-quotes', component: () => import('./pages/QuotesPage.vue'), meta },
    { path: 'crm/quotes/new/:kind', name: 'crm-quote-new', component: () => import('./pages/QuoteFormPage.vue'), meta },
    { path: 'crm/quotes/:id/edit', name: 'crm-quote-edit', component: () => import('./pages/QuoteFormPage.vue'), meta },
    { path: 'crm/quotes/:id', name: 'crm-quote', component: () => import('./pages/QuotePage.vue'), meta },
    { path: 'crm/pipelines', name: 'crm-pipelines', component: () => import('./pages/PipelinesPage.vue'), meta },
    { path: 'crm/fields', name: 'crm-fields', component: () => import('./pages/FieldsPage.vue'), meta },
];
