import { ref } from 'vue';
import { loadMenu } from '@/lib/menu';
import { loadNamespaces } from '@/lib/i18n';

/**
 * Tabs other modules add to a student's page: each module that has
 * something about a student (fees, results, courses) ships
 * resources/js/studentTabs.js exporting a list of
 *
 *   { key, module, ns, order, label: () => text, component,
 *     summary?: async (studentId) => ({ value, hint, tone }) }
 *
 * Education knows none of them by name. A tab shows only when its module
 * is in the person's menu (on here, and they hold one of its permissions);
 * the module's own endpoints check every access again.
 */
const definitions = import.meta.glob('../../../*/resources/js/studentTabs.js', { eager: true, import: 'default' });
const all = Object.values(definitions).flat();

/** The tabs whose module is in the menu, in their order. */
export function visibleTabs(tabs, menuItems) {
    const modules = new Set(menuItems.map((item) => item.module));
    return tabs.filter((tab) => modules.has(tab.module)).sort((a, b) => a.order - b.order);
}

/** The module tabs for the current person and organization, with their texts loaded; empty until known. */
export function useModuleTabs(tabs = all) {
    const list = ref([]);
    (async () => {
        try {
            const found = visibleTabs(tabs, (await loadMenu()).data);
            await loadNamespaces(found.map((tab) => tab.ns));
            list.value = found;
        } catch {
            // Without the menu, the student's page shows Education's own tabs only.
            list.value = [];
        }
    })();
    return list;
}
