<script setup>
import { computed, ref } from 'vue';
import { Users } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import ReviewRows from '../components/ReviewRows.vue';
import { appraisalsApi } from '../api';

/** The appraisals of the people one manages: those waiting for oneself first (goals sent to approve, a manager review), then all. */
const org = currentOrganization();
const calls = appraisalsApi(org.id);
const list = useResource(() => calls.team());
const view = ref('waiting');
const all = computed(() => list.data.value?.data ?? []);
const waits = (item) => item.cycle_status === 'open' && (item.stage === 'manager' || (item.stage === 'goals' && item.goals_sent));
const items = computed(() => (view.value === 'waiting' ? all.value.filter(waits) : all.value));
</script>

<template>
    <div class="space-y-5">
        <PageHeader :title="t('appraisals.team.title')" :description="t('appraisals.team.text')">
            <template #actions>
                <AppSegmented v-model="view" size="sm" :label="t('appraisals.team.title')" :options="[{ value: 'waiting', label: t('appraisals.team.waiting') }, { value: 'all', label: t('appraisals.common.all') }]" />
            </template>
        </PageHeader>
        <ErrorState v-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
        <SkeletonRows v-else-if="!list.data.value" :rows="4" />
        <section v-else class="card">
            <EmptyState v-if="!items.length" :icon="Users" :title="t(view === 'waiting' ? 'appraisals.team.empty_waiting' : 'appraisals.team.empty')" compact />
            <ReviewRows v-else :items="items" />
        </section>
    </div>
</template>
