<script setup>
import { computed, ref, watch } from 'vue';
import { BookOpen, Layers, PenLine, Plus, UserRound } from 'lucide-vue-next';
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
import { registrationApi } from '../api';
import { useRegistrationSetup } from '../setup';
import { byLevel, credits } from '../lib';
import CurriculumDialog from '../components/CurriculumDialog.vue';
import OfferingDialog from '../components/OfferingDialog.vue';
import SeatMeter from '../components/SeatMeter.vue';
import SessionBar from '../components/SessionBar.vue';

/**
 * Subjects offered in the session, class by class: group, kind, credits,
 * teacher and seats (taken, left, waiting). People who manage offer a
 * class's curriculum at once, add groups and change seats; someone who
 * only views (a teacher) sees the subjects they teach.
 */
const org = currentOrganization();
const api = registrationApi(org.id);
const setup = useRegistrationSetup();

const list = useResource(() => (setup.sessionId.value ? api.offerings({ session_id: setup.sessionId.value }) : Promise.resolve({ data: [] })), { immediate: false });
watch(() => setup.sessionId.value, (id) => id && list.reload(), { immediate: true });
const offerings = computed(() => list.data.value?.data ?? []);
const rank = (levelId) => {
    const index = (setup.data.value?.levels ?? []).findIndex((level) => level.id === levelId);
    return index < 0 ? 9999 : index;
};
const groups = computed(() => byLevel(offerings.value, rank));
const staff = computed(() => ['manage', 'register', 'approve', 'record_outcome'].some((ability) => setup.can(ability)));

const editing = ref(null); // null | 'new' | offering
const offeringCurriculum = ref(false);
function saved() {
    editing.value = null;
    offeringCurriculum.value = false;
    list.reload();
}
const kindTone = { compulsory: 'brand', elective: 'neutral', optional: 'outline' };
</script>

<template>
    <div>
        <PageHeader :title="t('course_registration.offerings.title')" :description="staff ? t('course_registration.offerings.text') : t('course_registration.offerings.mine')">
            <template v-if="setup.can('manage')" #actions>
                <AppButton :icon="Layers" :disabled="!setup.sessionId.value" @click="offeringCurriculum = true">{{ t('course_registration.offerings.from_curriculum') }}</AppButton>
                <AppButton variant="primary" :icon="Plus" :disabled="!setup.sessionId.value" @click="editing = 'new'">{{ t('course_registration.offerings.add') }}</AppButton>
            </template>
        </PageHeader>

        <SkeletonRows v-if="setup.loading.value && !setup.data.value" :rows="6" />
        <ErrorState v-else-if="setup.error.value" :error="setup.error.value" @retry="setup.reload()" />
        <template v-else-if="setup.data.value">
            <SessionBar @changed="list.reload()" />

            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="6" />
            <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
            <section v-else-if="!offerings.length" class="card">
                <EmptyState :icon="BookOpen" :title="t('course_registration.offerings.empty_title')" :text="setup.can('manage') ? t('course_registration.offerings.empty_text') : ''">
                    <AppButton v-if="setup.can('manage') && setup.sessionId.value" variant="primary" :icon="Layers" @click="offeringCurriculum = true">{{ t('course_registration.offerings.from_curriculum') }}</AppButton>
                </EmptyState>
            </section>

            <div v-else class="space-y-6">
                <section v-for="group in groups" :key="group.level_id ?? 'any'">
                    <h2 class="mb-2.5 text-[14.5px] font-semibold text-fg">{{ group.level_id ? setup.levelText(group.level_id) : t('course_registration.offerings.other_level') }}</h2>
                    <ul class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                        <li v-for="offering in group.offerings" :key="offering.id" class="card relative flex flex-col gap-3 p-4 transition hover:border-brand/40 hover:shadow-sm" :class="offering.status === 'open' ? '' : 'opacity-70'">
                            <div class="flex items-start gap-3">
                                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft font-mono text-[11px] font-semibold text-brand-text" aria-hidden="true">{{ offering.group_name }}</span>
                                <div class="min-w-0 flex-1">
                                    <RouterLink :to="{ name: 'crs-offering', params: { id: offering.id } }" class="block truncate text-[14px] font-semibold text-fg after:absolute after:inset-0 hover:underline">
                                        <span class="font-mono" dir="ltr">{{ offering.subject?.code }}</span> · {{ textIn(offering.subject?.name) }}
                                    </RouterLink>
                                    <p class="flex items-center gap-1.5 truncate text-[12.5px] text-muted">
                                        <UserRound class="size-3.5 shrink-0" aria-hidden="true" />
                                        {{ offering.teacher_id ? setup.teacherName(offering.teacher_id) || t('course_registration.offerings.teacher') : t('course_registration.offerings.no_teacher') }}
                                    </p>
                                </div>
                                <AppButton v-if="setup.can('manage')" size="icon-sm" variant="ghost" class="relative z-10" :icon="PenLine" :aria-label="`${t('course_registration.offering_form.edit_title', { subject: offering.subject?.code ?? '', group: offering.group_name })}`" @click="editing = offering" />
                            </div>
                            <SeatMeter :offering="offering" />
                            <div class="flex flex-wrap gap-1.5">
                                <AppBadge :tone="kindTone[offering.kind]">{{ t(`course_registration.kinds.${offering.kind}`) }}</AppBadge>
                                <AppBadge tone="neutral">{{ t('course_registration.credits_text', { credits: credits(offering.credits_centi) }) }}</AppBadge>
                                <AppBadge v-if="offering.status !== 'open'" tone="outline">{{ t(`course_registration.offering_statuses.${offering.status}`) }}</AppBadge>
                                <AppBadge v-if="(setup.data.value?.campuses?.length ?? 0) > 1" tone="outline">{{ setup.campusName(offering.unit_id) }}</AppBadge>
                            </div>
                        </li>
                    </ul>
                </section>
            </div>
        </template>

        <OfferingDialog :open="editing !== null" :offering="editing === 'new' ? null : editing" @close="editing = null" @saved="saved" @conflict="saved" />
        <CurriculumDialog :open="offeringCurriculum" @close="offeringCurriculum = false" @done="saved" />
    </div>
</template>
