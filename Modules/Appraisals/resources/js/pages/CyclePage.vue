<script setup>
import { computed, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { ArrowLeft, Search, Users } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import ReviewRows from '../components/ReviewRows.vue';
import { appraisalsApi } from '../api';
import { cycleTone, stagesOf } from '../lib';

/** One cycle: everyone's step (and result once the manager is done), by step, a name to find someone; a row opens the review. */
const route = useRoute();
const org = currentOrganization();
const calls = appraisalsApi(org.id);
const view = useResource(() => calls.cycle(route.params.id));
const cycle = computed(() => view.data.value?.data ?? null);
const stage = ref('all');
const query = ref('');
const people = computed(() => (cycle.value?.people ?? []).filter((item) => (stage.value === 'all' || item.stage === stage.value)
    && (!query.value.trim() || `${item.employee.name} ${item.employee.code}`.toLowerCase().includes(query.value.trim().toLowerCase()))));
const stages = computed(() => [{ value: 'all', label: t('appraisals.common.all') }, ...stagesOf(cycle.value).map((value) => ({ value, label: `${t(`appraisals.stages.${value}`)} (${formatNumber(cycle.value?.stage_counts?.[value] ?? 0)})` }))]);
</script>

<template>
    <div class="space-y-5">
        <ErrorState v-if="view.error.value" :error="view.error.value" @retry="view.reload()" />
        <SkeletonRows v-else-if="!cycle" :rows="4" />
        <template v-else>
            <PageHeader :title="cycle.name" :description="cycle.unit_name">
                <template #actions><AppBadge :tone="cycleTone(cycle.status)">{{ t(`appraisals.cycle_status.${cycle.status}`) }}</AppBadge></template>
            </PageHeader>
            <RouterLink to="/appraisals/cycles" class="inline-flex items-center gap-1.5 text-[13px] text-muted hover:text-fg"><ArrowLeft class="size-4 rtl:rotate-180" aria-hidden="true" />{{ t('appraisals.cycles.back') }}</RouterLink>
            <div class="flex flex-wrap items-center gap-3">
                <div class="max-w-full overflow-x-auto">
                    <AppSegmented v-model="stage" size="sm" :label="t('appraisals.cycles.by_step')" :options="stages" />
                </div>
                <label class="relative ms-auto w-full sm:w-64">
                    <span class="sr-only">{{ t('appraisals.cycles.search') }}</span>
                    <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
                    <input v-model="query" type="search" class="field-input ps-9" :placeholder="t('appraisals.cycles.search')" />
                </label>
            </div>
            <section class="card">
                <EmptyState v-if="!people.length" :icon="Users" :title="t('appraisals.cycles.nobody')" compact />
                <ReviewRows v-else :items="people" />
            </section>
        </template>
    </div>
</template>
