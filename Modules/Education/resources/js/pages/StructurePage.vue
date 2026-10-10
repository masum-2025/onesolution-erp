<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, BookOpen, CalendarRange, Layers, PenLine, Plus, Sparkles } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppTabs from '@/components/AppTabs.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { formatDate } from '@/lib/format';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { educationApi } from '../api';
import { useEducationSetup } from '../setup';
import StructureDialog from '../components/StructureDialog.vue';

/**
 * How the institution is organised: programs and their classes, years and
 * their sessions, its own lists (genders, relations, shifts…) and batches.
 * Read by everyone in education; changed by people who manage it. Entries
 * in use are switched off in their form, never deleted.
 */
const org = currentOrganization();
const education = educationApi(org.id);
const setup = useEducationSetup();
const route = useRoute();
const router = useRouter();

const tabs = computed(() => [
    { key: 'programs', label: t('education.structure.tabs.programs'), count: setup.data.value?.programs?.length },
    { key: 'years', label: t('education.structure.tabs.years'), count: setup.data.value?.years?.length },
    { key: 'lists', label: t('education.structure.tabs.lists') },
    { key: 'batches', label: t('education.structure.tabs.batches'), count: setup.data.value?.batches?.length },
]);
const tab = computed({
    get: () => (tabs.value.some((item) => item.key === route.query.tab) ? route.query.tab : 'programs'),
    set: (value) => router.replace({ query: value === 'programs' ? {} : { tab: value } }),
});

const manage = computed(() => setup.can('manage'));
const programs = computed(() => setup.data.value?.programs ?? []);
const years = computed(() => setup.data.value?.years ?? []);
const LIST_KINDS = ['gender', 'relation', 'category', 'shift', 'medium', 'stream'];
const listsOf = (kind) => (setup.data.value?.lists ?? []).filter((item) => item.kind === kind);
const sessionsOf = (yearId) => (setup.data.value?.sessions ?? []).filter((session) => session.academic_year_id === yearId).sort((a, b) => (a.kind === b.kind ? a.sequence - b.sequence : a.kind.localeCompare(b.kind)));
const allLevelsOf = (programId) => (setup.data.value?.levels ?? []).filter((level) => level.program_id === programId).sort((a, b) => a.sequence - b.sequence);

const editing = ref(null); // { kind, record, start }
function open(kind, record = null, start = {}) {
    editing.value = { kind, record, start };
}
function closed() {
    editing.value = null;
}

// Presets: apply another one (only what is missing is made).
const presetsOpen = ref(false);
const applying = ref(null);
async function apply(key) {
    applying.value = key;
    try {
        const { data } = await education.applyPreset(key);
        toast.success(t('education.wizard.applied', { count: Object.values(data.made).reduce((sum, count) => sum + count, 0) }));
        presetsOpen.value = false;
        await setup.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        applying.value = null;
    }
}
const statusTone = (status) => ({ open: 'ok', planned: 'brand', closed: 'neutral' })[status] ?? 'neutral';
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'education' }" :icon="ArrowLeft" class="mb-3">{{ t('education.back') }}</AppButton>
        <PageHeader :title="t('education.structure.title')" :description="t('education.structure.text')">
            <template v-if="manage" #actions>
                <AppButton :icon="Sparkles" @click="presetsOpen = true">{{ t('education.structure.preset') }}</AppButton>
            </template>
        </PageHeader>

        <SkeletonRows v-if="setup.loading.value && !setup.data.value" :rows="6" />
        <ErrorState v-else-if="setup.error.value" :error="setup.error.value" @retry="setup.reload()" />

        <template v-else-if="setup.data.value">
            <div class="mb-5"><AppTabs v-model="tab" :tabs="tabs" :label="t('education.structure.title')" /></div>
            <p v-if="!manage" class="mb-4 rounded-xl bg-subtle px-4 py-3 text-[12.5px] text-muted">{{ t('education.no_manage') }}</p>

            <!-- Programs and their classes -->
            <section v-if="tab === 'programs'" class="space-y-4">
                <div v-if="manage" class="flex justify-end"><AppButton variant="primary" size="sm" :icon="Plus" @click="open('programs')">{{ t('education.structure.add.programs') }}</AppButton></div>
                <div v-if="!programs.length" class="card">
                    <EmptyState :icon="BookOpen" :title="t('education.structure.programs_empty_title')" :text="t('education.structure.programs_empty_text')" compact />
                </div>
                <article v-for="program in programs" :key="program.id" class="card" :class="program.is_active ? '' : 'opacity-70'">
                    <header class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3.5">
                        <span class="grid size-9 place-items-center rounded-xl bg-brand-soft text-brand-text" aria-hidden="true"><Layers class="size-[18px]" /></span>
                        <div class="min-w-0 flex-1">
                            <h2 class="truncate text-[14.5px] font-semibold text-fg">{{ textIn(program.name) }} <span class="font-mono text-[12px] font-normal text-muted" dir="ltr">{{ program.code }}</span></h2>
                            <p class="text-[12.5px] text-muted">
                                {{ t(`education.progressions.${program.progression}`) }}
                                · {{ t('education.structure.levels_count', { count: allLevelsOf(program.id).length }) }}
                            </p>
                        </div>
                        <AppBadge v-if="!program.is_active" tone="outline">{{ t('education.structure.inactive') }}</AppBadge>
                        <AppButton v-if="manage" size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="`${t('education.edit')} ${textIn(program.name)}`" @click="open('programs', program)" />
                    </header>
                    <ol class="flex flex-wrap gap-2 px-5 py-3.5">
                        <li v-for="level in allLevelsOf(program.id)" :key="level.id">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-full border border-line px-3 py-1 text-[12.5px] transition enabled:hover:border-brand/50 enabled:hover:bg-brand-soft"
                                :class="level.is_active ? 'text-fg-2' : 'text-faint line-through'"
                                :disabled="!manage"
                                @click="open('levels', level)"
                            >
                                <span class="tabular text-faint">{{ level.sequence }}</span>{{ textIn(level.name) }}
                            </button>
                        </li>
                        <li v-if="!allLevelsOf(program.id).length" class="text-[12.5px] text-muted">{{ t('education.structure.no_levels') }}</li>
                        <li v-if="manage">
                            <button type="button" class="inline-flex items-center gap-1 rounded-full border border-dashed border-line-strong px-3 py-1 text-[12.5px] text-muted hover:text-fg" @click="open('levels', null, { program_id: program.id, sequence: allLevelsOf(program.id).length + 1 })">
                                <Plus class="size-3.5" aria-hidden="true" />{{ t('education.structure.add.levels') }}
                            </button>
                        </li>
                    </ol>
                </article>
            </section>

            <!-- Years and sessions -->
            <section v-else-if="tab === 'years'" class="space-y-4">
                <div v-if="manage" class="flex justify-end"><AppButton variant="primary" size="sm" :icon="Plus" @click="open('years')">{{ t('education.structure.add.years') }}</AppButton></div>
                <div v-if="!years.length" class="card">
                    <EmptyState :icon="CalendarRange" :title="t('education.structure.years_empty_title')" :text="t('education.structure.years_empty_text')" compact />
                </div>
                <article v-for="year in years" :key="year.id" class="card">
                    <header class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3.5">
                        <div class="min-w-0 flex-1">
                            <h2 class="text-[14.5px] font-semibold text-fg">{{ year.name }}</h2>
                            <p class="text-[12.5px] text-muted">{{ formatDate(year.starts_on) }} – {{ formatDate(year.ends_on) }}</p>
                        </div>
                        <AppBadge :tone="statusTone(year.status)" dot>{{ t(`education.session_statuses.${year.status}`) }}</AppBadge>
                        <AppButton v-if="manage" size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="`${t('education.edit')} ${year.name}`" @click="open('years', year)" />
                    </header>
                    <ul class="divide-y divide-line">
                        <li v-for="session in sessionsOf(year.id)" :key="session.id" class="flex flex-wrap items-center gap-3 px-5 py-2.5">
                            <span class="min-w-0 flex-1">
                                <span class="block text-[13.5px] font-medium text-fg">{{ textIn(session.name) }}</span>
                                <span class="block text-[12px] text-muted">{{ t(`education.progressions.${session.kind}`) }} · {{ formatDate(session.starts_on) }} – {{ formatDate(session.ends_on) }}</span>
                            </span>
                            <AppBadge :tone="statusTone(session.status)">{{ t(`education.session_statuses.${session.status}`) }}</AppBadge>
                            <AppButton v-if="manage" size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="`${t('education.edit')} ${textIn(session.name)}`" @click="open('sessions', session)" />
                        </li>
                        <li v-if="manage" class="px-5 py-2.5">
                            <AppButton size="sm" variant="ghost" :icon="Plus" @click="open('sessions', null, { academic_year_id: year.id, starts_on: year.starts_on, ends_on: year.ends_on, sequence: sessionsOf(year.id).length + 1 })">
                                {{ t('education.structure.add.sessions') }}
                            </AppButton>
                        </li>
                    </ul>
                </article>
            </section>

            <!-- Own lists -->
            <section v-else-if="tab === 'lists'" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                <article v-for="kind in LIST_KINDS" :key="kind" class="card">
                    <header class="flex items-center justify-between gap-2 border-b border-line px-4 py-3">
                        <h2 class="text-[13.5px] font-semibold text-fg">{{ t(`education.structure.list_kinds.${kind}`) }}</h2>
                        <AppButton v-if="manage" size="icon-sm" variant="ghost" :icon="Plus" :aria-label="t('education.structure.add.lists')" @click="open('lists', null, { kind })" />
                    </header>
                    <ul class="divide-y divide-line">
                        <li v-for="item in listsOf(kind)" :key="item.id" class="flex items-center gap-2 px-4 py-2">
                            <span class="min-w-0 flex-1 truncate text-[13.5px]" :class="item.is_active ? 'text-fg' : 'text-faint line-through'">{{ textIn(item.name) }}</span>
                            <span class="font-mono text-[11.5px] text-faint" dir="ltr">{{ item.key }}</span>
                            <AppButton v-if="manage" size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="`${t('education.edit')} ${textIn(item.name)}`" @click="open('lists', item)" />
                        </li>
                        <li v-if="!listsOf(kind).length" class="px-4 py-3 text-[12.5px] text-muted">{{ t('education.structure.lists_empty') }}</li>
                    </ul>
                </article>
            </section>

            <!-- Batches -->
            <section v-else class="space-y-4">
                <div v-if="manage" class="flex justify-end"><AppButton variant="primary" size="sm" :icon="Plus" :disabled="!programs.length" @click="open('batches', null, { program_id: programs[0]?.id })">{{ t('education.structure.add.batches') }}</AppButton></div>
                <div class="card">
                    <EmptyState v-if="!(setup.data.value.batches ?? []).length" :icon="Layers" :title="t('education.structure.batches_empty_title')" :text="t('education.structure.batches_empty_text')" compact />
                    <ul v-else class="divide-y divide-line">
                        <li v-for="batch in setup.data.value.batches" :key="batch.id" class="flex items-center gap-3 px-5 py-3">
                            <span class="min-w-0 flex-1">
                                <span class="block text-[13.5px] font-medium" :class="batch.is_active ? 'text-fg' : 'text-faint line-through'">{{ batch.name }}</span>
                                <span class="block text-[12px] text-muted">{{ textIn(setup.program(batch.program_id)?.name) }}<template v-if="batch.intake_session_id"> · {{ setup.sessionText(batch.intake_session_id) }}</template></span>
                            </span>
                            <AppButton v-if="manage" size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="`${t('education.edit')} ${batch.name}`" @click="open('batches', batch)" />
                        </li>
                    </ul>
                </div>
            </section>
        </template>

        <StructureDialog :open="editing !== null" :kind="editing?.kind" :record="editing?.record ?? null" :start="editing?.start ?? {}" @close="closed" @saved="closed" @conflict="closed(); setup.reload()" />

        <AppDialog :open="presetsOpen" :title="t('education.structure.preset')" :description="t('education.wizard.preset_hint')" :icon="Sparkles" size="lg" @close="presetsOpen = false">
            <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <li v-for="preset in setup.data.value?.presets ?? []" :key="preset.key" class="flex flex-col rounded-2xl border border-line p-4">
                    <h3 class="text-[14px] font-semibold text-fg">{{ textIn(preset.name) }}</h3>
                    <p class="mt-1 flex-1 text-[12.5px] text-muted">{{ textIn(preset.description) }}</p>
                    <AppButton class="mt-3 self-start" size="sm" variant="primary" :loading="applying === preset.key" :disabled="applying !== null" @click="apply(preset.key)">{{ t('education.wizard.apply') }}</AppButton>
                </li>
            </ul>
            <template #footer>
                <AppButton variant="ghost" @click="presetsOpen = false">{{ t('education.close') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
