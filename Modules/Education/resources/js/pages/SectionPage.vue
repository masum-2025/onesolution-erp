<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, ArrowRightLeft, IdCard, ListOrdered, PenLine, SearchX, Users } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { confirmAction } from '@/lib/dialogs';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { educationApi } from '../api';
import { useEducationSetup } from '../setup';
import CapacityMeter from '../components/CapacityMeter.vue';
import PlaceDialog from '../components/PlaceDialog.vue';
import StructureDialog from '../components/StructureDialog.vue';
import IssueDialog from '../components/IssueDialog.vue';
import StudentAvatar from '../components/StudentAvatar.vue';

/**
 * One section's students in roll order (those without a roll at the end),
 * how full it is and who teaches it. Rolls are numbered by the
 * institution's rule; students are moved one by one.
 */
const org = currentOrganization();
const education = educationApi(org.id);
const setup = useEducationSetup();
const route = useRoute();
const router = useRouter();

// ID cards for everyone in the section, then straight to printing.
const issuing = ref(false);
function issued(made) {
    issuing.value = false;
    router.push({ name: 'education-print', query: { ids: made.map((item) => item.id).join(',') } });
}

const roster = useResource(() => education.roster(route.params.id));
const data = computed(() => roster.data.value?.data ?? null);
const section = computed(() => data.value?.section ?? null);
const programId = computed(() => setup.level(section.value?.level_id)?.program_id);
const canEdit = computed(() => setup.can('edit_students'));

const numbering = ref(false);
async function numberRolls() {
    const confirmed = await confirmAction({ title: t('education.section.number_title'), message: t('education.section.number_text'), confirmLabel: t('education.section.number_confirm') });
    if (!confirmed) return;
    numbering.value = true;
    try {
        await education.rolls(section.value.id);
        toast.success(t('education.section.numbered'));
        roster.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        numbering.value = false;
    }
}

const moving = ref(null);
const editing = ref(false);
function done() {
    moving.value = null;
    editing.value = false;
    roster.reload();
}
const listNames = computed(() => (section.value ? ['shift', 'medium', 'stream'].map((kind) => section.value[`${kind}_id`] && setup.listName(kind, section.value[`${kind}_id`])).filter(Boolean) : []));
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'education-sections', query: section ? { session: section.session_id } : {} }" :icon="ArrowLeft" class="mb-3">{{ t('education.section.back') }}</AppButton>

        <SkeletonRows v-if="roster.loading.value && !data" :rows="8" avatar />
        <section v-else-if="roster.error.value?.status === 404" class="card">
            <EmptyState :icon="SearchX" :title="t('education.section.not_found')" />
        </section>
        <ErrorState v-else-if="roster.error.value" :error="roster.error.value" @retry="roster.reload()" />

        <template v-else-if="section">
            <PageHeader :title="`${setup.levelText(section.level_id)} · ${section.name}`" :description="setup.sessionText(section.session_id)">
                <template #actions>
                    <AppButton v-if="setup.can('issue_documents') && data.students.length" size="sm" :icon="IdCard" @click="issuing = true">{{ t('education.section_documents.issue') }}</AppButton>
                    <AppButton v-if="setup.can('manage')" size="sm" :icon="PenLine" @click="editing = true">{{ t('education.edit') }}</AppButton>
                    <AppButton v-if="canEdit && data.students.length" size="sm" variant="primary" :icon="ListOrdered" :loading="numbering" @click="numberRolls">{{ t('education.section.number_rolls') }}</AppButton>
                </template>
            </PageHeader>

            <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="card p-4 sm:col-span-1">
                    <CapacityMeter :taken="data.taken" :capacity="section.capacity" />
                </div>
                <div class="card p-4">
                    <p class="text-[12.5px] text-muted">{{ t('education.sections.class_teacher') }}</p>
                    <p class="truncate text-[14px] font-medium text-fg">{{ section.class_teacher_id ? setup.teacherName(section.class_teacher_id) || '—' : t('education.sections.no_teacher') }}</p>
                </div>
                <div class="card flex flex-wrap content-center gap-1.5 p-4">
                    <AppBadge v-if="!section.is_active" tone="outline">{{ t('education.sections.inactive') }}</AppBadge>
                    <AppBadge v-for="name in listNames" :key="name">{{ name }}</AppBadge>
                    <span v-if="!listNames.length && section.is_active" class="text-[12.5px] text-faint">{{ setup.sectionWord(programId, t('education.word.section')) }}</span>
                </div>
            </div>

            <section class="card">
                <header class="border-b border-line px-5 py-3 text-[13.5px] font-semibold text-fg">{{ t('education.section.roster') }}</header>
                <EmptyState v-if="!data.students.length" :icon="Users" :title="t('education.section.empty_title')" :text="t('education.section.empty_text')" compact />
                <ol v-else class="divide-y divide-line">
                    <li v-for="item in data.students" :key="item.id" class="flex items-center gap-3 px-4 py-2.5 sm:px-5">
                        <span class="tabular w-10 shrink-0 text-center text-[15px] font-semibold" :class="item.roll_no ? 'text-fg' : 'text-faint'" :title="item.roll_no ? '' : t('education.section.no_roll')">
                            {{ item.roll_no ?? '–' }}
                        </span>
                        <StudentAvatar :name="item.name" size="sm" />
                        <RouterLink :to="{ name: 'education-student', params: { id: item.student_id } }" class="min-w-0 flex-1 hover:underline">
                            <span class="block truncate text-[14px] font-medium text-fg">{{ item.name }}<span v-if="item.name_local" class="font-normal text-muted" lang="bn"> · {{ item.name_local }}</span></span>
                            <span class="block font-mono text-[12px] text-muted" dir="ltr">{{ item.code }}</span>
                        </RouterLink>
                        <AppButton v-if="canEdit" size="sm" variant="ghost" :icon="ArrowRightLeft" :aria-label="t('education.section.move_named', { name: item.name })" @click="moving = item">
                            <span class="hidden sm:inline">{{ t('education.section.move') }}</span>
                        </AppButton>
                    </li>
                </ol>
            </section>

            <PlaceDialog :open="moving !== null" :name="moving?.name ?? ''" :enrollment="moving" :education="education" @close="moving = null" @done="done" @conflict="done" />
            <IssueDialog :open="issuing" :students="data.students.map((item) => ({ id: item.student_id, name: item.name }))" kind="id_card" :education="education" @close="issuing = false" @issued="issued" />
            <StructureDialog :open="editing" kind="sections" :record="section" @close="editing = false" @saved="done" @conflict="done" />
        </template>
    </div>
</template>
