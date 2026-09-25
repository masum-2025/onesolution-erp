<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Search, SearchX, SlidersHorizontal } from 'lucide-vue-next';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import RuleDrawer from './RuleDrawer.vue';
import RuleRow from './RuleRow.vue';
import { useResource } from '@/lib/useResource';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * Rule list grouped by module and category, with search, filters and the
 * detail drawer. Shared by the organization and partner rule pages.
 */
const props = defineProps({
    adapter: { type: Object, required: true },
    scopeName: { type: String, default: '' },
});

const route = useRoute();
const router = useRouter();

const rules = useResource(() => props.adapter.list());
const query = ref('');
const filter = ref('all');
const moduleKey = ref('all');

watch(() => props.adapter, () => rules.reload());

const openKey = computed(() => (typeof route.query.rule === 'string' ? route.query.rule : null));

function open(key) {
    router.replace({ query: { ...route.query, rule: key } });
}

function close() {
    const { rule, ...rest } = route.query;
    router.replace({ query: rest });
}

const matchesFilter = (rule) =>
    filter.value === 'all' ||
    (filter.value === 'here' && (rule.own.value || rule.own.constraint)) ||
    (filter.value === 'locked' && (rule.locked_by || rule.locked_here)) ||
    (filter.value === 'pending' && rule.own.pending_approval.length);

const matchesQuery = (rule) => {
    const needle = query.value.trim().toLocaleLowerCase();
    return !needle || rule.label.toLocaleLowerCase().includes(needle) || rule.description.toLocaleLowerCase().includes(needle) || rule.key.includes(needle);
};

const groups = computed(() =>
    (rules.data.value ?? [])
        .filter((group) => moduleKey.value === 'all' || group.module === moduleKey.value)
        .map((group) => ({
            ...group,
            categories: group.categories
                .map((category) => ({ ...category, rules: category.rules.filter((rule) => matchesFilter(rule) && matchesQuery(rule)) }))
                .filter((category) => category.rules.length),
        }))
        .filter((group) => group.categories.length),
);

const moduleCounts = computed(() =>
    (rules.data.value ?? []).map((group) => ({
        module: group.module,
        name: group.name,
        count: group.categories.reduce((sum, category) => sum + category.rules.length, 0),
        changed: group.categories.reduce((sum, category) => sum + category.rules.filter((rule) => rule.own.value || rule.own.constraint).length, 0),
    })),
);

const total = computed(() => moduleCounts.value.reduce((sum, group) => sum + group.count, 0));

const filters = computed(() => [
    { value: 'all', label: t('rules.filter.all') },
    { value: 'here', label: t('rules.filter.here') },
    { value: 'locked', label: t('rules.filter.locked') },
    { value: 'pending', label: t('rules.filter.pending') },
]);

defineExpose({ reload: () => rules.reload() });
</script>

<template>
    <div class="lg:grid lg:grid-cols-[220px_minmax(0,1fr)] lg:gap-8">
        <!-- Module navigation -->
        <aside class="hidden lg:block">
            <nav class="sticky top-20 space-y-0.5" :aria-label="t('rules.modules_nav')">
                <button
                    type="button"
                    class="flex h-9 w-full items-center gap-2 rounded-lg px-2.5 text-start text-[13.5px] font-medium transition-colors"
                    :class="moduleKey === 'all' ? 'bg-surface text-fg shadow-[0_1px_2px_rgb(0_0_0/0.06),0_0_0_1px_var(--c-line)]' : 'text-fg-2 hover:bg-subtle'"
                    :aria-current="moduleKey === 'all' || undefined"
                    @click="moduleKey = 'all'"
                >
                    <span class="flex-1 truncate">{{ t('rules.all_modules') }}</span>
                    <span class="tabular text-[12px] text-faint">{{ formatNumber(total) }}</span>
                </button>
                <template v-if="rules.data.value">
                    <button
                        v-for="group in moduleCounts"
                        :key="group.module"
                        type="button"
                        class="flex h-9 w-full items-center gap-2 rounded-lg px-2.5 text-start text-[13.5px] transition-colors"
                        :class="moduleKey === group.module ? 'bg-surface font-medium text-fg shadow-[0_1px_2px_rgb(0_0_0/0.06),0_0_0_1px_var(--c-line)]' : 'text-fg-2 hover:bg-subtle'"
                        :aria-current="moduleKey === group.module || undefined"
                        @click="moduleKey = group.module"
                    >
                        <span class="flex-1 truncate">{{ group.name }}</span>
                        <span v-if="group.changed" class="size-1.5 rounded-full bg-brand" :title="t('rules.changed_here', { count: group.changed })" />
                        <span class="tabular text-[12px] text-faint">{{ formatNumber(group.count) }}</span>
                    </button>
                </template>
                <div v-else class="space-y-2 px-2.5 pt-2">
                    <div v-for="n in 6" :key="n" class="skeleton h-4" :style="{ width: `${50 + ((n * 13) % 40)}%` }" />
                </div>
            </nav>
        </aside>

        <div class="min-w-0">
            <div class="mb-5 flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                <div class="relative w-full xl:max-w-xs">
                    <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-faint" aria-hidden="true" />
                    <input v-model="query" type="search" class="field-input h-9 min-h-9 ps-9" :placeholder="t('rules.search')" :aria-label="t('rules.search')" />
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <select v-model="moduleKey" class="field-input h-9 min-h-9 w-auto lg:hidden" :aria-label="t('rules.modules_nav')">
                        <option value="all">{{ t('rules.all_modules') }}</option>
                        <option v-for="group in moduleCounts" :key="group.module" :value="group.module">{{ group.name }}</option>
                    </select>
                    <div class="max-w-full overflow-x-auto">
                        <AppSegmented v-model="filter" :options="filters" :label="t('rules.filter.label')" size="sm" />
                    </div>
                </div>
            </div>

            <div v-if="rules.loading.value && !rules.data.value" class="card overflow-hidden"><SkeletonRows :rows="7" /></div>
            <ErrorState v-else-if="rules.error.value" :error="rules.error.value" @retry="rules.reload()" />
            <EmptyState v-else-if="!total" :icon="SlidersHorizontal" :title="t('rules.empty_title')" :text="t('rules.empty_text')" />
            <EmptyState v-else-if="!groups.length" :icon="SearchX" :title="t('rules.no_match_title')" :text="t('rules.no_match_text')" />

            <div v-else class="space-y-6">
                <section v-for="group in groups" :key="group.module" class="card overflow-hidden">
                    <header class="flex items-center justify-between border-b border-line bg-subtle/40 px-4 py-3 sm:px-5">
                        <h2 class="text-[14px] font-semibold text-fg">{{ group.name }}</h2>
                    </header>
                    <div v-for="category in group.categories" :key="category.category">
                        <h3 class="border-b border-line px-4 pt-3.5 pb-2 text-[11px] font-semibold tracking-wider text-faint uppercase sm:px-5">
                            {{ category.label }}
                        </h3>
                        <ul class="divide-y divide-line border-b border-line last:border-b-0">
                            <li v-for="rule in category.rules" :key="rule.key">
                                <RuleRow :rule="rule" @open="open" />
                            </li>
                        </ul>
                    </div>
                </section>
            </div>
        </div>

        <RuleDrawer :open="!!openKey" :rule-key="openKey" :adapter="adapter" :scope-name="scopeName" @close="close" @changed="rules.reload()" />
    </div>
</template>
