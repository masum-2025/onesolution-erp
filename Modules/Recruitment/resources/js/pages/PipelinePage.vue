<script setup>
import { computed, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { ArrowLeft, CalendarClock, Search, Star, UserPlus, Users } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDateTime, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { i18n, t } from '@/lib/i18n';
import AddCandidateDialog from '../components/AddCandidateDialog.vue';
import ApplicationDrawer from '../components/ApplicationDrawer.vue';
import { recruitmentApi } from '../api';
import { applicationTone, byStage, nameIn, openingTone } from '../lib';

/**
 * One opening's board: its applications in columns by the company's
 * stages (a card dragged to another column moves it; on a phone one stage
 * at a time), those hired, rejected or withdrawn in their own lists, a
 * name or email to find someone, adding someone by hand. A card opens the
 * application (where it can also be moved, for keyboards and phones).
 */
const route = useRoute();
const org = currentOrganization();
const calls = recruitmentApi(org.id);

const opening = useResource(() => calls.opening(route.params.id));
const job = computed(() => opening.data.value?.data ?? null);
const stages = computed(() => job.value?.stages ?? []);
const canManage = computed(() => !!job.value?.can?.manage);

const view = ref('active');
const list = useResource(() => calls.applications({ opening_id: route.params.id, status: view.value }));
watch(view, () => list.reload());
const query = ref('');
const items = computed(() => {
    const q = query.value.trim().toLowerCase();
    return (list.data.value?.data ?? []).filter((item) => !q || `${item.name} ${item.email} ${item.phone ?? ''}`.toLowerCase().includes(q));
});
const columns = computed(() => byStage(items.value, stages.value));
const phoneStage = ref(null);
watch(stages, (value) => {
    if (!phoneStage.value && value.length) phoneStage.value = value[0].key;
}, { immediate: true });

const viewing = ref(null);
const adding = ref(false);
const options = useResource(() => calls.options());
const employmentTypes = computed(() => options.data.value?.data?.employment_types ?? []);

function changed() {
    list.reload();
    opening.reload();
}

// Dragging a card to another column moves the application there.
const dragging = ref(null);
const over = ref(null);
async function drop(stage) {
    const item = dragging.value;
    dragging.value = null;
    over.value = null;
    if (!item || item.stage === stage || !canManage.value) return;
    const before = item.stage;
    item.stage = stage;
    try {
        await calls.updateApplication(item.id, { base_version: item.version, stage });
        toast.success(t('recruitment.drawer.moved', { stage: nameIn(stages.value.find((entry) => entry.key === stage)?.name, i18n.locale) }));
    } catch (error) {
        item.stage = before;
        toast.error(error.message);
    } finally {
        changed();
    }
}

const views = computed(() => ['active', 'hired', 'rejected', 'withdrawn'].map((value) => ({ value, label: t(`recruitment.pipeline.view_${value}`) })));
</script>

<template>
    <div class="space-y-5">
        <ErrorState v-if="opening.error.value" :error="opening.error.value" @retry="opening.reload()" />
        <SkeletonRows v-else-if="!job" :rows="3" />
        <template v-else>
            <PageHeader :title="job.title" :description="`${job.unit_name} · ${t('recruitment.openings.places', { hired: formatNumber(job.hired_count), needed: formatNumber(job.vacancies) })}`">
                <template #actions>
                    <AppBadge :tone="openingTone(job.status)">{{ t(`recruitment.opening_status.${job.status}`) }}</AppBadge>
                    <AppButton v-if="canManage && !['filled'].includes(job.status)" variant="primary" :icon="UserPlus" @click="adding = true">{{ t('recruitment.pipeline.add') }}</AppButton>
                </template>
            </PageHeader>
            <RouterLink to="/recruitment/openings" class="inline-flex items-center gap-1.5 text-[13px] text-muted hover:text-fg"><ArrowLeft class="size-4 rtl:rotate-180" aria-hidden="true" />{{ t('recruitment.pipeline.back') }}</RouterLink>

            <div class="flex flex-wrap items-center gap-3">
                <div class="max-w-full overflow-x-auto">
                    <AppSegmented v-model="view" size="sm" :label="t('recruitment.pipeline.view')" :options="views" />
                </div>
                <label class="relative ms-auto w-full sm:w-72">
                    <span class="sr-only">{{ t('recruitment.pipeline.search') }}</span>
                    <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
                    <input v-model="query" type="search" class="field-input ps-9" :placeholder="t('recruitment.pipeline.search')" />
                </label>
            </div>

            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <template v-else-if="view === 'active'">
                <!-- Phones: one stage at a time. -->
                <div class="md:hidden space-y-3">
                    <div class="max-w-full overflow-x-auto">
                        <AppSegmented v-model="phoneStage" size="sm" :label="t('recruitment.drawer.stage')"
                            :options="stages.map((stage) => ({ value: stage.key, label: `${nameIn(stage.name, i18n.locale)} (${formatNumber(columns[stage.key]?.length ?? 0)})` }))" />
                    </div>
                    <ul class="space-y-2">
                        <li v-for="item in columns[phoneStage] ?? []" :key="item.id">
                            <button type="button" class="card w-full p-3 text-start" @click="viewing = item.id">
                                <span class="block text-[14px] font-medium">{{ item.name }}</span>
                                <span class="block text-[12.5px] text-muted">{{ item.email }}</span>
                            </button>
                        </li>
                        <li v-if="!(columns[phoneStage] ?? []).length"><EmptyState :icon="Users" :title="t('recruitment.pipeline.column_empty')" compact /></li>
                    </ul>
                </div>
                <!-- Wider screens: the board. -->
                <div class="hidden gap-3 overflow-x-auto pb-2 md:grid" :style="{ gridTemplateColumns: `repeat(${stages.length}, minmax(220px, 1fr))` }">
                    <section
                        v-for="stage in stages"
                        :key="stage.key"
                        class="flex min-h-[220px] flex-col rounded-2xl border p-2 transition"
                        :class="over === stage.key ? 'border-brand bg-brand/5' : 'border-line bg-subtle/60'"
                        :aria-label="nameIn(stage.name, i18n.locale)"
                        @dragover.prevent="over = stage.key"
                        @dragleave="over = over === stage.key ? null : over"
                        @drop.prevent="drop(stage.key)"
                    >
                        <h2 class="flex items-center justify-between px-2 py-1.5 text-[13px] font-semibold">
                            {{ nameIn(stage.name, i18n.locale) }}
                            <span class="tabular rounded-full bg-surface px-2 text-[12px] text-muted">{{ formatNumber(columns[stage.key]?.length ?? 0) }}</span>
                        </h2>
                        <ul class="flex-1 space-y-2">
                            <li v-for="item in columns[stage.key]" :key="item.id">
                                <button
                                    type="button"
                                    class="card w-full cursor-pointer p-3 text-start hover:border-brand"
                                    :draggable="canManage"
                                    @dragstart="dragging = item"
                                    @dragend="dragging = null; over = null"
                                    @click="viewing = item.id"
                                >
                                    <span class="block truncate text-[14px] font-medium">{{ item.name }}</span>
                                    <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[12px] text-muted">
                                        <span v-if="item.rating" class="inline-flex items-center gap-0.5"><Star class="size-3.5 fill-warn text-warn" aria-hidden="true" />{{ formatNumber(item.rating) }}</span>
                                        <span v-if="item.average_score !== null">{{ t('recruitment.pipeline.score', { score: formatNumber(item.average_score) }) }}</span>
                                        <span v-if="item.next_interview_at" class="inline-flex items-center gap-1"><CalendarClock class="size-3.5" aria-hidden="true" />{{ formatDateTime(item.next_interview_at) }}</span>
                                        <span>{{ t(`recruitment.sources.${item.source}`) }}</span>
                                    </span>
                                </button>
                            </li>
                        </ul>
                    </section>
                </div>
                <p v-if="canManage" class="hidden text-[12.5px] text-muted md:block">{{ t('recruitment.pipeline.drag_hint') }}</p>
            </template>
            <section v-else class="card">
                <EmptyState v-if="!items.length" :icon="Users" :title="t(`recruitment.pipeline.empty_${view}`)" compact />
                <ul v-else class="divide-y divide-line">
                    <li v-for="item in items" :key="item.id">
                        <button type="button" class="flex w-full flex-wrap items-center justify-between gap-2 px-5 py-3 text-start hover:bg-subtle" @click="viewing = item.id">
                            <span class="min-w-0">
                                <span class="block text-[14px] font-medium">{{ item.name }}</span>
                                <span class="block text-[12.5px] text-muted">{{ item.email }} · {{ formatDateTime(item.decided_at) }}</span>
                            </span>
                            <AppBadge :tone="applicationTone(item.status)">{{ t(`recruitment.status.${item.status}`) }}</AppBadge>
                        </button>
                    </li>
                </ul>
            </section>
        </template>

        <AddCandidateDialog :open="adding" :opening-id="job?.id ?? null" :calls="calls" @close="adding = false" @added="(data) => { adding = false; changed(); viewing = data.id; }" />
        <ApplicationDrawer :open="viewing !== null" :application-id="viewing" :opening="job" :employment-types="employmentTypes" :calls="calls" @close="viewing = null" @changed="changed" />
    </div>
</template>
