/**
 * AI assistant screens (children of the app shell); texts from the
 * "ai_assistant" namespace.
 */
const meta = { context: 'organization', ns: ['ai_assistant'], module: 'ai_assistant' };

export default [{ path: 'ai_assistant', name: 'ai-usage', component: () => import('./pages/UsagePage.vue'), meta }];
