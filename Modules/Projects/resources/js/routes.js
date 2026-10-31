/**
 * Projects screens (children of the app shell). Each page is its own chunk,
 * fetched when opened; texts load from this module's "projects" namespace.
 */
const meta = { context: 'organization', ns: ['projects'], module: 'projects' };

export default [
    { path: 'projects', name: 'projects', component: () => import('./pages/ProjectsPage.vue'), meta },
    // Literal paths before :id.
    { path: 'projects/my-tasks', name: 'projects-mine', component: () => import('./pages/MyTasksPage.vue'), meta },
    { path: 'projects/templates', name: 'projects-templates', component: () => import('./pages/TemplatesPage.vue'), meta },
    { path: 'projects/:id', name: 'project', component: () => import('./pages/ProjectPage.vue'), meta },
    // A client's projects in the company's portal (B2B2C): portal members may open this.
    { path: 'portal/projects/:id', name: 'projects-portal', component: () => import('./pages/PortalProjectsPage.vue'), meta: { ...meta, portal: true } },
];
