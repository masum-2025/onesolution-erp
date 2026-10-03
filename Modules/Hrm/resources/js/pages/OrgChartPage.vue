<script setup>
import { computed } from 'vue';
import { ArrowLeft, Network } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import OrgChartNode from '../components/OrgChartNode.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { hrmApi } from '../api';

/**
 * Who reports to whom, opened one level at a time. The top shows everyone
 * with no manager here; each person opens to their direct reports.
 */
const org = currentOrganization();
const hrm = hrmApi(org.id);

const top = useResource(() => hrm.orgChart());
const people = computed(() => top.data.value?.data ?? []);
const total = computed(() => top.data.value?.meta.total ?? 0);
</script>

<template>
    <div>
        <PageHeader :title="t('hrm.org_chart.title')" :description="t('hrm.org_chart.text')">
            <template #actions>
                <AppButton to="/hrm" :icon="ArrowLeft">{{ t('hrm.org_chart.back') }}</AppButton>
            </template>
        </PageHeader>

        <SkeletonRows v-if="top.loading.value && !top.data.value" :rows="4" />
        <ErrorState v-else-if="top.error.value" :error="top.error.value" @retry="top.reload()" />
        <div v-else-if="!people.length" class="card">
            <EmptyState :icon="Network" :title="t('hrm.org_chart.empty_title')" :text="t('hrm.org_chart.empty_text')">
                <AppButton to="/hrm">{{ t('hrm.org_chart.back') }}</AppButton>
            </EmptyState>
        </div>
        <template v-else>
            <ul class="max-w-3xl space-y-3" :aria-label="t('hrm.org_chart.title')">
                <OrgChartNode v-for="person in people" :key="person.id" :person="person" :hrm="hrm" />
            </ul>
            <p v-if="total > people.length" class="mt-3 text-[12.5px] text-muted">{{ t('hrm.org_chart.more', { count: total - people.length, shown: formatNumber(total - people.length) }) }}</p>
        </template>
    </div>
</template>
