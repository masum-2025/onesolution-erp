<script setup>
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { ChevronRight } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import OrgTypeIcon from '@/components/OrgTypeIcon.vue';
import { t } from '@/lib/i18n';

defineOptions({ name: 'OrgTreeNode' });

const props = defineProps({
    node: { type: Object, required: true },
    expanded: { type: Set, required: true },
    currentId: { type: String, default: null },
});

const emit = defineEmits(['toggle']);

const open = computed(() => props.expanded.has(props.node.id));
const hasChildren = computed(() => props.node.children.length > 0);
</script>

<template>
    <li :aria-expanded="hasChildren ? open : undefined" role="treeitem">
        <div class="group flex h-11 items-center gap-1.5 rounded-lg pe-2 transition-colors hover:bg-subtle">
            <button
                v-if="hasChildren"
                type="button"
                class="grid size-7 shrink-0 place-items-center rounded-md text-faint transition hover:bg-line hover:text-fg"
                :aria-label="open ? t('orgs.tree.collapse', { name: node.display_name }) : t('orgs.tree.expand', { name: node.display_name })"
                @click="emit('toggle', node.id)"
            >
                <ChevronRight class="size-4 transition-transform duration-200 rtl:rotate-180" :class="open ? 'rotate-90 rtl:rotate-90' : ''" aria-hidden="true" />
            </button>
            <span v-else class="w-7 shrink-0" aria-hidden="true" />

            <RouterLink :to="`/organizations/${node.id}`" class="flex min-w-0 flex-1 items-center gap-2.5 rounded-md py-1 outline-offset-0">
                <OrgTypeIcon :type="node.type" size="sm" />
                <span class="truncate text-[13.5px] font-medium text-fg group-hover:text-brand-text">{{ node.display_name }}</span>
                <span class="hidden shrink-0 text-[12.5px] text-muted sm:inline">{{ t(`core.org_types.${node.type}`) }}</span>
                <AppBadge v-if="node.id === currentId" tone="brand" dot class="shrink-0">{{ t('orgs.tree.you_are_here') }}</AppBadge>
                <AppBadge v-if="node.status !== 'active'" :tone="node.status === 'suspended' ? 'warn' : 'neutral'" class="shrink-0">
                    {{ t(`orgs.status.${node.status}`) }}
                </AppBadge>
            </RouterLink>

            <span v-if="hasChildren" class="tabular hidden shrink-0 text-[12px] text-faint sm:inline">{{ node.children.length }}</span>
        </div>

        <ul v-if="hasChildren && open" role="group" class="ms-[13px] border-s border-line ps-2">
            <OrgTreeNode v-for="child in node.children" :key="child.id" :node="child" :expanded="expanded" :current-id="currentId" @toggle="emit('toggle', $event)" />
        </ul>
    </li>
</template>
