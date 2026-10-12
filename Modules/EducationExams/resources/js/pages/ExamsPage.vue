<script setup>
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { ClipboardList, Plus } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppTabs from '@/components/AppTabs.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { examsApi } from '../api';
import { useExamsSetup } from '../setup';
import { examTone } from '../lib';
import { dateRange } from '../labels';
import ExamDialog from '../components/ExamDialog.vue';
import SheetBar from '../components/SheetBar.vue';

/**
 * Exams of a session at the unit and below, by status, each with how far
 * its mark sheets are. People who manage exams make new ones here.
 */
const api = examsApi(currentOrganization().id);
const setup = useExamsSetup();
const router = useRouter();

const sessionId = ref('');
watch(() => setup.data.value, (data) => {
    if (data && !sessionId.value) sessionId.value = data.sessions.find((session) => session.status === 'open')?.id ?? data.sessions[0]?.id ?? '';
}, { immediate: true });
const list = useResource(() => (sessionId.value ? api.exams({ session_id: sessionId.value }) : Promise.resolve({ data: [] })), { immediate: false });
watch(sessionId, (id) => id && list.reload(), { immediate: true });

const tab = ref('all');
const all = computed(() => list.data.value?.data ?? []);
const tabs = computed(() => ['all', 'draft', 'marking', 'closed', 'published'].map((key) => ({
    key, label: t(`education_exams.list.tabs.${key}`), count: key === 'all' ? undefined : all.value.filter((exam) => exam.status === key).length || undefined,
})));
const rows = computed(() => (tab.value === 'all' ? all.value : all.value.filter((exam) => exam.status === tab.value)));

const making = ref(false);
function made(exam) {
    making.value = false;
    router.push({ name: 'exams-exam', params: { id: exam.id } });
}
</script>

<template>
    <div>
        <PageHeader :title="t('education_exams.list.title')" :description="t('education_exams.list.text')">
            <template v-if="setup.can('manage')" #actions>
                <AppButton variant="primary" :icon="Plus" :disabled="!setup.data.value?.sessions.length" @click="making = true">{{ t('education_exams.exam.new') }}</AppButton>
            </template>
        </PageHeader>

        <SkeletonRows v-if="setup.loading.value && !setup.data.value" :rows="5" />
        <ErrorState v-else-if="setup.error.value" :error="setup.error.value" @retry="setup.reload()" />
        <EmptyState v-else-if="setup.data.value && !setup.data.value.sessions.length" :icon="ClipboardList" :title="t('education_exams.list.no_sessions')" :text="t('education_exams.list.no_sessions_text')" />
        <template v-else-if="setup.data.value">
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <label class="w-full sm:w-64">
                    <span class="sr-only">{{ t('education_exams.exam.session') }}</span>
                    <select v-model="sessionId" class="field-input">
                        <option v-for="session in setup.data.value.sessions" :key="session.id" :value="session.id">{{ textIn(session.name) }}</option>
                    </select>
                </label>
                <div class="min-w-0 max-w-full overflow-x-auto"><AppTabs v-model="tab" :tabs="tabs" :label="t('education_exams.list.title')" /></div>
            </div>

            <section class="card">
                <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
                <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
                <EmptyState v-else-if="!rows.length" :icon="ClipboardList" compact
                    :title="t(all.length ? 'education_exams.list.none_here' : 'education_exams.list.empty')"
                    :text="!all.length && setup.can('manage') ? t('education_exams.list.empty_text') : ''" />
                <ul v-else class="divide-y divide-line">
                    <li v-for="exam in rows" :key="exam.id">
                        <RouterLink :to="{ name: 'exams-exam', params: { id: exam.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3 transition hover:bg-subtle/60">
                            <span class="min-w-0 basis-full sm:basis-0 sm:flex-1">
                                <span class="block truncate text-[14px] font-medium text-fg">{{ textIn(exam.name) }}</span>
                                <span class="block text-[12.5px] text-muted">
                                    {{ t(`education_exams.kinds.${exam.kind}`) }}
                                    <template v-if="exam.starts_on"> · {{ dateRange(exam.starts_on, exam.ends_on) }}</template>
                                    <template v-if="setup.data.value.campuses.length > 1"> · {{ setup.campusName(exam.unit_id) }}</template>
                                </span>
                            </span>
                            <SheetBar v-if="exam.status !== 'draft'" :counts="exam.counts" class="w-full sm:w-44" />
                            <AppBadge :tone="examTone[exam.status]" dot>{{ t(`education_exams.exam_statuses.${exam.status}`) }}</AppBadge>
                        </RouterLink>
                    </li>
                </ul>
            </section>
        </template>

        <ExamDialog :open="making" :session-id="sessionId" @close="making = false" @saved="made" />
    </div>
</template>
