<script setup>
import { computed, onMounted, ref } from 'vue';
import { ArrowRight, Blocks, CheckCheck, Network, PenLine, ShieldCheck, SlidersHorizontal, UserPlus } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import SourceBadge from '@/components/SourceBadge.vue';
import ErrorState from '@/components/ErrorState.vue';
import DashboardWidget from '@/components/dashboard/DashboardWidget.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { visibleOrganizations } from '@/lib/organizations';
import { attentionItems, attentionLoaded, refreshAttention } from '@/lib/attention';
import { loadMenu } from '@/lib/menu';
import { can, currentOrganization, session } from '@/lib/session';
import { formatNumber, localeTag } from '@/lib/format';
import { settingSource, settingValue } from '@/lib/display';
import { t } from '@/lib/i18n';

const org = currentOrganization();

const units = useResource(() => visibleOrganizations());
const modules = useResource(() => api(`/api/organizations/${org.id}/modules`).then((response) => response.data));
const rules = useResource(() => api(`/api/organizations/${org.id}/rules`).then((response) => response.data));
const settings = useResource(() => api(`/api/organizations/${org.id}/settings`).then((response) => response.data));
// Headline widgets of every module that is on here (UI-1b), each loading on its own.
const overview = useResource(() => api('/api/dashboard').then((response) => response.data));
const icons = ref({});
onMounted(async () => {
    refreshAttention();
    try {
        icons.value = Object.fromEntries((await loadMenu()).data.map((item) => [item.module, item.icon]));
    } catch {
        icons.value = {};
    }
});

const today = computed(() => new Intl.DateTimeFormat(localeTag(), { dateStyle: 'full', timeZone: org.settings?.timezone ?? undefined }).format(new Date()));

const approvals = useResource(() => (can('rules.approve') ? api(`/api/organizations/${org.id}/rule-approvals`).then((response) => response.data) : Promise.resolve([])));

const greeting = computed(() => {
    const hour = new Date().getHours();
    const part = hour < 12 ? 'morning' : hour < 18 ? 'afternoon' : 'evening';
    const first = (session.me?.user?.name ?? '').split(/\s+/)[0];
    return t(`home.greeting.${part}`, { name: first });
});

const ruleList = computed(() => (rules.data.value ?? []).flatMap((group) => group.categories.flatMap((category) => category.rules)));

const stats = computed(() => [
    {
        key: 'units',
        icon: Network,
        label: t('home.stats.units'),
        value: units.data.value ? formatNumber(units.data.value.length) : null,
        hint: t('home.stats.units_hint'),
        to: '/organizations',
        loading: units.loading.value && !units.data.value,
    },
    {
        key: 'modules',
        icon: Blocks,
        label: t('home.stats.modules'),
        value: modules.data.value
            ? t('home.stats.of', { value: formatNumber(modules.data.value.filter((item) => item.enabled).length), total: formatNumber(modules.data.value.length) })
            : null,
        hint: t('home.stats.modules_hint'),
        to: '/modules',
        loading: modules.loading.value && !modules.data.value,
    },
    {
        key: 'rules',
        icon: SlidersHorizontal,
        label: t('home.stats.rules_here'),
        value: rules.data.value ? formatNumber(ruleList.value.filter((rule) => rule.own.value || rule.own.constraint).length) : null,
        hint: t('home.stats.rules_hint', { total: formatNumber(ruleList.value.length) }),
        to: '/rules',
        loading: rules.loading.value && !rules.data.value,
    },
    {
        key: 'approvals',
        icon: ShieldCheck,
        label: t('home.stats.approvals'),
        value: can('rules.approve') ? (approvals.data.value ? formatNumber(approvals.data.value.length) : null) : '—',
        hint: can('rules.approve') ? t('home.stats.approvals_hint') : t('home.stats.approvals_no_access'),
        to: can('rules.approve') ? '/approvals' : null,
        loading: approvals.loading.value && !approvals.data.value,
    },
]);

const SETTING_KEYS = ['country_code', 'currency_code', 'default_locale', 'timezone'];

const actions = computed(() =>
    [
        { to: '/modules', icon: Blocks, title: t('home.actions.modules'), text: t('home.actions.modules_text') },
        { to: '/rules', icon: SlidersHorizontal, title: t('home.actions.rules'), text: t('home.actions.rules_text') },
        can('organizations.manage') && { to: '/organizations', icon: Network, title: t('home.actions.structure'), text: t('home.actions.structure_text') },
        can('organizations.manage') && {
            to: `/organizations/${org.id}?tab=members`,
            icon: UserPlus,
            title: t('home.actions.members'),
            text: t('home.actions.members_text'),
        },
    ].filter(Boolean),
);
</script>

<template>
    <div>
        <PageHeader :title="greeting" :description="t('home.subtitle', { org: org.name, type: t(`core.org_types.${org.organization_type}`) })">
            <template #eyebrow>
                <p class="mb-1 text-[12.5px] font-medium text-muted">{{ today }}</p>
            </template>
        </PageHeader>

        <!-- Headline numbers of the modules that are on -->
        <section v-if="overview.data.value?.length || (overview.loading.value && !overview.data.value)" class="mb-6" :aria-label="t('dashboard.overview')">
            <div v-if="!overview.data.value" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" role="status" :aria-label="t('core.states.loading')">
                <div v-for="n in 3" :key="n" class="skeleton h-44 rounded-2xl" />
            </div>
            <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <DashboardWidget v-for="widget in overview.data.value" :key="`${widget.module}-${widget.key}`" :widget="widget" :icon="icons[widget.module]" show-module />
            </div>
        </section>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
            <!-- Needs your action -->
            <section class="card lg:col-span-3 lg:self-start" aria-labelledby="needs-action">
                <header class="border-b border-line px-5 py-4">
                    <h2 id="needs-action" class="text-[14.5px] font-semibold text-fg">{{ t('dashboard.needs_action') }}</h2>
                    <p class="mt-0.5 text-[12.5px] text-muted">{{ t('dashboard.needs_action_text') }}</p>
                </header>
                <div v-if="!attentionLoaded" class="space-y-3 p-5" role="status" :aria-label="t('core.states.loading')">
                    <div v-for="n in 2" :key="n" class="skeleton h-10" />
                </div>
                <p v-else-if="!attentionItems.length" class="flex items-center gap-3 px-5 py-5 text-[13.5px] text-muted">
                    <span class="grid size-9 place-items-center rounded-xl bg-ok-soft text-ok" aria-hidden="true"><CheckCheck class="size-[18px]" /></span>
                    {{ t('dashboard.all_clear') }}
                </p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="item in attentionItems" :key="item.key">
                        <RouterLink :to="item.path" class="group flex items-center gap-3.5 px-5 py-3.5 transition-colors hover:bg-subtle">
                            <span
                                class="grid size-9 shrink-0 place-items-center rounded-xl"
                                :class="{ warn: 'bg-warn-soft text-warn', bad: 'bg-bad-soft text-bad' }[item.tone] ?? 'bg-brand-soft text-brand-text'"
                                aria-hidden="true"
                            >
                                <component :is="item.icon" class="size-[18px]" />
                            </span>
                            <span class="min-w-0 flex-1 truncate text-[13.5px] font-medium text-fg">{{ item.label }}</span>
                            <span class="tabular rounded-full bg-subtle px-2.5 py-0.5 text-[13px] font-semibold text-fg">{{ formatNumber(item.count) }}</span>
                            <ArrowRight class="size-4 shrink-0 text-faint transition group-hover:translate-x-0.5 group-hover:text-fg-2 rtl:rotate-180" aria-hidden="true" />
                        </RouterLink>
                    </li>
                </ul>
            </section>

            <section class="lg:col-span-2">
                <h2 class="mb-3 text-[14.5px] font-semibold text-fg">{{ t('home.actions.title') }}</h2>
                <ul class="space-y-2">
                    <li v-for="action in actions" :key="action.to">
                        <RouterLink :to="action.to" class="card group flex items-center gap-3.5 p-3.5 transition hover:border-line-strong hover:shadow-pop">
                            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-text">
                                <component :is="action.icon" class="size-[18px]" aria-hidden="true" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-[13.5px] font-medium text-fg">{{ action.title }}</span>
                                <span class="mt-0.5 block truncate text-[12.5px] text-muted">{{ action.text }}</span>
                            </span>
                            <ArrowRight class="size-4 shrink-0 text-faint transition group-hover:translate-x-0.5 group-hover:text-fg-2 rtl:rotate-180" aria-hidden="true" />
                        </RouterLink>
                    </li>
                </ul>
                <p v-if="!can('organizations.manage')" class="mt-4 flex items-start gap-2 text-[12.5px] leading-relaxed text-muted">
                    <PenLine class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                    {{ t('home.read_only_note') }}
                </p>
            </section>
        </div>

        <!-- The workspace itself -->
        <h2 class="mt-8 mb-3 text-[14.5px] font-semibold text-fg">{{ t('dashboard.workspace') }}</h2>
        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            <component
                :is="stat.to ? 'RouterLink' : 'div'"
                v-for="stat in stats"
                :key="stat.key"
                :to="stat.to ?? undefined"
                class="card group relative p-4 transition-[box-shadow,border-color]"
                :class="stat.to ? 'hover:border-line-strong hover:shadow-pop' : ''"
            >
                <div class="flex items-center justify-between">
                    <p class="text-[13px] font-medium text-muted">{{ stat.label }}</p>
                    <span class="grid size-8 place-items-center rounded-lg bg-subtle text-muted transition-colors group-hover:bg-brand-soft group-hover:text-brand-text">
                        <component :is="stat.icon" class="size-4" aria-hidden="true" />
                    </span>
                </div>
                <div class="mt-3 h-8">
                    <div v-if="stat.loading" class="skeleton mt-1 h-6 w-16" />
                    <p v-else class="tabular text-[26px] leading-none font-semibold tracking-[-0.02em] text-fg">{{ stat.value ?? '—' }}</p>
                </div>
                <p class="mt-2 truncate text-[12.5px] text-muted">{{ stat.hint }}</p>
            </component>
        </div>

        <section class="card mt-6" aria-labelledby="home-settings">
            <header class="flex items-center justify-between border-b border-line px-5 py-4">
                <div>
                    <h2 id="home-settings" class="text-[14.5px] font-semibold text-fg">{{ t('home.settings.title') }}</h2>
                    <p class="mt-0.5 text-[12.5px] text-muted">{{ t('home.settings.text') }}</p>
                </div>
                <RouterLink :to="`/organizations/${org.id}?tab=settings`" class="text-[13px] font-medium text-brand-text hover:underline">
                    {{ t('home.settings.view_all') }}
                </RouterLink>
            </header>
            <ErrorState v-if="settings.error.value" compact :error="settings.error.value" @retry="settings.reload()" />
            <dl v-else class="divide-y divide-line">
                <div v-for="key in SETTING_KEYS" :key="key" class="flex flex-col gap-1.5 px-5 py-3.5 sm:flex-row sm:items-center sm:gap-4">
                    <dt class="w-40 shrink-0 text-[13px] text-muted">{{ t(`orgs.settings.fields.${key}`) }}</dt>
                    <dd class="flex min-w-0 flex-1 flex-wrap items-center justify-between gap-2">
                        <span v-if="!settings.data.value" class="skeleton h-4 w-32" />
                        <template v-else>
                            <span class="truncate text-[13.5px] font-medium text-fg">{{ settingValue(key, settings.data.value[key]?.value) }}</span>
                            <SourceBadge :kind="settingSource(settings.data.value[key])" :name="settings.data.value[key]?.source_organization_name" />
                        </template>
                    </dd>
                </div>
            </dl>
        </section>
    </div>
</template>
