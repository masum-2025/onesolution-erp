/**
 * Facebook selling screens (children of the app shell). Each page is its
 * own chunk; texts load from the "social_commerce" namespace.
 */
const meta = { context: 'organization', ns: ['social_commerce'], module: 'social_commerce' };

export default [
    { path: 'social/posts', name: 'social-posts', component: () => import('./pages/PostsPage.vue'), meta },
    { path: 'social/posts/new', name: 'social-post-new', component: () => import('./pages/PostPage.vue'), meta },
    { path: 'social/posts/:id', name: 'social-post', component: () => import('./pages/PostPage.vue'), meta },
    { path: 'social/pages', name: 'social-pages', component: () => import('./pages/PagesPage.vue'), meta },
    { path: 'social/connect', name: 'social-connect', component: () => import('./pages/ConnectPage.vue'), meta },
    { path: 'social/inbox', name: 'social-inbox', component: () => import('./pages/InboxPage.vue'), meta },
    { path: 'social/replies', name: 'social-replies', component: () => import('./pages/RepliesPage.vue'), meta },
];
