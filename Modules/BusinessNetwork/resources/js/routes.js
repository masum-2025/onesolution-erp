/**
 * Business connection screens (children of the app shell). Texts load from
 * this module's "business_network" namespace.
 */
const meta = { context: 'organization', ns: ['business_network'], module: 'business_network' };

export default [
    { path: 'connections', name: 'connections', component: () => import('./pages/ConnectionsPage.vue'), meta },
];
