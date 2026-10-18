/**
 * ZKTeco device screens (children of the app shell). Each page is its own
 * chunk, fetched when opened; texts load from the "zkteco" namespace.
 */
const meta = { context: 'organization', ns: ['zkteco'], module: 'zkteco' };

export default [
    { path: 'zkteco', name: 'zkteco-devices', component: () => import('./pages/DevicesPage.vue'), meta },
    // One device: what it is, and its users matched to employees.
    { path: 'zkteco/devices/:id', name: 'zkteco-device', component: () => import('./pages/DevicePage.vue'), meta },
];
