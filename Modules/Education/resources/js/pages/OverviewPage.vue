<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowRight, BookOpen, CalendarPlus, ClipboardCheck, FileText, LayoutGrid, ListPlus, Settings2, TriangleAlert, UserPlus, Users, UserX } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { educationApi } from '../api';
import { useEducationSetup } from '../setup';
import { byLevel } from '../lib';
import CapacityMeter from '../components/CapacityMeter.vue';
import SetupWizard from '../components/SetupWizard.vue';
import StudentDialog from '../components/StudentDialog.vue';
import StructureDialog from '../components/StructureDialog.vue';

/**
 * The education home: a session (the open one by default), numbers that
 * matter today, how full each class is, and what needs doing. Until the
 * institution is set up, people who manage it see the set-up steps; others
 * are told who has to do it.
 */
const org = currentOrganization();
const education = educationApi(org.id);
const setup = useEducationSetup();
const route = useRoute();
const router = useRouter();

const sessionId = ref(route.query.session ?? '');
const overview = useResource(() => education.overview(sessionId.value ? { session_id: sessionId.value } : {}));
const data = computed(() => overview.data.value?.data ?? null);
const stats = computed(() => data.value?.stats ?? null);

watch(sessionId, (value) => {
    router.replace({ query: value ? { session: value } : {} });
    overview.reload();
});
// The session shown, once the server picked one.
watch(data, (value) => {
    if (value?.session_id && !sessionId.value) sessionId.value = value.session_id;
});

const sessions = computed(() => setup.data.value?.sessions ?? []);
const hasSessions = computed(() => sessions.value.length > 0);
const needsSetup = computed(() => setup.data.value && (!setup.ready.value || !hasSessions.value));
const levels = computed(() => byLevel(data.value?.sections ?? [], setup.rank));

const tiles = computed(() => {
    if (!stats.value) return [];
    const list = [
        { key: 'students', icon: Users, value: stats.value.students, to: { name: 'education-students' } },
        { key: 'unplaced', icon: UserX, value: stats.value.unplaced, to: { name: 'education-students', query: { unplaced: 1 } }, warn: stats.value.unplaced > 0 },
        { key: 'sections', icon: LayoutGrid, value: stats.value.sections, to: { name: 'education-sections', query: sessionId.value ? { session: sessionId.value } : {} } },
        { key: 'nearly_full', icon: TriangleAlert, value: stats.value.nearly_full, warn: stats.value.nearly_full > 0 },
    ];
    if (stats.value.applications !== null) list.push({ key: 'applications', icon: FileText, value: stats.value.applications });
    if (stats.value.promotions_waiting !== null) list.push({ key: 'promotions', icon: ClipboardCheck, value: stats.value.promotions_waiting, warn: stats.value.promotions_waiting > 0 });
    return list;
});

const adding = ref(false);
const editing = ref(null); // { kind, start }

function setupDone() {
    sessionId.value = '';
    overview.reload();
}

function saved(student) {
    adding.value = false;
    router.push({ name: 'education-student', params: { id: student.id } });
}
</script>

<template>
    <div>
        <PageHeader :title="t('education.overview.title')" :description="setup.data.value?.institution?.name ?? t('education.overview.text')">
            <template v-if="!needsSetup" #actions>
                <label class="flex items-center gap-2">
                    <span class="sr-only">{{ t('education.overview.session') }}</span>
                    <select v-model="sessionId" class="field-input h-9 w-auto min-w-44">
                        <option v-for="session in sessions" :key="session.id" :value="session.id">
                            {{ textIn(session.name) }}{{ session.status === 'open' ? '' : ` · ${t(`education.session_statuses.${session.status}`)}` }}
                        </option>
                    </select>
                </label>
                <AppButton v-if="setup.can('admit')" variant="primary" :icon="UserPlus" @click="adding = true">{{ t('education.students.new') }}</AppButton>
            </template>
        </PageHeader>

        <SkeletonRows v-if="setup.loading.value && !setup.data.value" :rows="6" />
        <ErrorState v-else-if="setup.error.value" :error="setup.error.value" @retry="setup.reload()" />

        <template v-else-if="needsSetup">
            <SetupWizard v-if="setup.can('manage')" @done="setupDone" />
            <section v-else class="card">
                <EmptyState :icon="Settings2" :title="t('education.wizard.waiting_title')" :text="t('education.wizard.waiting_text')" />
            </section>
        </template>

        <template v-else>
            <!-- Numbers that matter today; each one opens its list where there is one. -->
            <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                <template v-if="stats">
                    <component
                        :is="tile.to ? 'RouterLink' : 'div'"
                        v-for="tile in tiles"
                        :key="tile.key"
                        :to="tile.to"
                        class="card group flex flex-col gap-2 p-4 transition"
                        :class="tile.to ? 'hover:border-brand/40 hover:shadow-sm' : ''"
                    >
                        <span class="flex items-center justify-between gap-2">
                            <span class="text-[12.5px] text-muted">{{ t(`education.overview.stats.${tile.key}`) }}</span>
                            <span class="grid size-7 place-items-center rounded-lg" :class="tile.warn ? 'bg-warn-soft text-warn' : 'bg-brand-soft text-brand-text'" aria-hidden="true">
                                <component :is="tile.icon" class="size-4" />
                            </span>
                        </span>
                        <span class="tabular text-[24px] font-semibold leading-none text-fg">{{ formatNumber(tile.value) }}</span>
                    </component>
                </template>
                <div v-else v-for="index in 4" :key="index" class="card h-[86px] animate-pulse bg-subtle/60" />
            </div>

            <!-- Students without a section. -->
            <div v-if="stats?.unplaced" class="mb-6 flex flex-col gap-3 rounded-2xl border border-warn/30 bg-warn-soft px-4 py-3.5 sm:flex-row sm:items-center">
                <TriangleAlert class="size-5 shrink-0 text-warn" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <p class="text-[13.5px] font-semibold text-fg">{{ t('education.overview.unplaced_title', { count: stats.unplaced }) }}</p>
                    <p class="text-[12.5px] text-fg-2">{{ t('education.overview.unplaced_text') }}</p>
                </div>
                <AppButton size="sm" :to="{ name: 'education-students', query: { unplaced: 1 } }" :icon-end="ArrowRight">{{ t('education.overview.unplaced_action') }}</AppButton>
            </div>

            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_280px]">
                <section class="card">
                    <header class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-3.5">
                        <div>
                            <h2 class="text-[14.5px] font-semibold text-fg">{{ t('education.overview.levels_title') }}</h2>
                            <p class="text-[12.5px] text-muted">{{ t('education.overview.levels_text') }}</p>
                        </div>
                    </header>
                    <SkeletonRows v-if="overview.loading.value && !data" :rows="5" />
                    <ErrorState v-else-if="overview.error.value" compact :error="overview.error.value" @retry="overview.reload()" />
                    <EmptyState v-else-if="!levels.length" :icon="LayoutGrid" :title="t('education.overview.no_sections_title')" :text="t('education.overview.no_sections_text')" compact>
                        <AppButton v-if="setup.can('manage')" variant="primary" :icon="LayoutGrid" @click="editing = { kind: 'sections', start: { session_id: sessionId } }">
                            {{ t('education.overview.no_sections_action') }}
                        </AppButton>
                    </EmptyState>
                    <ul v-else class="divide-y divide-line">
                        <li v-for="group in levels" :key="group.level_id" class="grid grid-cols-1 gap-3 px-5 py-3.5 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)] sm:items-center">
                            <div class="min-w-0">
                                <p class="truncate text-[14px] font-medium text-fg">{{ setup.levelText(group.level_id) }}</p>
                                <p class="mt-0.5 flex flex-wrap gap-1.5">
                                    <RouterLink
                                        v-for="section in group.sections"
                                        :key="section.id"
                                        :to="{ name: 'education-section', params: { id: section.id } }"
                                        class="rounded-md bg-subtle px-1.5 py-0.5 text-[12px] text-fg-2 hover:bg-brand-soft hover:text-brand-text"
                                    >
                                        {{ section.name }} <span class="tabular text-muted">{{ formatNumber(section.taken) }}</span>
                                    </RouterLink>
                                </p>
                            </div>
                            <div>
                                <CapacityMeter :taken="group.taken" :capacity="group.capacity" />
                                <p class="mt-0.5 text-[11.5px] text-faint">{{ t('education.overview.sections_count', { count: group.sections.length }) }}</p>
                            </div>
                        </li>
                    </ul>
                </section>

                <nav class="card h-max p-2" :aria-label="t('education.title')">
                    <RouterLink v-for="link in [
                        { key: 'students', icon: Users, to: { name: 'education-students' } },
                        { key: 'sections', icon: LayoutGrid, to: { name: 'education-sections' } },
                        ...(setup.can('manage') ? [
                            { key: 'structure', icon: BookOpen, to: { name: 'education-structure' } },
                            { key: 'fields', icon: ListPlus, to: { name: 'education-fields' } },
                        ] : []),
                    ]" :key="link.key" :to="link.to" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-[13.5px] text-fg-2 transition hover:bg-subtle hover:text-fg">
                        <component :is="link.icon" class="size-4 text-muted" aria-hidden="true" />
                        <span class="flex-1">{{ t(`education.overview.quick.${link.key}`) }}</span>
                        <ArrowRight class="size-3.5 text-faint rtl:rotate-180" aria-hidden="true" />
                    </RouterLink>
                    <button v-if="setup.can('manage')" type="button" class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-start text-[13.5px] text-fg-2 transition hover:bg-subtle hover:text-fg" @click="editing = { kind: 'years', start: {} }">
                        <CalendarPlus class="size-4 text-muted" aria-hidden="true" />
                        <span class="flex-1">{{ t('education.structure.add.years') }}</span>
                    </button>
                </nav>
            </div>
        </template>

        <StudentDialog :open="adding" :start="{ session_id: sessionId }" @close="adding = false" @saved="saved" />
        <StructureDialog :open="editing !== null" :kind="editing?.kind" :start="editing?.start ?? {}" @close="editing = null" @saved="editing = null; overview.reload()" />
    </div>
</template>
