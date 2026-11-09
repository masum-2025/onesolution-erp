/**
 * Online order screens (children of the app shell). Each page is its own
 * chunk; texts load from the "online_orders" namespace.
 */
const meta = { context: 'organization', ns: ['online_orders'], module: 'online_orders' };

export default [
    { path: 'online-orders', name: 'online-orders', component: () => import('./pages/OrdersPage.vue'), meta },
    { path: 'online-orders/new', name: 'online-order-new', component: () => import('./pages/NewOrderPage.vue'), meta },
    { path: 'online-orders/couriers', name: 'online-order-couriers', component: () => import('./pages/CouriersPage.vue'), meta },
    { path: 'online-orders/:id', name: 'online-order', component: () => import('./pages/OrderPage.vue'), meta },
];
