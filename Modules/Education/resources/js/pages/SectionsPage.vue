<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { LayoutGrid, PenLine, Plus, UserRound } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { educationApi } from '../api';
import { useEducationSetup } from '../setup';
import { byLevel } from '../lib';
import CapacityMeter from '../components/CapacityMeter.vue';
import StructureDialog from '../components/StructureDialog.vue';

/**
 * Sections of a session, class by class: how full each one is, its class
 * teacher, shift and medium. A teacher sees their own sections only (the
 * server decides); people who manage education make and change them.
 */
const org = currentOrganization();
const education = educationApi(org.id);
const setup = useEducationSetup();
const route = useRoute();
const router = useRouter();

const sessionId = ref(route.query.session ?? '');
const overview = useResource(() => education.overview(sessionId.value ? { session_id: sessionId.value } : {}));
const data = computed(() => overview.data.value?.data ?? null);
const groups = computed(() => byLevel(data.value?.sections ?? [], setup.rank));
const sessions = computed(() => setup.data.value?.sessions ?? []);

watch(sessionId, (value) => {
    router.replace({ query: value ? { session: value } : {} });
    overview.reload();
});
watch(data, (value) => {
    if (value?.session_id && !sessionId.value) sessionId.value = value.session_id;
});

const editing = ref(null); // { record, start }
function saved() {
    editing.value = null;
    overview.reload();
}
const listNames = (section) => ['shift_id', 'medium_id', 'stream_id'].map((key) => section[key] && setup.listName(key.replace('_id', ''), section[key])).filter(Boolean);
</script>

<template>
    <div>
        <PageHeader :title="t('education.sections.title')" :description="t('education.sections.text')">
            <template #actions>
                <label class="flex items-center gap-2">
                    <span class="sr-only">{{ t('education.overview.session') }}</span>
                    <select v-model="sessionId" class="field-input h-9 w-auto min-w-44" :disabled="!sessions.length">
                        <option v-if="!sessions.length" value="">{{ t('education.overview.no_sessions') }}</option>
                        <option v-for="session in sessions" :key="session.id" :value="session.id">{{ textIn(session.name) }}</option>
                    </select>
                </label>
                <AppButton v-if="setup.can('manage')" variant="primary" :icon="Plus" :disabled="!sessions.length" @click="editing = { record: null, start: { session_id: sessionId } }">{{ t('education.sections.new') }}</AppButton>
            </template>
        </PageHeader>

        <SkeletonRows v-if="overview.loading.value && !data" :rows="6" />
        <ErrorState v-else-if="overview.error.value" :error="overview.error.value" @retry="overview.reload()" />
        <section v-else-if="!groups.length" class="card">
            <EmptyState :icon="LayoutGrid" :title="t('education.sections.empty_title')" :text="t('education.sections.empty_text')">
                <AppButton v-if="setup.can('manage') && sessions.length" variant="primary" :icon="Plus" @click="editing = { record: null, start: { session_id: sessionId } }">{{ t('education.sections.new') }}</AppButton>
            </EmptyState>
        </section>

        <div v-else class="space-y-6">
            <section v-for="group in groups" :key="group.level_id">
                <header class="mb-2.5 flex items-center justify-between gap-3">
                    <h2 class="text-[14.5px] font-semibold text-fg">{{ setup.levelText(group.level_id) }}</h2>
                    <AppButton v-if="setup.can('manage')" size="sm" variant="ghost" :icon="Plus" @click="editing = { record: null, start: { session_id: sessionId, level_id: group.level_id } }">
                        {{ setup.sectionWord(setup.level(group.level_id)?.program_id, t('education.word.section')) }}
                    </AppButton>
                </header>
                <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <li v-for="section in group.sections" :key="section.id" class="card relative flex flex-col gap-3 p-4 transition hover:border-brand/40 hover:shadow-sm" :class="section.is_active ? '' : 'opacity-70'">
                        <div class="flex items-start gap-3">
                            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-[15px] font-semibold text-brand-text" aria-hidden="true">{{ Array.from(section.name)[0] }}</span>
                            <div class="min-w-0 flex-1">
                                <RouterLink :to="{ name: 'education-section', params: { id: section.id } }" class="block truncate text-[14.5px] font-semibold text-fg after:absolute after:inset-0 hover:underline">
                                    {{ section.name }}
                                </RouterLink>
                                <p class="flex items-center gap-1.5 truncate text-[12.5px] text-muted">
                                    <UserRound class="size-3.5 shrink-0" aria-hidden="true" />
                                    {{ section.class_teacher_id ? setup.teacherName(section.class_teacher_id) || t('education.sections.class_teacher') : t('education.sections.no_teacher') }}
                                </p>
                            </div>
                            <AppButton
                                v-if="setup.can('manage')"
                                size="icon-sm"
                                variant="ghost"
                                class="relative z-10"
                                :icon="PenLine"
                                :aria-label="`${t('education.edit')} ${section.name}`"
                                @click="editing = { record: section, start: {} }"
                            />
                        </div>
                        <CapacityMeter :taken="section.taken" :capacity="section.capacity" />
                        <div v-if="listNames(section).length || !section.is_active" class="flex flex-wrap gap-1.5">
                            <AppBadge v-if="!section.is_active" tone="outline">{{ t('education.sections.inactive') }}</AppBadge>
                            <AppBadge v-for="name in listNames(section)" :key="name">{{ name }}</AppBadge>
                        </div>
                    </li>
                </ul>
            </section>
        </div>

        <StructureDialog :open="editing !== null" kind="sections" :record="editing?.record ?? null" :start="editing?.start ?? {}" @close="editing = null" @saved="saved" @conflict="saved" />
    </div>
</template>
