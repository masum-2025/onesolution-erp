<script setup>
import { computed, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { CalendarRange, PenLine, Plus } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import CycleEditor from '../components/CycleEditor.vue';
import { appraisalsApi } from '../api';
import { cycleTone, stagesOf } from '../lib';

/**
 * Review cycles of the unit: each with how many people are at each step.
 * Those managing appraisals write a draft, open it (everyone working there
 * gets a review), move its last days and close it.
 */
const org = currentOrganization();
const calls = appraisalsApi(org.id);
const list = useResource(() => calls.cycles());
const options = useResource(() => calls.options());
const items = computed(() => list.data.value?.data ?? []);
const canManage = computed(() => !!list.data.value?.meta?.can_manage);
const editing = ref(null);
const busy = ref(null);
const day = (value) => formatDate(`${value}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });

async function step(cycle, name) {
    const confirmed = await confirmAction({
        title: t(`appraisals.cycles.${name}_title`, { name: cycle.name }),
        message: t(`appraisals.cycles.${name}_text`),
        confirmLabel: t(`appraisals.cycles.${name}`),
        danger: name === 'close',
    });
    if (!confirmed) return;
    busy.value = cycle.id;
    try {
        await calls.stepCycle(cycle.id, name, { base_version: cycle.version });
        toast.success(t(`appraisals.cycles.${name}_done`));
        list.reload();
    } catch (error) {
        if (error.code === 'version_conflict') list.reload();
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}

function saved() {
    editing.value = null;
    list.reload();
}
</script>

<template>
    <div class="space-y-5">
        <PageHeader :title="t('appraisals.cycles.title')" :description="t('appraisals.cycles.text')">
            <template v-if="canManage" #actions>
                <AppButton variant="primary" :icon="Plus" @click="editing = 'new'">{{ t('appraisals.cycles.new') }}</AppButton>
            </template>
        </PageHeader>
        <ErrorState v-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
        <SkeletonRows v-else-if="!list.data.value" :rows="3" />
        <section v-else-if="!items.length" class="card">
            <EmptyState :icon="CalendarRange" :title="t('appraisals.cycles.empty')" :text="t('appraisals.cycles.empty_text')" compact>
                <AppButton v-if="canManage" variant="primary" :icon="Plus" @click="editing = 'new'">{{ t('appraisals.cycles.new') }}</AppButton>
            </EmptyState>
        </section>
        <ul v-else class="grid gap-4 md:grid-cols-2">
            <li v-for="cycle in items" :key="cycle.id" class="card flex flex-col p-4">
                <div class="flex items-start justify-between gap-3">
                    <RouterLink :to="`/appraisals/cycles/${cycle.id}`" class="min-w-0">
                        <h2 class="truncate text-[16px] font-semibold hover:text-brand">{{ cycle.name }}</h2>
                        <p class="text-[12.5px] text-muted">{{ cycle.unit_name }} · {{ day(cycle.period_from) }} – {{ day(cycle.period_to) }}</p>
                    </RouterLink>
                    <AppBadge :tone="cycleTone(cycle.status)">{{ t(`appraisals.cycle_status.${cycle.status}`) }}</AppBadge>
                </div>
                <div v-if="cycle.status !== 'draft'" class="mt-3 flex flex-wrap gap-1.5">
                    <span v-for="stage in stagesOf(cycle)" :key="stage" class="inline-flex items-center gap-1.5 rounded-lg bg-subtle px-2.5 py-1 text-[12.5px]">
                        {{ t(`appraisals.stages.${stage}`) }} <b class="tabular">{{ formatNumber(cycle.stage_counts[stage] ?? 0) }}</b>
                    </span>
                </div>
                <p v-else class="mt-3 text-[12.5px] text-muted">{{ t('appraisals.cycles.draft_note') }}</p>
                <div v-if="canManage && cycle.status !== 'closed'" class="mt-3 flex flex-wrap gap-2 border-t border-line pt-3">
                    <AppButton size="sm" variant="ghost" :icon="PenLine" @click="editing = cycle">{{ t('appraisals.cycles.edit') }}</AppButton>
                    <AppButton v-if="cycle.status === 'draft'" size="sm" variant="primary" :loading="busy === cycle.id" @click="step(cycle, 'open')">{{ t('appraisals.cycles.open') }}</AppButton>
                    <AppButton v-else size="sm" variant="ghost" :loading="busy === cycle.id" @click="step(cycle, 'close')">{{ t('appraisals.cycles.close') }}</AppButton>
                </div>
            </li>
        </ul>
        <CycleEditor :open="editing !== null" :cycle="editing === 'new' ? null : editing" :options="options.data.value?.data ?? {}" :calls="calls" @close="editing = null" @saved="saved" />
    </div>
</template>
