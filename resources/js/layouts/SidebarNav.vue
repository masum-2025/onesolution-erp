<script setup>
import { computed, reactive, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import {
    ArrowRightLeft,
    Blocks,
    Building,
    ChevronDown,
    ChevronsLeft,
    Code,
    Download,
    FileText,
    Globe,
    Handshake,
    Home,
    KeyRound,
    Languages,
    LifeBuoy,
    Mail,
    MessageSquareText,
    Network,
    Package,
    Palette,
    Receipt,
    ScrollText,
    ShieldCheck,
    SlidersHorizontal,
} from 'lucide-vue-next';
import ContextSwitcher from './ContextSwitcher.vue';
import BrandMark from '@/components/BrandMark.vue';
import { brand, taglineFor } from '@/lib/brand';
import { can, session } from '@/lib/session';
import { formatNumber } from '@/lib/format';
import { moduleIcon } from '@/lib/icons';
import { menuLink } from '@/lib/menu';
import { i18n, t } from '@/lib/i18n';

/**
 * The sidebar of the shell. Platform screens first (workspace, then
 * administration), then each module section from /api/menu with its
 * sub-pages. Collapsed: icons only, names in tooltips, counts stay numbers
 * (never a colored dot alone).
 */
const props = defineProps({
    menu: { type: Array, default: () => [] },
    pendingApprovals: { type: Number, default: 0 },
    collapsed: Boolean,
    // Desktop only: shows the collapse button.
    collapsible: Boolean,
});

const emit = defineEmits(['navigate', 'toggle']);

const route = useRoute();
const router = useRouter();
const linkTo = (item) => menuLink(item, router);
const itemKey = (item) => `${item.module}-${item.key}`;
const isPartner = computed(() => session.me?.context?.type === 'partner');

const exportItem = () => ({ to: '/export', label: t('core.nav.export'), icon: Download });

/** Platform screens, in sections: [{ key, label, items }] */
const platformSections = computed(() => {
    const context = session.me?.context;

    if (isPartner.value) {
        return [
            {
                key: 'partner',
                label: t('core.nav.partner_console'),
                items: [
                    { to: '/partner/organizations', label: t('core.nav.clients'), icon: Handshake },
                    { to: '/partner/plans', label: t('core.nav.partner_plans'), icon: Package },
                    ...(['owner', 'billing'].includes(context?.role) ? [{ to: '/partner/billing', label: t('core.nav.partner_billing'), icon: Receipt }] : []),
                    { to: '/partner/support', label: t('core.nav.partner_support'), icon: LifeBuoy },
                    { to: '/partner/transfers', label: t('core.nav.partner_transfers'), icon: ArrowRightLeft },
                    { to: '/partner/legal', label: t('core.nav.partner_legal'), icon: FileText },
                ],
            },
            {
                key: 'partner_settings',
                label: t('core.nav.partner_settings'),
                items: [
                    ...(context?.role === 'owner' ? [{ to: '/partner/api-keys', label: t('core.nav.partner_api'), icon: Code }] : []),
                    { to: '/partner/brand', label: t('core.nav.partner_brand'), icon: Palette },
                    { to: '/partner/messaging', label: t('core.nav.partner_messaging'), icon: Mail },
                    { to: '/partner/templates', label: t('core.nav.partner_templates'), icon: MessageSquareText },
                    { to: '/partner/languages', label: t('core.nav.partner_languages'), icon: Languages },
                    { to: '/partner/domains', label: t('core.nav.partner_domains'), icon: Globe },
                    { to: '/partner/modules', label: t('core.nav.partner_modules'), icon: Blocks },
                    { to: '/partner/rules', label: t('core.nav.partner_rules'), icon: SlidersHorizontal },
                ],
            },
        ];
    }

    // A suspended provider after the grace period: exporting is all that is left.
    if (context?.mode === 'export_only') return [{ key: 'workspace', label: t('core.nav.workspace'), items: [exportItem()] }];

    // A client's own person in its portal (Phase 5C-4): their records, nothing else.
    if (context?.membership_type === 'portal') return [{ key: 'workspace', label: t('core.nav.workspace'), items: [{ to: '/portal', label: t('core.nav.portal_home'), icon: Home }] }];

    return [
        {
            key: 'workspace',
            label: t('core.nav.workspace'),
            items: [
                { to: '/', label: t('core.nav.overview'), icon: Home, exact: true },
                { to: '/organizations', label: t('core.nav.organizations'), icon: Network },
                ...(can('roles.manage') || can('members.manage') ? [{ to: '/roles', label: t('core.nav.roles'), icon: KeyRound }] : []),
                { to: '/modules', label: t('core.nav.modules'), icon: Blocks },
                { to: '/rules', label: t('core.nav.rules'), icon: SlidersHorizontal },
                ...(can('rules.approve') ? [{ to: '/approvals', label: t('core.nav.approvals'), icon: ShieldCheck, count: props.pendingApprovals }] : []),
            ],
        },
        {
            key: 'administration',
            label: t('core.nav.administration'),
            items: [
                ...(can('support.approve') ? [{ to: '/support-access', label: t('core.nav.support_access'), icon: LifeBuoy }] : []),
                ...(can('billing.view') ? [{ to: '/billing', label: t('core.nav.billing'), icon: Receipt }] : []),
                ...(can('audit.view') ? [{ to: '/audit-log', label: t('core.nav.audit_log'), icon: ScrollText }] : []),
                ...(can('branding.manage') && context?.account_owner ? [{ to: '/branding', label: t('core.nav.brand'), icon: Palette }] : []),
                { to: '/provider', label: t('core.nav.provider'), icon: Building, count: context?.legal_pending || 0 },
                ...(can('data.export') ? [exportItem()] : []),
            ],
        },
    ].filter((section) => section.items.length);
});

/** Module entries grouped by their section, in menu order. */
const moduleSections = computed(() => {
    if (isPartner.value) return [];
    const sections = new Map();
    for (const item of props.menu) {
        const key = item.section ?? 'apps';
        if (!sections.has(key)) sections.set(key, { key, label: item.section_label ?? t('core.nav.apps'), items: [] });
        sections.get(key).items.push(item);
    }
    return [...sections.values()];
});

function isActive(item) {
    return item.exact ? route.path === item.to : route.path === item.to || route.path.startsWith(`${item.to}/`);
}

/**
 * The module entry and sub-page the current address belongs to: the longest
 * matching link across every entry, so /hrm/positions selects "Positions",
 * /hrm/employees/1 "Employees", and /accounting/sales the Sales entry rather
 * than Accounting (whose own link, /accounting, is shorter).
 */
const activeLink = computed(() => {
    let best = null;
    const consider = (item, child) => {
        const link = linkTo(child ? { ...child, module: item.module } : item);
        const longer = !best || link.length > best.link.length || (link.length === best.link.length && child && !best.child);
        if ((route.path === link || route.path.startsWith(`${link}/`)) && longer) {
            best = { item: itemKey(item), child: child?.key ?? null, link };
        }
    };
    for (const item of props.menu) {
        consider(item, null);
        for (const child of item.children ?? []) consider(item, child);
    }
    return best;
});

function moduleActive(item) {
    return activeLink.value?.item === itemKey(item);
}

function activeChild(item) {
    return activeLink.value?.item === itemKey(item) ? activeLink.value.child : null;
}

// Open sections with sub-pages: the one holding the current page opens by itself.
const open = reactive({});
watch(
    () => [route.path, props.menu],
    () => {
        for (const item of props.menu) {
            if (item.children?.length && moduleActive(item)) open[itemKey(item)] = true;
        }
    },
    { immediate: true },
);

function toggleOpen(item) {
    open[itemKey(item)] = !open[itemKey(item)];
}

// Under the brand name: the console's name for partner staff, else the brand's tagline.
const subtitle = computed(() => (isPartner.value ? t('core.context.partner_console') : taglineFor(i18n.locale)));

/**
 * Names of icon-only entries (collapsed sidebar), drawn on top of the page so
 * the scrolling list cannot clip them. Shown on hover and keyboard focus.
 */
const tip = reactive({ show: false, text: '', top: 0, left: 0, rtl: false });

function showTip(event, text) {
    if (!props.collapsed) return;
    const rect = event.currentTarget.getBoundingClientRect();
    const rtl = document.documentElement.dir === 'rtl';
    Object.assign(tip, { show: true, text, rtl, top: rect.top + rect.height / 2, left: rtl ? rect.left - 12 : rect.right + 12 });
}

function hideTip() {
    tip.show = false;
}

watch(() => [props.collapsed, route.path], hideTip);

const linkClass = (active) => [
    'group relative flex items-center rounded-[10px] text-[13.5px] font-medium transition-colors duration-150',
    props.collapsed ? 'size-11 justify-center' : 'h-10 gap-3 px-3',
    active ? 'bg-side-active text-side-fg shadow-[inset_0_0_0_1px_var(--side-ring)]' : 'text-side-fg-2 hover:bg-side-hover hover:text-side-fg',
];
</script>

<template>
    <div class="shell-side relative flex h-full flex-col">
        <!-- Brand -->
        <div class="flex h-16 shrink-0 items-center gap-3" :class="[collapsed ? 'justify-center px-2' : 'px-5', !brand.side_band_color && 'border-b border-side-line']">
            <BrandMark size="md" />
            <div v-if="!collapsed" class="min-w-0">
                <p class="truncate text-[15px] leading-tight font-semibold tracking-[-0.01em] text-side-fg">{{ brand.name }}</p>
                <p v-if="subtitle" class="mt-0.5 truncate text-[12px] leading-tight text-side-muted">{{ subtitle }}</p>
            </div>
        </div>
        <!-- The sidebar's brand band along the bottom of the brand row, level with the header's. -->
        <div v-if="brand.side_band_color" class="h-1 shrink-0 shadow-[inset_0_-1px_0_var(--side-line)]" :style="{ background: brand.side_band_color }" aria-hidden="true" />

        <!-- Collapse button on the sidebar's edge (desktop) -->
        <button
            v-if="collapsible"
            type="button"
            class="absolute -end-3.5 top-[3.25rem] z-10 grid size-7 place-items-center rounded-full border border-line bg-surface text-muted shadow-pop transition hover:scale-105 hover:text-fg"
            :aria-label="collapsed ? t('core.nav.expand') : t('core.nav.collapse')"
            :aria-expanded="!collapsed"
            @click="emit('toggle')"
        >
            <ChevronsLeft class="size-4 transition-transform duration-200 rtl:rotate-180" :class="collapsed && 'rotate-180 rtl:rotate-0'" aria-hidden="true" />
        </button>

        <div class="shrink-0" :class="collapsed ? 'px-2 pt-3' : 'px-3 pt-3'">
            <ContextSwitcher :compact="collapsed" />
        </div>

        <nav class="min-h-0 flex-1 overflow-x-visible overflow-y-auto pb-4" :class="collapsed ? 'px-2' : 'px-3'" :aria-label="t('core.nav.label')">
            <!-- Platform screens -->
            <section v-for="section in platformSections" :key="section.key" class="pt-4" :aria-label="section.label">
                <p v-if="!collapsed" class="px-3 pb-2 text-[11px] font-semibold tracking-[0.1em] text-side-muted uppercase" aria-hidden="true">{{ section.label }}</p>
                <div v-else class="mx-auto mb-2 h-px w-6 bg-side-line" aria-hidden="true" />
                <ul class="space-y-1">
                    <li v-for="item in section.items" :key="item.to" :class="collapsed && 'flex justify-center'">
                        <RouterLink :to="item.to" :class="linkClass(isActive(item))" :aria-current="isActive(item) ? 'page' : undefined" :aria-label="collapsed ? item.label : undefined" @mouseenter="showTip($event, item.label)" @focus="showTip($event, item.label)" @mouseleave="hideTip" @blur="hideTip" @click="emit('navigate')">
                            <span v-if="isActive(item)" class="absolute inset-y-2 -start-3 w-1 rounded-e-full bg-side-accent" :class="collapsed && '-start-2'" aria-hidden="true" />
                            <component :is="item.icon" class="size-[18px] shrink-0 transition-colors" :class="isActive(item) ? 'text-side-accent' : 'text-side-muted group-hover:text-side-fg-2'" aria-hidden="true" />
                            <span v-if="!collapsed" class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                            <span
                                v-if="item.count"
                                class="tabular rounded-full bg-brand px-1.5 text-[11px] leading-[18px] font-semibold text-brand-fg"
                                :class="collapsed && 'absolute -end-1 -top-1 min-w-[18px] text-center ring-2 ring-[var(--side-bg)]'"
                            >
                                {{ formatNumber(item.count) }}
                            </span>
                        </RouterLink>
                    </li>
                </ul>
            </section>

            <!-- Modules, by section -->
            <section v-for="section in moduleSections" :key="section.key" class="pt-5" :aria-label="section.label">
                <p v-if="!collapsed" class="px-3 pb-2 text-[11px] font-semibold tracking-[0.1em] text-side-muted uppercase" aria-hidden="true">{{ section.label }}</p>
                <div v-else class="mx-auto mb-2 h-px w-6 bg-side-line" aria-hidden="true" />
                <ul class="space-y-1">
                    <li v-for="item in section.items" :key="itemKey(item)" :class="collapsed && 'flex justify-center'">
                        <!-- With sub-pages (expanded sidebar): a disclosure button -->
                        <template v-if="item.children?.length && !collapsed">
                            <button
                                type="button"
                                :class="linkClass(moduleActive(item) && !open[itemKey(item)])"
                                class="w-full text-start"
                                :aria-expanded="!!open[itemKey(item)]"
                                :aria-controls="`side-${itemKey(item)}`"
                                @click="toggleOpen(item)"
                            >
                                <component
                                    :is="moduleIcon(item.icon)"
                                    class="size-[18px] shrink-0"
                                    :class="moduleActive(item) ? 'text-side-accent' : 'text-side-muted group-hover:text-side-fg-2'"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                                <ChevronDown class="size-4 shrink-0 text-side-muted transition-transform duration-200" :class="open[itemKey(item)] && 'rotate-180'" aria-hidden="true" />
                            </button>
                            <Transition
                                enter-active-class="transition-[opacity,translate] duration-200 ease-[var(--ease-soft)]"
                                enter-from-class="opacity-0 -translate-y-1"
                            >
                                <ul v-if="open[itemKey(item)]" :id="`side-${itemKey(item)}`" class="relative ms-[1.4rem] mt-1 space-y-0.5 border-s border-side-line ps-3">
                                    <li v-for="child in item.children" :key="child.key">
                                        <RouterLink
                                            :to="linkTo({ ...child, module: item.module })"
                                            class="relative flex h-9 items-center rounded-lg px-3 text-[13px] transition-colors"
                                            :class="activeChild(item) === child.key ? 'bg-side-active font-semibold text-side-fg' : 'text-side-fg-2 hover:bg-side-hover hover:text-side-fg'"
                                            :aria-current="activeChild(item) === child.key ? 'page' : undefined"
                                            @click="emit('navigate')"
                                        >
                                            <span
                                                v-if="activeChild(item) === child.key"
                                                class="absolute inset-y-2 -start-[13.5px] w-0.5 rounded-full bg-side-accent"
                                                aria-hidden="true"
                                            />
                                            <span class="truncate">{{ child.label }}</span>
                                        </RouterLink>
                                    </li>
                                </ul>
                            </Transition>
                        </template>

                        <RouterLink
                            v-else
                            :to="linkTo(item)"
                            :class="linkClass(moduleActive(item))"
                            :aria-current="moduleActive(item) ? 'page' : undefined"
                            :aria-label="collapsed ? item.label : undefined"
                            @mouseenter="showTip($event, item.label)"
                            @focus="showTip($event, item.label)"
                            @mouseleave="hideTip"
                            @blur="hideTip"
                            @click="emit('navigate')"
                        >
                            <span v-if="moduleActive(item)" class="absolute inset-y-2 -start-3 w-1 rounded-e-full bg-side-accent" :class="collapsed && '-start-2'" aria-hidden="true" />
                            <component
                                :is="moduleIcon(item.icon)"
                                class="size-[18px] shrink-0"
                                :class="moduleActive(item) ? 'text-side-accent' : 'text-side-muted group-hover:text-side-fg-2'"
                                aria-hidden="true"
                            />
                            <span v-if="!collapsed" class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                        </RouterLink>
                    </li>
                </ul>
            </section>
        </nav>

        <Teleport to="body">
            <div v-if="tip.show" class="side-tip" :class="tip.rtl && 'side-tip-rtl'" :style="{ top: `${tip.top}px`, left: `${tip.left}px` }" aria-hidden="true">{{ tip.text }}</div>
        </Teleport>
    </div>
</template>
