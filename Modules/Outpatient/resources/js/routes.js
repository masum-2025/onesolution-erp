import schema from './schema';

/**
 * Outpatient screens (children of the app shell). Each page is its own
 * chunk; texts load from the "outpatient" namespace (and "generated" for the
 * doctors and chambers lists, made by make:erp-module).
 */
const meta = { context: 'organization', ns: ['outpatient'], module: 'outpatient' };
const generated = { ...meta, ns: ['outpatient', 'generated'] };
const listPage = () => import('@/components/generated/GeneratedListPage.vue');

export default [
    { path: 'outpatient/serials', name: 'outpatient-serials', component: () => import('./pages/SerialsPage.vue'), meta },
    { path: 'outpatient/book', name: 'outpatient-book', component: () => import('./pages/BookPage.vue'), meta },
    { path: 'outpatient/serials/:id/slip', name: 'outpatient-slip', component: () => import('./pages/SlipPage.vue'), meta },
    { path: 'outpatient/my-patients', name: 'outpatient-mine', component: () => import('./pages/MyPatientsPage.vue'), meta },
    { path: 'outpatient/schedules', name: 'outpatient-schedules', component: () => import('./pages/SchedulesPage.vue'), meta },
    // The waiting-room screen: full screen, numbers only.
    { path: 'outpatient/queue/:chamber', name: 'outpatient-queue', component: () => import('./pages/QueuePage.vue'), meta: { ...meta, focus: true } },
    ...schema.entities.map((entity) => ({
        path: `${schema.slug}/${entity.path}`,
        name: `${schema.key}-${entity.name}`,
        component: listPage,
        props: { schema, entity: entity.name },
        meta: generated,
    })),
];
