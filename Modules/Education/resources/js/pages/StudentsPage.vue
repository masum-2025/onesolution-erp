<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ChevronLeft, ChevronRight, FileUp, GraduationCap, Search, UserPlus, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { educationApi } from '../api';
import { useEducationSetup } from '../setup';
import { statusTone } from '../lib';
import StudentAvatar from '../components/StudentAvatar.vue';
import StudentDialog from '../components/StudentDialog.vue';

/**
 * Students of this unit and the campuses below: search by name, code or
 * phone; filter by status, program, class, or those not in a section yet.
 * The filters live in the address (shareable, back button). A teacher sees
 * the students of their own sections only (the server decides).
 */
const org = currentOrganization();
const education = educationApi(org.id);
const setup = useEducationSetup();
const route = useRoute();
const router = useRouter();

const search = ref(route.query.q ?? '');
const status = ref(route.query.status ?? '');
const programId = ref(route.query.program ?? '');
const levelId = ref(route.query.level ?? '');
const unplaced = ref(route.query.unplaced === '1');
const page = ref(Number(route.query.page) || 1);
const STATUSES = ['active', 'suspended', 'left', 'graduated'];

const list = useResource(() =>
    education.students({
        q: search.value.trim(),
        status: status.value,
        program_id: programId.value,
        level_id: levelId.value,
        unplaced: unplaced.value ? 1 : '',
        page: page.value,
        per_page: 25,
    }),
);
const students = computed(() => list.data.value?.data ?? []);
const meta = computed(() => list.data.value?.meta ?? null);
const filtering = computed(() => search.value.trim() !== '' || status.value !== '' || programId.value !== '' || levelId.value !== '' || unplaced.value);
const programs = computed(() => setup.data.value?.programs ?? []);
const levels = computed(() => (programId.value ? setup.levelsOf(programId.value) : []));

let timer = null;
watch([search, status, programId, levelId, unplaced], () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        page.value = 1;
        sync();
    }, 300);
});
watch(programId, () => {
    if (!levels.value.some((level) => level.id === levelId.value)) levelId.value = '';
});
onBeforeUnmount(() => clearTimeout(timer));

function sync() {
    const query = {
        ...(search.value.trim() ? { q: search.value.trim() } : {}),
        ...(status.value ? { status: status.value } : {}),
        ...(programId.value ? { program: programId.value } : {}),
        ...(levelId.value ? { level: levelId.value } : {}),
        ...(unplaced.value ? { unplaced: '1' } : {}),
        ...(page.value > 1 ? { page: page.value } : {}),
    };
    router.replace({ query });
    list.reload();
}

function go(to) {
    page.value = to;
    sync();
}

function clear() {
    search.value = '';
    status.value = '';
    programId.value = '';
    levelId.value = '';
    unplaced.value = false;
}

// "New student" from the header menu opens the form here (?new=1).
const adding = ref(route.query.new === '1');
function closeAdding() {
    adding.value = false;
    if (route.query.new) router.replace({ query: { ...route.query, new: undefined } });
}
function saved(student) {
    adding.value = false;
    router.push({ name: 'education-student', params: { id: student.id } });
}

// Section names for the rows on this page (read once per session shown).
const sectionNames = ref({});
watch(students, async (rows) => {
    const sessions = [...new Set(rows.map((row) => row.enrollment?.session_id).filter(Boolean))].filter((id) => !(id in sectionNames.value));
    for (const sessionId of sessions) {
        try {
            const { data } = await education.list('sections', { session_id: sessionId });
            sectionNames.value = { ...sectionNames.value, [sessionId]: Object.fromEntries(data.map((section) => [section.id, section.name])) };
        } catch {
            sectionNames.value = { ...sectionNames.value, [sessionId]: {} };
        }
    }
});
function rowPlace(student) {
    const enrollment = student.enrollment;
    if (!enrollment) return t('education.not_placed');
    const parts = [textIn(setup.level(enrollment.level_id)?.name)];
    parts.push(enrollment.section_id ? sectionNames.value[enrollment.session_id]?.[enrollment.section_id] ?? '' : t('education.not_placed'));
    if (enrollment.roll_no) parts.push(t('education.student.roll', { roll: enrollment.roll_no }));
    return parts.filter(Boolean).join(' · ');
}
</script>

<template>
    <div>
        <PageHeader :title="t('education.students.title')" :description="t('education.students.text')">
            <template #actions>
                <AppButton v-if="setup.can('admit')" :icon="FileUp" :to="{ name: 'education-import' }" :disabled="!setup.ready.value">{{ t('education.students_import') }}</AppButton>
                <AppButton v-if="setup.can('admit')" variant="primary" :icon="UserPlus" :disabled="!setup.ready.value" @click="adding = true">{{ t('education.students.new') }}</AppButton>
            </template>
        </PageHeader>

        <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:flex lg:items-end">
            <AppField v-slot="{ id }" :label="t('education.students.search')" sr-only-label class="sm:col-span-2 lg:flex-1">
                <div class="relative">
                    <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-faint" aria-hidden="true" />
                    <input :id="id" v-model="search" type="search" class="field-input ps-9" :placeholder="t('education.students.search')" autocomplete="off" />
                </div>
            </AppField>
            <AppField v-slot="{ id }" :label="t('education.students.filters.program')" sr-only-label class="lg:w-48">
                <select :id="id" v-model="programId" class="field-input">
                    <option value="">{{ t('education.students.filters.all_programs') }}</option>
                    <option v-for="item in programs" :key="item.id" :value="item.id">{{ textIn(item.name) }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('education.students.filters.level')" sr-only-label class="lg:w-40">
                <select :id="id" v-model="levelId" class="field-input" :disabled="!programId">
                    <option value="">{{ t('education.students.filters.all_levels') }}</option>
                    <option v-for="item in levels" :key="item.id" :value="item.id">{{ textIn(item.name) }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('education.students.filters.status')" sr-only-label class="lg:w-40">
                <select :id="id" v-model="status" class="field-input">
                    <option value="">{{ t('education.students.filters.all_statuses') }}</option>
                    <option v-for="value in STATUSES" :key="value" :value="value">{{ t(`education.statuses.${value}`) }}</option>
                </select>
            </AppField>
            <label class="inline-flex h-9 cursor-pointer items-center gap-2 rounded-[9px] border border-line-strong px-3 text-[13px] has-[:checked]:border-warn has-[:checked]:bg-warn-soft">
                <input v-model="unplaced" type="checkbox" class="accent-[var(--brand)]" />
                {{ t('education.students.unplaced_only') }}
            </label>
            <AppButton v-if="filtering" variant="ghost" :icon="X" @click="clear">{{ t('education.students.filters.clear') }}</AppButton>
        </div>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="8" avatar />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState
                v-else-if="!students.length"
                :icon="GraduationCap"
                :title="filtering ? t('education.students.none_title') : t('education.students.empty_title')"
                :text="filtering ? t('education.students.none_text') : t('education.students.empty_text')"
                compact
            >
                <AppButton v-if="!filtering && setup.can('admit') && setup.ready.value" variant="primary" :icon="UserPlus" @click="adding = true">{{ t('education.students.new') }}</AppButton>
            </EmptyState>

            <template v-else>
                <header class="border-b border-line px-4 py-2.5 text-[12.5px] text-muted sm:px-5">
                    {{ t('education.students.count', { count: meta?.total ?? students.length }) }}
                </header>
                <ul class="divide-y divide-line">
                    <li v-for="student in students" :key="student.id">
                        <RouterLink :to="{ name: 'education-student', params: { id: student.id } }" class="flex items-center gap-3.5 px-4 py-3 transition hover:bg-subtle/60 sm:px-5">
                            <StudentAvatar :name="student.name" :photo-url="student.photo_url" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[14px] font-medium text-fg">
                                    {{ student.name }}<span v-if="student.name_local" class="font-normal text-muted" lang="bn"> · {{ student.name_local }}</span>
                                </span>
                                <span class="mt-0.5 block truncate text-[12.5px] text-muted">
                                    <span class="font-mono" dir="ltr">{{ student.code }}</span> · {{ rowPlace(student) }}
                                </span>
                            </span>
                            <span class="hidden shrink-0 text-[12px] text-faint md:block">{{ textIn(setup.program(student.program_id)?.name) }}</span>
                            <AppBadge :tone="student.enrollment && !student.enrollment.section_id && student.status === 'active' ? 'warn' : statusTone(student.status)" dot class="shrink-0">
                                {{ student.enrollment && !student.enrollment.section_id && student.status === 'active' ? t('education.students.unplaced_only') : t(`education.statuses.${student.status}`) }}
                            </AppBadge>
                        </RouterLink>
                    </li>
                </ul>
            </template>

            <footer v-if="meta && meta.last_page > 1" class="flex items-center justify-between border-t border-line px-5 py-3">
                <span class="tabular text-[12.5px] text-muted">{{ t('education.students.page', { page: meta.page, pages: meta.last_page }) }}</span>
                <div class="flex gap-2">
                    <AppButton size="sm" :icon="ChevronLeft" :disabled="meta.page <= 1" @click="go(meta.page - 1)">{{ t('core.actions.previous') }}</AppButton>
                    <AppButton size="sm" :icon-end="ChevronRight" :disabled="meta.page >= meta.last_page" @click="go(meta.page + 1)">{{ t('core.actions.next') }}</AppButton>
                </div>
            </footer>
        </section>

        <StudentDialog :open="adding" :start="{ program_id: programId || undefined, level_id: levelId || undefined }" @close="closeAdding" @saved="saved" />
    </div>
</template>
