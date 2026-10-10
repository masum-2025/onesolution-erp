<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, ArrowRightLeft, Camera, DoorOpen, FileBadge, Mail, PenLine, Phone, Plus, Printer, Trash2, UserX, UsersRound } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppTabs from '@/components/AppTabs.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { confirmAction } from '@/lib/dialogs';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { educationApi } from '../api';
import { useEducationSetup } from '../setup';
import { fieldText, phoneText, statusTone } from '../lib';
import StudentAvatar from '../components/StudentAvatar.vue';
import StudentDialog from '../components/StudentDialog.vue';
import GuardianDialog from '../components/GuardianDialog.vue';
import LeaveDialog from '../components/LeaveDialog.vue';
import PlaceDialog from '../components/PlaceDialog.vue';
import IssueDialog from '../components/IssueDialog.vue';

/**
 * One student: their details and where they study now, their guardians and
 * their classes over time. Changing, moving, leaving and guardians need
 * education.edit_students; the server checks every step again.
 */
const PHOTO_MAX_KB = 2048;

const org = currentOrganization();
const education = educationApi(org.id);
const setup = useEducationSetup();
const route = useRoute();
const router = useRouter();

const record = useResource(() => education.student(route.params.id));
const student = computed(() => record.data.value?.data ?? null);
const canEdit = computed(() => setup.can('edit_students'));
const sensitive = computed(() => setup.can('view_sensitive'));

const tabs = computed(() => [
    { key: 'overview', label: t('education.student.tabs.overview') },
    { key: 'guardians', label: t('education.student.tabs.guardians'), count: student.value?.guardians?.length },
    { key: 'history', label: t('education.student.tabs.history') },
    ...(setup.can('issue_documents') ? [{ key: 'documents', label: t('education.student_documents.tab') }] : []),
]);
const tab = computed({
    get: () => (tabs.value.some((item) => item.key === route.query.tab) ? route.query.tab : 'overview'),
    set: (value) => router.replace({ query: value === 'overview' ? {} : { tab: value } }),
});

// Section names of the sessions this student studied in.
const sectionNames = ref({});
watch(student, async (value) => {
    const sessions = [...new Set((value?.enrollments ?? []).map((item) => item.session_id))].filter((id) => !(id in sectionNames.value));
    for (const sessionId of sessions) {
        try {
            const { data } = await education.list('sections', { session_id: sessionId });
            sectionNames.value = { ...sectionNames.value, [sessionId]: Object.fromEntries(data.map((section) => [section.id, section.name])) };
        } catch {
            sectionNames.value = { ...sectionNames.value, [sessionId]: {} };
        }
    }
});
const sectionName = (enrollment) => (enrollment.section_id ? sectionNames.value[enrollment.session_id]?.[enrollment.section_id] ?? '…' : t('education.not_placed'));
const sectionWord = computed(() => setup.sectionWord(student.value?.program_id, t('education.word.section')));

const details = computed(() => {
    const value = student.value;
    if (!value) return [];
    const batch = setup.data.value?.batches?.find((item) => item.id === value.batch_id);
    const rows = [
        { label: t('education.fields.program'), text: textIn(setup.program(value.program_id)?.name) },
        batch ? { label: t('education.fields.batch'), text: batch.name } : null,
        { label: t('education.fields.gender'), text: setup.listName('gender', value.gender) || '—' },
        { label: t('education.fields.category'), text: setup.listName('category', value.category_id) || '—' },
        { label: t('education.fields.phone'), text: phoneText(value.phone) || '—', ltr: true },
        { label: t('education.fields.email'), text: value.email || '—', ltr: true },
        { label: t('education.fields.admission_no'), text: value.admission_no || '—', ltr: true },
        { label: t('education.fields.admitted_on'), text: formatDate(value.admitted_on) },
        (setup.data.value?.campuses?.length ?? 0) > 1 ? { label: t('education.fields.unit'), text: setup.campusName(value.unit_id) } : null,
        sensitive.value ? { label: t('education.fields.date_of_birth'), text: value.date_of_birth ? formatDate(value.date_of_birth) : '—', private: true } : null,
        sensitive.value ? { label: t('education.fields.birth_registration_no'), text: value.birth_registration_no || '—', ltr: true, private: true } : null,
    ];
    return rows.filter(Boolean);
});
const ownFields = computed(() => setup.fields('student').filter((field) => student.value?.extra?.[field.key] !== undefined));

// Documents issued to the student (people who issue them only).
const docs = useResource(() => education.documents({ student_id: route.params.id, per_page: 100 }), { immediate: false });
watch(tab, (value) => value === 'documents' && !docs.data.value && docs.reload(), { immediate: true });
const issuing = ref(false);
function issued(made) {
    issuing.value = false;
    router.push({ name: 'education-print', query: { ids: made.map((item) => item.id).join(',') } });
}

// Dialogs.
const editing = ref(false);
const leaving = ref(false);
const moving = ref(false);
const guardianOpen = ref(false);
const guardian = ref(null);

function replaced(data) {
    // Changes answer with the student only; guardians and history come with a fresh read.
    record.data.value = { data: { ...student.value, ...data } };
}

function reload() {
    editing.value = leaving.value = moving.value = guardianOpen.value = false;
    record.reload();
}

function openGuardian(item = null) {
    guardian.value = item;
    guardianOpen.value = true;
}

async function removeGuardian(item) {
    const confirmed = await confirmAction({
        title: t('education.guardian.remove_title', { name: item.name }),
        message: t('education.guardian.remove_text'),
        confirmLabel: t('education.guardian.remove'),
        danger: true,
    });
    if (!confirmed) return;
    try {
        await education.unlinkGuardian(student.value.id, item.id);
        toast.success(t('education.guardian.removed'));
        record.reload();
    } catch (error) {
        toast.error(error.message);
    }
}

// Photo: checked here for size, by the server for size and kind.
const photoInput = ref(null);
const uploading = ref(false);
async function uploadPhoto(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    if (file.size > PHOTO_MAX_KB * 1024) {
        toast.error(t('education.student.photo_too_big', { kb: PHOTO_MAX_KB }));
        return;
    }
    uploading.value = true;
    try {
        const { data } = await education.photo(student.value.id, file);
        replaced(data);
        toast.success(t('education.student.photo_saved'));
    } catch (error) {
        toast.error(error.errors?.photo?.[0] ?? error.message);
    } finally {
        uploading.value = false;
    }
}
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'education-students' }" :icon="ArrowLeft" class="mb-3">{{ t('education.student.back') }}</AppButton>

        <SkeletonRows v-if="record.loading.value && !student" :rows="6" avatar />
        <section v-else-if="record.error.value?.status === 404" class="card">
            <EmptyState :icon="UserX" :title="t('education.student.not_found')" />
        </section>
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />

        <template v-else-if="student">
            <PageHeader :title="student.name" :description="student.name_local ?? ''">
                <template #eyebrow>
                    <span class="mb-3 flex flex-wrap items-center gap-3">
                        <span class="relative">
                            <StudentAvatar :name="student.name" :photo-url="student.photo_url" size="lg" />
                            <button
                                v-if="canEdit"
                                type="button"
                                class="absolute -bottom-1 -end-1 grid size-7 place-items-center rounded-full border border-line bg-surface text-fg-2 shadow-sm hover:text-fg disabled:opacity-50"
                                :aria-label="t('education.student.change_photo')"
                                :title="t('education.student.photo_hint', { kb: PHOTO_MAX_KB })"
                                :disabled="uploading"
                                @click="photoInput?.click()"
                            >
                                <Camera class="size-3.5" aria-hidden="true" />
                            </button>
                            <input ref="photoInput" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" tabindex="-1" @change="uploadPhoto" />
                        </span>
                        <span class="font-mono text-[12.5px] text-muted" dir="ltr">{{ student.code }}</span>
                        <AppBadge :tone="statusTone(student.status)" dot>{{ t(`education.statuses.${student.status}`) }}</AppBadge>
                        <span v-if="student.admission" class="text-[12px] text-faint">{{ t('education.student.from_admission', { number: student.admission.number }) }}</span>
                    </span>
                </template>
                <template v-if="canEdit" #actions>
                    <AppButton size="sm" :icon="PenLine" @click="editing = true">{{ t('education.edit') }}</AppButton>
                    <AppButton v-if="student.status === 'active'" size="sm" variant="danger-soft" :icon="DoorOpen" @click="leaving = true">{{ t('education.student.leave') }}</AppButton>
                </template>
            </PageHeader>

            <div class="mb-5">
                <AppTabs v-model="tab" :tabs="tabs" :label="student.name" />
            </div>

            <div v-if="tab === 'overview'" class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
                <section class="card p-5 sm:p-6">
                    <dl class="grid grid-cols-1 gap-x-6 gap-y-4 text-[13.5px] sm:grid-cols-2">
                        <div v-for="row in details" :key="row.label">
                            <dt class="flex items-center gap-1.5 text-muted">
                                {{ row.label }}
                                <span v-if="row.private" class="rounded bg-subtle px-1.5 text-[10.5px] font-medium uppercase tracking-wide text-faint">{{ t('education.fields.private') }}</span>
                            </dt>
                            <dd class="font-medium text-fg" :dir="row.ltr ? 'ltr' : undefined" :class="row.ltr ? 'text-start' : ''">{{ row.text }}</dd>
                        </div>
                        <div v-if="student.left_on" class="sm:col-span-2">
                            <dt class="text-muted">{{ t('education.student.left_on', { date: formatDate(student.left_on) }) }}</dt>
                            <dd class="font-medium text-fg">{{ student.left_reason }}</dd>
                        </div>
                    </dl>
                    <p v-if="!sensitive" class="mt-5 rounded-xl bg-subtle px-4 py-3 text-[12.5px] text-muted">{{ t('education.student.sensitive_hidden') }}</p>

                    <template v-if="ownFields.length">
                        <h3 class="mb-3 mt-6 border-t border-line pt-5 text-[13px] font-semibold text-fg-2">{{ t('education.student.own_fields') }}</h3>
                        <dl class="grid grid-cols-1 gap-x-6 gap-y-4 text-[13.5px] sm:grid-cols-2">
                            <div v-for="field in ownFields" :key="field.key">
                                <dt class="text-muted">{{ field.label_text }}</dt>
                                <dd class="font-medium text-fg">{{ fieldText(field, student.extra[field.key], (label) => (typeof label === 'string' ? t(`core.${label}`) : textIn(label))) }}</dd>
                            </div>
                        </dl>
                    </template>
                </section>

                <!-- Where the student studies now. -->
                <section class="card h-max p-5">
                    <h2 class="text-[12.5px] font-medium text-muted">{{ t('education.student.now') }}</h2>
                    <template v-if="student.enrollment">
                        <p class="mt-1 text-[17px] font-semibold text-fg">{{ textIn(setup.level(student.enrollment.level_id)?.name) }}</p>
                        <dl class="mt-3 space-y-2 text-[13px]">
                            <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('education.fields.session') }}</dt><dd class="font-medium">{{ setup.sessionText(student.enrollment.session_id) }}</dd></div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-muted">{{ sectionWord }}</dt>
                                <dd class="font-medium" :class="student.enrollment.section_id ? '' : 'text-warn'">{{ sectionName(student.enrollment) }}</dd>
                            </div>
                            <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('education.fields.roll_no') }}</dt><dd class="tabular font-medium">{{ student.enrollment.roll_no ?? '—' }}</dd></div>
                        </dl>
                        <AppButton v-if="canEdit && student.status === 'active'" block size="sm" class="mt-4" :icon="ArrowRightLeft" @click="moving = true">{{ t('education.student.move') }}</AppButton>
                    </template>
                    <p v-else class="mt-1 text-[13.5px] text-muted">{{ t('education.not_placed') }}</p>
                </section>
            </div>

            <section v-else-if="tab === 'guardians'" class="space-y-3">
                <div v-if="canEdit" class="flex justify-end">
                    <AppButton size="sm" variant="primary" :icon="Plus" @click="openGuardian()">{{ t('education.guardian.add_title') }}</AppButton>
                </div>
                <div v-if="!student.guardians.length" class="card">
                    <EmptyState :icon="UsersRound" :title="t('education.guardian.empty_title')" :text="t('education.guardian.empty_text')" compact />
                </div>
                <ul v-else class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <li v-for="item in student.guardians" :key="item.id" class="card flex flex-col gap-3 p-4">
                        <div class="flex items-start gap-3">
                            <StudentAvatar :name="item.name" size="md" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-[14.5px] font-semibold text-fg">{{ item.name }}</p>
                                <p class="text-[12.5px] text-muted">{{ setup.listName('relation', item.relation) }}<template v-if="item.occupation"> · {{ item.occupation }}</template></p>
                            </div>
                            <AppBadge v-if="item.is_primary" tone="brand">{{ t('education.guardian.primary') }}</AppBadge>
                        </div>
                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-[13px]">
                            <a v-if="item.phone" :href="`tel:${item.phone}`" class="inline-flex items-center gap-1.5 text-brand-text hover:underline" dir="ltr"><Phone class="size-3.5" aria-hidden="true" />{{ phoneText(item.phone) }}</a>
                            <a v-if="item.email" :href="`mailto:${item.email}`" class="inline-flex items-center gap-1.5 text-brand-text hover:underline" dir="ltr"><Mail class="size-3.5" aria-hidden="true" />{{ item.email }}</a>
                            <span v-if="sensitive && item.national_id" class="text-muted">{{ t('education.guardian.national_id') }}: <span dir="ltr" class="tabular">{{ item.national_id }}</span></span>
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            <AppBadge v-if="item.can_pick_up" tone="outline">{{ t('education.guardian.can_pick_up') }}</AppBadge>
                            <AppBadge v-if="item.receives_notices" tone="outline">{{ t('education.guardian.receives_notices') }}</AppBadge>
                            <AppBadge v-if="item.has_portal" tone="ok">{{ t('education.guardian.portal') }}</AppBadge>
                        </div>
                        <div v-if="canEdit" class="flex gap-2 border-t border-line pt-3">
                            <AppButton size="sm" variant="ghost" :icon="PenLine" @click="openGuardian(item)">{{ t('education.edit') }}</AppButton>
                            <AppButton size="sm" variant="danger-soft" :icon="Trash2" :aria-label="t('education.guardian.remove_named', { name: item.name })" @click="removeGuardian(item)">{{ t('education.guardian.remove') }}</AppButton>
                        </div>
                    </li>
                </ul>
            </section>

            <section v-else-if="tab === 'documents'" class="space-y-3">
                <div class="flex justify-end">
                    <AppButton size="sm" variant="primary" :icon="FileBadge" @click="issuing = true">{{ t('education.documents.issue') }}</AppButton>
                </div>
                <div class="card">
                    <p v-if="docs.loading.value && !docs.data.value" class="px-5 py-6 text-[13px] text-muted">…</p>
                    <p v-else-if="!(docs.data.value?.data ?? []).length" class="px-5 py-6 text-[13px] text-muted">{{ t('education.student_documents.empty') }}</p>
                    <ul v-else class="divide-y divide-line">
                        <li v-for="item in docs.data.value.data" :key="item.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                            <span class="min-w-0 flex-1">
                                <span class="block text-[14px] font-medium text-fg">{{ item.title_text }}</span>
                                <span class="block text-[12.5px] text-muted"><span class="font-mono" dir="ltr">{{ item.number }}</span> · {{ t('education.documents.issued_on', { date: formatDate(item.issued_on) }) }}<template v-if="item.valid_until"> · {{ t('education.documents.valid_until', { date: formatDate(item.valid_until) }) }}</template></span>
                            </span>
                            <AppBadge :tone="{ valid: 'ok', revoked: 'bad', expired: 'warn' }[item.status]" dot>{{ t(`education.document_statuses.${item.status}`) }}</AppBadge>
                            <AppButton size="sm" variant="ghost" :icon="Printer" :to="{ name: 'education-print', query: { ids: item.id } }">{{ t('education.documents.print') }}</AppButton>
                        </li>
                    </ul>
                </div>
            </section>

            <section v-else class="card">
                <p v-if="!student.enrollments.length" class="px-5 py-6 text-[13px] text-muted">{{ t('education.student.history_empty') }}</p>
                <ol v-else class="divide-y divide-line">
                    <li v-for="item in student.enrollments" :key="item.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14px] font-medium text-fg">{{ setup.sessionText(item.session_id) }} · {{ textIn(setup.level(item.level_id)?.name) }}</span>
                            <span class="block text-[12.5px] text-muted">
                                {{ sectionWord }}: {{ sectionName(item) }}<template v-if="item.roll_no"> · {{ t('education.student.roll', { roll: item.roll_no }) }}</template>
                                · {{ formatDate(item.started_on) }}<template v-if="item.ended_on"> – {{ formatDate(item.ended_on) }}</template>
                            </span>
                        </span>
                        <AppBadge :tone="item.status === 'active' ? 'brand' : 'neutral'">{{ t(`education.enrollment_statuses.${item.status}`) }}</AppBadge>
                    </li>
                </ol>
            </section>

            <StudentDialog :open="editing" :student="student" @close="editing = false" @saved="(data) => { editing = false; replaced(data); }" @conflict="reload" />
            <LeaveDialog :open="leaving" :student="student" :education="education" @close="leaving = false" @done="reload" @conflict="reload" />
            <PlaceDialog :open="moving" :name="student.name" :enrollment="student.enrollment" :education="education" @close="moving = false" @done="reload" @conflict="reload" />
            <IssueDialog :open="issuing" :students="[{ id: student.id, name: student.name }]" :education="education" @close="issuing = false" @issued="issued" />
            <GuardianDialog :open="guardianOpen" :student-id="student.id" :guardian="guardian" :education="education" @close="guardianOpen = false" @saved="reload" @conflict="reload" />
        </template>
    </div>
</template>
