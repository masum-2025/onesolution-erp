<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowRightLeft, ChevronRight, MoreHorizontal, PenLine, Plus, SearchX } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppMenu from '@/components/AppMenu.vue';
import AppTabs from '@/components/AppTabs.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import OrgTypeIcon from '@/components/OrgTypeIcon.vue';
import SourceBadge from '@/components/SourceBadge.vue';
import MembersPanel from './MembersPanel.vue';
import PlanUsageCard from './PlanUsageCard.vue';
import MoveDialog from './MoveDialog.vue';
import OrganizationFormDialog from './OrganizationFormDialog.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { allowedParents, ancestorsOf, childTypesFor, subtreeIds, visibleOrganizations } from '@/lib/organizations';
import { can, currentOrganization } from '@/lib/session';
import { loadSectors } from '@/lib/packaging';
import { formatDate } from '@/lib/format';
import { settingSource, settingValue } from '@/lib/display';
import { direction, t } from '@/lib/i18n';
import { textLocales } from '@/lib/texts';

const route = useRoute();
const router = useRouter();
const context = currentOrganization();
const id = computed(() => route.params.id);

const organization = useResource(() => api(`/api/organizations/${id.value}`).then((response) => response.data));
const settings = useResource(() => api(`/api/organizations/${id.value}/settings`).then((response) => response.data));
const list = useResource(() => visibleOrganizations());
const rules = useResource(() => allowedParents(context.id).catch(() => ({})));
const sectors = useResource(() => loadSectors().catch(() => []));
const sectorName = (key) => (sectors.data.value ?? []).find((sector) => sector.key === key)?.name ?? key;

const org = computed(() => organization.data.value);
const ancestors = computed(() => (list.data.value && org.value ? ancestorsOf(list.data.value, org.value.id) : []));
const children = computed(() => (list.data.value ?? []).filter((item) => item.parent_id === id.value));
const manage = computed(() => can('organizations.manage'));

const tabs = computed(() => [
    { key: 'overview', label: t('orgs.show.tabs.overview') },
    { key: 'settings', label: t('orgs.show.tabs.settings') },
    ...(can('members.manage') ? [{ key: 'members', label: t('orgs.show.tabs.members') }] : []),
]);

const tab = computed({
    get: () => (tabs.value.some((item) => item.key === route.query.tab) ? route.query.tab : 'overview'),
    set: (value) => router.replace({ query: { ...route.query, tab: value === 'overview' ? undefined : value } }),
});

const editing = ref(false);
const creating = ref(false);
const moving = ref(false);

const childTypes = (parentType) => childTypesFor(parentType, rules.data.value ?? {});
const canAddChild = computed(() => manage.value && org.value && childTypes(org.value.type).length > 0);
const canMove = computed(() => can('organizations.move') && org.value && org.value.type !== 'group' && org.value.id !== context.id);

const moveAllowed = (candidate) => {
    if (!org.value || !list.data.value) return false;
    const blocked = subtreeIds(list.data.value, org.value.id);
    return !blocked.has(candidate.id) && candidate.id !== org.value.parent_id && (rules.data.value?.[org.value.type] ?? []).includes(candidate.type);
};

const menuItems = computed(() => [
    canMove.value && { label: t('orgs.show.move'), icon: ArrowRightLeft, onSelect: () => (moving.value = true) },
].filter(Boolean));

function reloadAll() {
    organization.reload();
    settings.reload();
    list.reload();
}

watch(id, reloadAll);

const SETTING_KEYS = ['country_code', 'currency_code', 'default_locale', 'timezone', 'region'];
const STATUS_TONES = { active: 'ok', suspended: 'warn', archived: 'neutral' };
</script>

<template>
    <div>
        <div v-if="organization.loading.value && !org" class="space-y-4" role="status" :aria-label="t('core.states.loading')">
            <div class="skeleton h-4 w-48" />
            <div class="flex items-center gap-4">
                <div class="skeleton size-12 rounded-2xl" />
                <div class="space-y-2"><div class="skeleton h-6 w-64" /><div class="skeleton h-4 w-40" /></div>
            </div>
            <div class="skeleton mt-8 h-64 w-full rounded-2xl" />
        </div>

        <EmptyState
            v-else-if="organization.error.value?.status === 404"
            :icon="SearchX"
            :title="t('orgs.show.not_found_title')"
            :text="t('orgs.show.not_found_text')"
        >
            <AppButton to="/organizations">{{ t('orgs.show.back') }}</AppButton>
        </EmptyState>
        <ErrorState v-else-if="organization.error.value" :error="organization.error.value" @retry="reloadAll" />

        <template v-else-if="org">
            <nav class="mb-4 flex flex-wrap items-center gap-1 text-[13px]" :aria-label="t('core.breadcrumb')">
                <RouterLink to="/organizations" class="text-muted hover:text-fg">{{ t('orgs.index.title') }}</RouterLink>
                <template v-for="crumb in ancestors" :key="crumb.id">
                    <ChevronRight class="size-3.5 text-faint rtl:rotate-180" aria-hidden="true" />
                    <RouterLink :to="`/organizations/${crumb.id}`" class="text-muted hover:text-fg">{{ crumb.display_name }}</RouterLink>
                </template>
            </nav>

            <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 items-center gap-4">
                    <OrgTypeIcon :type="org.type" size="lg" class="!size-12 !rounded-2xl" />
                    <div class="min-w-0">
                        <h1 class="truncate text-[22px] leading-tight font-semibold tracking-[-0.02em] text-fg sm:text-[24px]">{{ org.display_name }}</h1>
                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                            <AppBadge tone="neutral">{{ t(`core.org_types.${org.type}`) }}</AppBadge>
                            <AppBadge :tone="STATUS_TONES[org.status]" dot>{{ t(`orgs.status.${org.status}`) }}</AppBadge>
                            <AppBadge v-if="org.id === context.id" tone="brand">{{ t('orgs.tree.you_are_here') }}</AppBadge>
                        </div>
                    </div>
                </div>
                <div v-if="manage" class="flex items-center gap-2">
                    <AppButton :icon="PenLine" @click="editing = true">{{ t('core.actions.edit') }}</AppButton>
                    <AppButton v-if="canAddChild" variant="primary" :icon="Plus" @click="creating = true">{{ t('orgs.show.add_child') }}</AppButton>
                    <AppMenu v-if="menuItems.length" :items="menuItems" :label="t('core.actions.more')">
                        <template #trigger="{ toggle, attrs }">
                            <AppButton size="icon" :icon="MoreHorizontal" v-bind="attrs" @click="toggle(false)" />
                        </template>
                    </AppMenu>
                </div>
            </div>

            <AppTabs v-model="tab" :tabs="tabs" :label="org.display_name" />

            <div class="mt-6">
                <div v-if="tab === 'overview'" class="grid gap-6 lg:grid-cols-5">
                    <section class="card lg:col-span-3">
                        <h2 class="border-b border-line px-5 py-4 text-[14.5px] font-semibold text-fg">{{ t('orgs.show.details') }}</h2>
                        <dl class="divide-y divide-line text-[13.5px]">
                            <div v-for="locale in textLocales()" :key="locale" class="flex gap-4 px-5 py-3">
                                <dt class="w-36 shrink-0 text-muted">{{ t('orgs.form.name') }} ({{ t(`core.languages.${locale}`) }})</dt>
                                <dd class="min-w-0 font-medium text-fg" :lang="locale" :dir="direction(locale)">{{ org.name?.[locale] || '—' }}</dd>
                            </div>
                            <div class="flex gap-4 px-5 py-3"><dt class="w-36 shrink-0 text-muted">{{ t('orgs.form.type') }}</dt><dd class="text-fg">{{ t(`core.org_types.${org.type}`) }}</dd></div>
                            <div v-if="org.sector_key" class="flex gap-4 px-5 py-3"><dt class="w-36 shrink-0 text-muted">{{ t('orgs.form.sector') }}</dt><dd class="text-fg">{{ sectorName(org.sector_key) }}</dd></div>
                            <div class="flex gap-4 px-5 py-3"><dt class="w-36 shrink-0 text-muted">{{ t('orgs.show.created') }}</dt><dd class="text-fg">{{ formatDate(org.created_at) }}</dd></div>
                        </dl>
                    </section>

                    <div class="space-y-6 lg:col-span-2">
                    <PlanUsageCard :key="org.id" :organization="org" />
                    <section class="card">
                        <header class="flex items-center justify-between border-b border-line px-5 py-4">
                            <h2 class="text-[14.5px] font-semibold text-fg">{{ t('orgs.show.children') }}</h2>
                            <AppButton v-if="canAddChild" variant="ghost" size="sm" :icon="Plus" @click="creating = true">{{ t('orgs.show.add_child') }}</AppButton>
                        </header>
                        <ul v-if="children.length" class="divide-y divide-line">
                            <li v-for="child in children" :key="child.id">
                                <RouterLink :to="`/organizations/${child.id}`" class="flex items-center gap-3 px-5 py-3 transition hover:bg-subtle">
                                    <OrgTypeIcon :type="child.type" size="sm" />
                                    <span class="min-w-0 flex-1 truncate text-[13.5px] font-medium text-fg">{{ child.display_name }}</span>
                                    <span class="text-[12.5px] text-muted">{{ t(`core.org_types.${child.type}`) }}</span>
                                </RouterLink>
                            </li>
                        </ul>
                        <p v-else class="px-5 py-8 text-center text-[13px] text-muted">{{ t('orgs.show.no_children') }}</p>
                    </section>
                    </div>
                </div>

                <section v-else-if="tab === 'settings'" class="card">
                    <header class="flex flex-col gap-3 border-b border-line px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-[14.5px] font-semibold text-fg">{{ t('orgs.settings.title') }}</h2>
                            <p class="mt-0.5 text-[12.5px] text-muted">{{ t('orgs.settings.text') }}</p>
                        </div>
                        <AppButton v-if="manage" size="sm" :icon="PenLine" @click="editing = true">{{ t('core.actions.edit') }}</AppButton>
                    </header>
                    <ErrorState v-if="settings.error.value" compact :error="settings.error.value" @retry="settings.reload()" />
                    <dl v-else class="divide-y divide-line">
                        <div v-for="key in SETTING_KEYS" :key="key" class="flex flex-col gap-1.5 px-5 py-3.5 sm:flex-row sm:items-center sm:gap-4">
                            <dt class="w-44 shrink-0 text-[13px] text-muted">{{ t(`orgs.settings.fields.${key}`) }}</dt>
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

                <MembersPanel v-else-if="tab === 'members'" :organization="org" />
            </div>

            <OrganizationFormDialog :open="editing" mode="edit" :organization="org" @close="editing = false" @saved="reloadAll" />
            <OrganizationFormDialog
                :open="creating"
                mode="create"
                :parent-id="org.id"
                :parent-allowed="(candidate) => candidate.status === 'active' && childTypes(candidate.type).length > 0"
                :child-types="childTypes"
                @close="creating = false"
                @saved="(created) => router.push(`/organizations/${created.id}`)"
            />
            <MoveDialog :open="moving" :organization="org" :allow="moveAllowed" @close="moving = false" @moved="reloadAll" />
        </template>
    </div>
</template>
