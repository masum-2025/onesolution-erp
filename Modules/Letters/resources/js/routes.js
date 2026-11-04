/**
 * Letters screens (children of the app shell). Each page is its own chunk,
 * fetched when opened; texts load from the "letters" namespace.
 */
const meta = { context: 'organization', ns: ['letters'], module: 'letters' };

export default [
    { path: 'letters', name: 'letters', component: () => import('./pages/LettersPage.vue'), meta },
    { path: 'letters/issued/:id', name: 'letters-letter', component: () => import('./pages/LetterPage.vue'), meta: { ...meta, letter: 'staff' } },
    { path: 'letters/templates', name: 'letters-templates', component: () => import('./pages/TemplatesPage.vue'), meta },
    { path: 'letters/templates/:id', name: 'letters-editor', component: () => import('./pages/EditorPage.vue'), meta: { ...meta, focus: true } },
    { path: 'letters/me', name: 'letters-me', component: () => import('./pages/MyLettersPage.vue'), meta: { ...meta, letter: 'mine' } },
    { path: 'letters/me/:id', name: 'letters-my-letter', component: () => import('./pages/LetterPage.vue'), meta: { ...meta, letter: 'mine' } },

    // An employee's own letters in the client's portal (B2B2C).
    { path: 'portal/letters', name: 'letters-portal', component: () => import('./pages/MyLettersPage.vue'), meta: { ...meta, portal: true, letter: 'portal' } },
    { path: 'portal/letters/:id', name: 'letters-portal-letter', component: () => import('./pages/LetterPage.vue'), meta: { ...meta, portal: true, letter: 'portal' } },
];
