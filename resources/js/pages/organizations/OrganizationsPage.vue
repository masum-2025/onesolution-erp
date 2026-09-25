<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { ChevronsDownUp, ChevronsUpDown, Network, Plus, Search } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import OrgTypeIcon from '@/components/OrgTypeIcon.vue';
import OrgTreeNode from './OrgTreeNode.vue';
import OrganizationFormDialog from './OrganizationFormDialog.vue';
import { useResource } from '@/lib/useResource';
import { allowedParents, ancestorsOf, buildTree, childTypesFor, visibleOrganizations } from '@/lib/organizations';
import { can, currentOrganization } from '@/lib/session';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';

const router = useRouter();
const org = currentOrganization();

const list = useResource(() => visibleOrganizations());
const rules = useResource(() => allowedParents(org.id).catch(() => ({})));

const query = ref('');
const expanded = reactive(new Set());
const creating = ref(false);

const tree = computed(() => buildTree(list.data.value ?? []));

// Open the first two levels and the path to where the user works.
watch(
    () => list.data.value,
    (items) => {
        if (!items || expanded.size) return;
        const minDepth = Math.min(...items.map((item) => item.depth));
        items.filter((item) => item.depth <= minDepth + 1).forEach((item) => expanded.add(item.id));
        ancestorsOf(items, org.id).forEach((item) => expanded.add(item.id));
    },
    { immediate: true },
);

function toggle(id) {
    expanded.has(id) ? expanded.delete(id) : expanded.add(id);
}

const allOpen = computed(() => (list.data.value ?? []).every((item) => expanded.has(item.id)));

function toggleAll() {
    if (allOpen.value) expanded.clear();
    else (list.data.value ?? []).forEach((item) => expanded.add(item.id));
}

const matches = computed(() => {
    const needle = query.value.trim().toLocaleLowerCase();
    if (!needle) return [];
    const items = list.data.value ?? [];
    return items
        .filter((item) => item.display_name.toLocaleLowerCase().includes(needle))
        .map((item) => ({ ...item, path: ancestorsOf(items, item.id).map((node) => node.display_name) }));
});

const childTypes = (parentType) => childTypesFor(parentType, rules.data.value ?? {});
const parentAllowed = (candidate) => candidate.status === 'active' && childTypes(candidate.type).length > 0;

function onCreated(created) {
    list.reload();
    router.push(`/organizations/${created.id}`);
}
</script>

<template>
    <div>
        <PageHeader :title="t('orgs.index.title')" :description="t('orgs.index.text')">
            <template #actions>
                <AppButton v-if="can('organizations.manage')" variant="primary" :icon="Plus" @click="creating = true">{{ t('orgs.index.add') }}</AppButton>
            </template>
        </PageHeader>

        <section class="card overflow-hidden">
            <header class="flex flex-col gap-3 border-b border-line px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                <div class="relative w-full sm:max-w-xs">
                    <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-faint" aria-hidden="true" />
                    <input v-model="query" type="search" class="field-input h-9 min-h-9 ps-9" :placeholder="t('orgs.index.search')" :aria-label="t('orgs.index.search')" />
                </div>
                <div class="flex items-center gap-3">
                    <span v-if="list.data.value" class="tabular text-[12.5px] text-muted">{{ t('orgs.index.count', { count: list.data.value.length, formatted: formatNumber(list.data.value.length) }) }}</span>
                    <AppButton v-if="!query" variant="ghost" size="sm" :icon="allOpen ? ChevronsDownUp : ChevronsUpDown" @click="toggleAll">
                        {{ allOpen ? t('orgs.tree.collapse_all') : t('orgs.tree.expand_all') }}
                    </AppButton>
                </div>
            </header>

            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="6" avatar />
            <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!tree.length" :icon="Network" :title="t('orgs.index.empty_title')" :text="t('orgs.index.empty_text')" />

            <template v-else-if="query">
                <ul v-if="matches.length" class="divide-y divide-line">
                    <li v-for="item in matches" :key="item.id">
                        <RouterLink :to="`/organizations/${item.id}`" class="flex items-center gap-3 px-4 py-3 transition hover:bg-subtle sm:px-5">
                            <OrgTypeIcon :type="item.type" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[13.5px] font-medium text-fg">{{ item.display_name }}</span>
                                <span class="block truncate text-[12.5px] text-muted">{{ [...item.path, t(`core.org_types.${item.type}`)].join(' › ') }}</span>
                            </span>
                        </RouterLink>
                    </li>
                </ul>
                <p v-else class="px-5 py-12 text-center text-[13.5px] text-muted">{{ t('orgs.index.no_match') }}</p>
            </template>

            <ul v-else role="tree" :aria-label="t('orgs.index.title')" class="p-2 sm:p-3">
                <OrgTreeNode v-for="node in tree" :key="node.id" :node="node" :expanded="expanded" :current-id="org.id" @toggle="toggle" />
            </ul>
        </section>

        <OrganizationFormDialog
            :open="creating"
            mode="create"
            :parent-id="org.id"
            :parent-allowed="parentAllowed"
            :child-types="childTypes"
            @close="creating = false"
            @saved="onCreated"
        />
    </div>
</template>
