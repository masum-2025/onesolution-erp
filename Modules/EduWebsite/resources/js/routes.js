/**
 * Website screens (children of the app shell). Each page is its own chunk,
 * fetched when opened; texts load from the "edu_website" namespace.
 */
const meta = { context: 'organization', ns: ['edu_website'], module: 'edu_website' };

export default [
    { path: 'website', name: 'website', component: () => import('./pages/OverviewPage.vue'), meta },
    { path: 'website/settings', name: 'website-settings', component: () => import('./pages/SettingsPage.vue'), meta },
    { path: 'website/pages', name: 'website-pages', component: () => import('./pages/PagesPage.vue'), meta },
    { path: 'website/pages/:id', name: 'website-page', component: () => import('./pages/PageEditorPage.vue'), meta },
    { path: 'website/notices', name: 'website-notices', component: () => import('./pages/NoticesPage.vue'), meta },
    { path: 'website/media', name: 'website-media', component: () => import('./pages/MediaPage.vue'), meta },
];
