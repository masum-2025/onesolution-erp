import schema from './schema';

/**
 * Pharmacy screens (children of the app shell). Each page is its own chunk;
 * texts load from the "pharmacy" namespace (and "generated" for the generics
 * and manufacturers lists, made by make:erp-module).
 */
const meta = { context: 'organization', ns: ['pharmacy'], module: 'pharmacy' };
const generated = { ...meta, ns: ['pharmacy', 'generated'] };
const listPage = () => import('@/components/generated/GeneratedListPage.vue');

export default [
    { path: 'pharmacy/sell', name: 'pharmacy-sell', component: () => import('./pages/SellPage.vue'), meta },
    { path: 'pharmacy/medicines', name: 'pharmacy-medicines', component: () => import('./pages/MedicinesPage.vue'), meta },
    { path: 'pharmacy/purchases', name: 'pharmacy-purchases', component: () => import('./pages/PurchasesPage.vue'), meta },
    { path: 'pharmacy/stock', name: 'pharmacy-stock', component: () => import('./pages/StockPage.vue'), meta },
    { path: 'pharmacy/returns', name: 'pharmacy-returns', component: () => import('./pages/ReturnsPage.vue'), meta },
    { path: 'pharmacy/controlled', name: 'pharmacy-controlled', component: () => import('./pages/ControlledPage.vue'), meta },
    { path: 'pharmacy/reports', name: 'pharmacy-reports', component: () => import('./pages/ReportsPage.vue'), meta },
    ...schema.entities.map((entity) => ({
        path: `${schema.slug}/${entity.path}`,
        name: `${schema.key}-${entity.name}`,
        component: listPage,
        props: { schema, entity: entity.name },
        meta: generated,
    })),
];
