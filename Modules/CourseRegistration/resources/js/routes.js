/**
 * Course registration screens (children of the app shell). Each page is its
 * own chunk, fetched when opened; texts load from the "course_registration"
 * namespace.
 */
const meta = { context: 'organization', ns: ['course_registration'], module: 'course_registration' };

export default [
    { path: 'course-registration', name: 'crs-offerings', component: () => import('./pages/OfferingsPage.vue'), meta },
    { path: 'course-registration/offerings/:id', name: 'crs-offering', component: () => import('./pages/OfferingPage.vue'), meta },
    { path: 'course-registration/registrations', name: 'crs-registrations', component: () => import('./pages/RegistrationsPage.vue'), meta },
    { path: 'course-registration/registrations/:id', name: 'crs-registration', component: () => import('./pages/RegistrationPage.vue'), meta },
    // A student's own registration in the client's portal (B2B2C); with a record, a parent looks at their child's.
    { path: 'portal/course-registration/:record?', name: 'crs-portal', component: () => import('./pages/PortalRegistrationPage.vue'), meta: { ...meta, portal: true } },
];
