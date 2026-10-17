import schema from './schema';

/**
 * Fleet screens (made by make:erp-module): one list per entity, from the shared
 * generated list page; texts from the "fleet" and "generated" namespaces.
 */
const meta = { context: 'organization', ns: ['fleet', 'generated'], module: 'fleet' };
const page = () => import('@/components/generated/GeneratedListPage.vue');

export default schema.entities.map((entity) => ({
    path: `${schema.slug}/${entity.path}`,
    name: `${schema.key}-${entity.name}`,
    component: page,
    props: { schema, entity: entity.name },
    meta,
}));
