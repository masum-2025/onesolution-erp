<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { BookOpen, CheckCheck, Paperclip, PenLine, Plus, Send, Trash2, Undo2, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppTabs from '@/components/AppTabs.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { confirmAction } from '@/lib/dialogs';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { lecturesApi } from '../api';
import { useLecturesSetup } from '../setup';
import { acceptList } from '../lib';
import LectureCard from '../components/LectureCard.vue';
import LectureDialog from '../components/LectureDialog.vue';

/**
 * Lectures at the unit: a teacher's own sections (and what they posted),
 * everything for who manages lectures. Filter by section, subject and
 * status; post, change, add files, publish, approve, withdraw (with a
 * reason) or delete a draft, as far as the person may. The server checks
 * every step again.
 */
const api = lecturesApi(currentOrganization().id);
const setup = useLecturesSetup();
const route = useRoute();
const router = useRouter();

const section = computed({
    get: () => (typeof route.query.section === 'string' ? route.query.section : ''),
    set: (value) => router.replace({ query: { ...route.query, section: value || undefined, subject: undefined, page: undefined } }),
});
const subject = computed({
    get: () => (typeof route.query.subject === 'string' ? route.query.subject : ''),
    set: (value) => router.replace({ query: { ...route.query, subject: value || undefined, page: undefined } }),
});
const status = computed({
    get: () => (typeof route.query.status === 'string' ? route.query.status : 'all'),
    set: (value) => router.replace({ query: { ...route.query, status: value === 'all' ? undefined : value, page: undefined } }),
});
const page = computed(() => Number(route.query.page ?? 1) || 1);

const list = useResource(() => api.list({ section_id: section.value, subject_id: subject.value, status: status.value === 'all' ? '' : status.value, page: page.value, per_page: 20 }));
watch(() => route.query, () => list.reload());
const rows = computed(() => list.data.value?.data ?? []);
const meta = computed(() => list.data.value?.meta ?? {});

const tabs = computed(() => ['all', 'published', ...(setup.can('post') ? ['draft'] : []), ...(setup.can('manage') || setup.rules.value.approval_required ? ['waiting'] : []), 'withdrawn']
    .map((key) => ({ key, label: t(`education_lectures.tabs.${key}`) })));
const subjectsOf = computed(() => setup.sections.value.find((item) => item.id === section.value)?.subjects ?? []);

// Posting and changing.
const editing = ref(null);
const dialogOpen = ref(false);
function openNew() {
    editing.value = null;
    dialogOpen.value = true;
}
// "New lecture" from the header menu: once the sections are known, so the form starts filled.
watch([() => route.query.new, () => setup.data.value], ([value, data]) => {
    if (value && data) {
        openNew();
        router.replace({ query: { ...route.query, new: undefined } });
    }
}, { immediate: true });
function saved() {
    dialogOpen.value = false;
    list.reload();
}

const busy = ref(null);
async function run(lecture, action, done) {
    busy.value = lecture.id;
    try {
        await action();
        toast.success(done);
        list.reload();
    } catch (error) {
        toast.error(error.code === 'version_conflict' ? t('education_lectures.conflict') : error.errors?.file?.[0] ?? error.message);
        if (error.code === 'version_conflict') list.reload();
    } finally {
        busy.value = null;
    }
}
const publish = (lecture) => run(lecture, () => api.publish(lecture.id, lecture.version), t(setup.rules.value.approval_required && !setup.can('manage') ? 'education_lectures.actions.sent' : 'education_lectures.actions.published'));
const approve = (lecture) => run(lecture, () => api.approve(lecture.id, lecture.version), t('education_lectures.actions.approved'));
async function removeDraft(lecture) {
    const yes = await confirmAction({ title: t('education_lectures.actions.delete_title'), message: t('education_lectures.actions.delete_text', { title: lecture.title }), confirmLabel: t('education_lectures.actions.delete'), danger: true });
    if (yes) run(lecture, () => api.remove(lecture.id), t('education_lectures.actions.deleted'));
}
async function removeFile(lecture, file) {
    const yes = await confirmAction({ title: t('education_lectures.files.remove_title'), message: t('education_lectures.files.remove_text', { name: file.name }), confirmLabel: t('education_lectures.files.remove'), danger: true });
    if (yes) run(lecture, () => api.removeFile(lecture.id, file.id), t('education_lectures.files.removed'));
}

// Files: chosen here, checked by size here and by content on the server.
const picker = ref(null);
const pickingFor = ref(null);
function pickFile(lecture) {
    pickingFor.value = lecture;
    picker.value?.click();
}
function filePicked(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    const lecture = pickingFor.value;
    if (!file || !lecture) return;
    const maxKb = setup.rules.value.max_file_kb ?? 10240;
    if (file.size > maxKb * 1024) {
        toast.error(t('education_lectures.files.too_big', { mb: Math.max(1, Math.floor(maxKb / 1024)) }));
        return;
    }
    run(lecture, () => api.addFile(lecture.id, file), t('education_lectures.files.added'));
}

// Withdrawing (with a reason).
const withdrawing = ref(null);
const reason = ref('');
function askWithdraw(lecture) {
    withdrawing.value = lecture;
    reason.value = '';
}
async function withdraw() {
    const lecture = withdrawing.value;
    await run(lecture, () => api.withdraw(lecture.id, lecture.version, reason.value.trim()), t('education_lectures.actions.withdrawn'));
    withdrawing.value = null;
}
const goPage = (to) => router.replace({ query: { ...route.query, page: to > 1 ? to : undefined } });
</script>

<template>
    <div>
        <PageHeader :title="t('education_lectures.page.title')" :description="t('education_lectures.page.text')">
            <template v-if="setup.can('post')" #actions>
                <AppButton variant="primary" :icon="Plus" @click="openNew">{{ t('education_lectures.page.new') }}</AppButton>
            </template>
        </PageHeader>

        <ErrorState v-if="setup.error.value" :error="setup.error.value" @retry="setup.reload()" />
        <template v-else>
            <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:w-2/3">
                <AppField v-slot="{ id }" :label="t('education_lectures.fields.section')" sr-only-label>
                    <select :id="id" v-model="section" class="field-input">
                        <option value="">{{ t('education_lectures.filters.all_sections') }}</option>
                        <option v-for="item in setup.sections.value" :key="item.id" :value="item.id">{{ textIn(item.level) }} · {{ item.name }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('education_lectures.fields.subject')" sr-only-label>
                    <select :id="id" v-model="subject" class="field-input" :disabled="!section">
                        <option value="">{{ t('education_lectures.filters.all_subjects') }}</option>
                        <option v-for="item in subjectsOf" :key="item.id" :value="item.id">{{ textIn(item.name) }}</option>
                    </select>
                </AppField>
            </div>
            <div class="mb-4 overflow-x-auto"><AppTabs v-model="status" :tabs="tabs" :label="t('education_lectures.page.title')" /></div>

            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
            <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
            <div v-else-if="!rows.length" class="card">
                <EmptyState :icon="BookOpen" :title="t('education_lectures.page.empty_title')" :text="setup.can('post') ? t('education_lectures.page.empty_text_post') : t('education_lectures.page.empty_text')">
                    <AppButton v-if="setup.can('post') && setup.postable.value.length" variant="primary" :icon="Plus" @click="openNew">{{ t('education_lectures.page.new') }}</AppButton>
                </EmptyState>
            </div>
            <div v-else class="space-y-3">
                <LectureCard v-for="lecture in rows" :key="lecture.id" :lecture="lecture" :names="meta" show-status>
                    <template v-if="lecture.may_change || (setup.can('manage') && lecture.status === 'waiting')">
                        <AppButton v-if="lecture.may_change && lecture.status === 'draft'" size="sm" variant="primary" :icon="Send" :loading="busy === lecture.id" @click="publish(lecture)">
                            {{ setup.rules.value.approval_required && !setup.can('manage') ? t('education_lectures.actions.send') : t('education_lectures.actions.publish') }}
                        </AppButton>
                        <AppButton v-if="setup.can('manage') && lecture.status === 'waiting'" size="sm" variant="primary" :icon="CheckCheck" @click="approve(lecture)">{{ t('education_lectures.actions.approve') }}</AppButton>
                        <AppButton v-if="lecture.may_change && lecture.status !== 'withdrawn'" size="sm" :icon="PenLine" @click="editing = lecture; dialogOpen = true">{{ t('education_lectures.actions.edit') }}</AppButton>
                        <AppButton
                            v-if="lecture.may_change && lecture.status !== 'withdrawn' && lecture.files.length < (setup.rules.value.max_files ?? 5)"
                            size="sm"
                            :icon="Paperclip"
                            @click="pickFile(lecture)"
                        >{{ t('education_lectures.files.add') }}</AppButton>
                        <AppButton v-if="lecture.may_change && ['waiting', 'published'].includes(lecture.status)" size="sm" variant="danger-soft" :icon="Undo2" @click="askWithdraw(lecture)">{{ t('education_lectures.actions.withdraw') }}</AppButton>
                        <AppButton v-if="lecture.may_change && lecture.status === 'draft'" size="sm" variant="danger-soft" :icon="Trash2" @click="removeDraft(lecture)">{{ t('education_lectures.actions.delete') }}</AppButton>
                        <span v-if="lecture.may_change && lecture.status !== 'withdrawn' && lecture.files.length" class="flex basis-full flex-wrap gap-1.5 pt-1">
                            <button
                                v-for="file in lecture.files"
                                :key="file.id"
                                type="button"
                                class="inline-flex items-center gap-1 rounded-full bg-subtle px-2 py-0.5 text-[11.5px] text-muted hover:text-bad"
                                :aria-label="t('education_lectures.files.remove_named', { name: file.name })"
                                @click="removeFile(lecture, file)"
                            >
                                <X class="size-3" aria-hidden="true" />{{ file.name }}
                            </button>
                        </span>
                    </template>
                </LectureCard>

                <nav v-if="meta.last_page > 1" class="flex items-center justify-between pt-2" :aria-label="t('education_lectures.page.pages')">
                    <AppButton size="sm" variant="ghost" :disabled="page <= 1" @click="goPage(page - 1)">{{ t('education_lectures.page.previous') }}</AppButton>
                    <span class="tabular text-[12.5px] text-muted">{{ t('education_lectures.page.page', { page, pages: meta.last_page }) }}</span>
                    <AppButton size="sm" variant="ghost" :disabled="page >= meta.last_page" @click="goPage(page + 1)">{{ t('education_lectures.page.next') }}</AppButton>
                </nav>
            </div>
        </template>

        <input ref="picker" type="file" class="sr-only" tabindex="-1" :accept="acceptList(setup.data.value?.file_types)" @change="filePicked" />
        <LectureDialog :open="dialogOpen" :lecture="editing" :start="{ section_id: section, subject_id: subject }" @close="dialogOpen = false" @saved="saved" @conflict="dialogOpen = false; list.reload()" />

        <AppDialog :open="withdrawing !== null" tone="bad" :icon="Undo2" :title="t('education_lectures.actions.withdraw_title')" :description="t('education_lectures.actions.withdraw_text')" @close="withdrawing = null">
            <AppField v-slot="{ id }" :label="t('education_lectures.actions.reason')">
                <input :id="id" v-model="reason" class="field-input" maxlength="300" :placeholder="t('education_lectures.actions.reason_hint')" />
            </AppField>
            <template #footer>
                <AppButton variant="ghost" @click="withdrawing = null">{{ t('education_lectures.cancel') }}</AppButton>
                <AppButton variant="danger" :disabled="reason.trim().length < 3" :loading="busy !== null" @click="withdraw">{{ t('education_lectures.actions.withdraw') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
