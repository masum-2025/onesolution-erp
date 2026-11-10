/**
 * Education pages anyone may open, signed in or not, without the app shell.
 * Always part of the first load (FE-1): the signed-in screens in routes.js are
 * loaded only where the module is on.
 */
export default [
    // Checking a document's QR code.
    { path: '/verify/:organization/:code', name: 'education-verify', component: () => import('./pages/VerifyPage.vue'), meta: { public: true, outside: true, ns: ['education'], module: 'education' } },
];
