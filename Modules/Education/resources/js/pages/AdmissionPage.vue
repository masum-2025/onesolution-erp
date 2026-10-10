<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, ArrowRight, FileX, Handshake, Mail, PenLine, Phone, UserCheck } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatDateTime } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { educationApi } from '../api';
import { useEducationSetup } from '../setup';
import { ADMISSION_NEXT, admissionTone, canAdmit, fieldText, phoneText } from '../lib';
import AdmitDialog from '../components/AdmitDialog.vue';
import ApplicationDialog from '../components/ApplicationDialog.vue';
import StudentAvatar from '../components/StudentAvatar.vue';

/**
 * One application: the applicant, the place applied for, guardians and own
 * fields; the next decisions (test, offer, not admitted, withdrawn, each
 * with a note) and admitting, which makes the student and opens them.
 */
const org = currentOrganization();
const education = educationApi(org.id);
const setup = useEducationSetup();
const route = useRoute();
const router = useRouter();

const record = useResource(() => education.admission(route.params.id));
const admission = computed(() => record.data.value?.data ?? null);
const applicant = computed(() => admission.value?.applicant ?? {});
const open = computed(() => admission.value && canAdmit(admission.value.status));
const placed = computed(() => Boolean(admission.value?.program_id && admission.value?.level_id && admission.value?.session_id));
const nextSteps = computed(() => ADMISSION_NEXT[admission.value?.status] ?? []);
const ownFields = computed(() => setup.fields('admission').filter((field) => applicant.value.extra?.[field.key] !== undefined));

const editing = ref(false);
const admitting = ref(false);
const stepping = ref(null);
const note = ref('');
const saving = ref(false);

function openStep(step) {
    note.value = '';
    stepping.value = step;
}

async function sendStep() {
    saving.value = true;
    try {
        const { data } = await education.admissionStep(admission.value.id, { base_version: admission.value.version, status: stepping.value, note: note.value.trim() || null });
        record.data.value = { data };
        toast.success(t('education.admission.step_done', { status: t(`education.admission_statuses.${data.status}`) }));
        stepping.value = null;
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('education.conflict'));
            stepping.value = null;
            record.reload();
            return;
        }
        toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

function admitted(student) {
    admitting.value = false;
    router.push({ name: 'education-student', params: { id: student.id } });
}

function reload() {
    editing.value = admitting.value = false;
    record.reload();
}

const closing = computed(() => ['rejected', 'withdrawn'].includes(stepping.value));
const stepTitle = computed(() => {
    if (stepping.value === 'rejected') return t('education.admission.reject_title', { name: applicant.value.name });
    if (stepping.value === 'withdrawn') return t('education.admission.withdraw_title', { name: applicant.value.name });
    return stepping.value ? t('education.admission.step_title', { step: t(`education.admission.steps.${stepping.value}`), name: applicant.value.name }) : '';
});
const labelOf = (label) => (typeof label === 'string' ? t(`core.${label}`) : textIn(label));
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'education-admissions' }" :icon="ArrowLeft" class="mb-3">{{ t('education.admission.back') }}</AppButton>

        <SkeletonRows v-if="record.loading.value && !admission" :rows="6" avatar />
        <section v-else-if="record.error.value?.status === 404" class="card">
            <EmptyState :icon="FileX" :title="t('education.admission.not_found')" />
        </section>
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />

        <template v-else-if="admission">
            <PageHeader :title="applicant.name || '—'" :description="applicant.name_local ?? ''">
                <template #eyebrow>
                    <span class="mb-3 flex flex-wrap items-center gap-3">
                        <StudentAvatar :name="applicant.name ?? '?'" size="lg" />
                        <span class="font-mono text-[12.5px] text-muted" dir="ltr">{{ admission.number }}</span>
                        <AppBadge :tone="admissionTone(admission.status)" dot>{{ t(`education.admission_statuses.${admission.status}`) }}</AppBadge>
                        <span class="inline-flex items-center gap-1 text-[12px] text-faint">
                            <Handshake v-if="admission.source === 'crm'" class="size-3.5" aria-hidden="true" />
                            {{ admission.source === 'crm' ? t('education.admissions.from_crm') : t('education.admissions.from_direct') }} · {{ formatDate(admission.created_at) }}
                        </span>
                    </span>
                </template>
                <template v-if="open" #actions>
                    <AppButton size="sm" :icon="PenLine" @click="editing = true">{{ t('education.admission.edit') }}</AppButton>
                    <AppButton v-for="step in nextSteps" :key="step" size="sm" :variant="['rejected', 'withdrawn'].includes(step) ? 'danger-soft' : 'secondary'" @click="openStep(step)">
                        {{ t(`education.admission.steps.${step}`) }}
                    </AppButton>
                    <AppButton size="sm" variant="primary" :icon="UserCheck" :disabled="!placed" @click="admitting = true">{{ t('education.admission.admit') }}</AppButton>
                </template>
            </PageHeader>

            <!-- What happened: admitted (open the student), closed, or a place still to choose. -->
            <div v-if="admission.student_id" class="mb-5 flex flex-col gap-3 rounded-2xl border border-ok/30 bg-ok-soft px-4 py-3.5 sm:flex-row sm:items-center">
                <UserCheck class="size-5 shrink-0 text-ok" aria-hidden="true" />
                <p class="flex-1 text-[13.5px] font-medium text-fg">{{ t(`education.admission_statuses.admitted`) }}<template v-if="admission.decided_at"> · {{ formatDateTime(admission.decided_at) }}</template></p>
                <AppButton size="sm" :to="{ name: 'education-student', params: { id: admission.student_id } }" :icon-end="ArrowRight">{{ t('education.admission.open_student') }}</AppButton>
            </div>
            <p v-else-if="!open" class="mb-5 rounded-xl bg-subtle px-4 py-3 text-[13px] text-muted">{{ t('education.admission.closed', { status: t(`education.admission_statuses.${admission.status}`) }) }}</p>
            <p v-else-if="!placed" class="mb-5 rounded-xl bg-warn-soft px-4 py-3 text-[13px] text-warn">{{ t('education.admission.place_needed') }}</p>

            <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
                <section class="card p-5 sm:p-6">
                    <h2 class="mb-4 text-[13.5px] font-semibold text-fg">{{ t('education.admission.applicant') }}</h2>
                    <dl class="grid grid-cols-1 gap-x-6 gap-y-4 text-[13.5px] sm:grid-cols-2">
                        <div><dt class="text-muted">{{ t('education.fields.gender') }}</dt><dd class="font-medium text-fg">{{ setup.listName('gender', applicant.gender) || '—' }}</dd></div>
                        <div v-if="setup.can('view_sensitive')"><dt class="text-muted">{{ t('education.fields.date_of_birth') }}</dt><dd class="font-medium text-fg">{{ applicant.date_of_birth ? formatDate(applicant.date_of_birth) : '—' }}</dd></div>
                        <div><dt class="text-muted">{{ t('education.fields.phone') }}</dt><dd class="font-medium text-fg" dir="ltr">{{ phoneText(applicant.phone) || '—' }}</dd></div>
                        <div><dt class="text-muted">{{ t('education.fields.email') }}</dt><dd class="font-medium text-fg" dir="ltr">{{ applicant.email || '—' }}</dd></div>
                        <div><dt class="text-muted">{{ t('education.admission.previous_school') }}</dt><dd class="font-medium text-fg">{{ applicant.previous_school || '—' }}</dd></div>
                        <div v-for="field in ownFields" :key="field.key"><dt class="text-muted">{{ field.label_text }}</dt><dd class="font-medium text-fg">{{ fieldText(field, applicant.extra[field.key], labelOf) }}</dd></div>
                    </dl>
                    <template v-if="admission.note">
                        <h3 class="mb-1 mt-6 border-t border-line pt-5 text-[13px] font-semibold text-fg-2">{{ t('education.admission.note') }}</h3>
                        <p class="whitespace-pre-line text-[13.5px] text-fg">{{ admission.note }}</p>
                    </template>
                </section>

                <div class="space-y-5">
                    <section class="card p-5">
                        <h2 class="text-[12.5px] font-medium text-muted">{{ t('education.admission.place') }}</h2>
                        <template v-if="admission.level_id">
                            <p class="mt-1 text-[16px] font-semibold text-fg">{{ setup.levelText(admission.level_id) }}</p>
                            <p class="text-[13px] text-muted">{{ setup.sessionText(admission.session_id) }}</p>
                        </template>
                        <p v-else class="mt-1 text-[13.5px] text-warn">{{ t('education.admissions.no_place') }}</p>
                    </section>
                    <section class="card p-5">
                        <h2 class="mb-3 text-[12.5px] font-medium text-muted">{{ t('education.admission.guardians') }}</h2>
                        <p v-if="!(applicant.guardians ?? []).length" class="text-[13px] text-muted">{{ t('education.new_student.no_guardians') }}</p>
                        <ul class="space-y-3">
                            <li v-for="(guardian, index) in applicant.guardians ?? []" :key="index" class="text-[13.5px]">
                                <p class="font-medium text-fg">{{ guardian.name || '—' }} <span class="font-normal text-muted">· {{ setup.listName('relation', guardian.relation) }}</span></p>
                                <p class="flex flex-wrap gap-x-3 text-[12.5px]">
                                    <a v-if="guardian.phone" :href="`tel:${guardian.phone}`" class="inline-flex items-center gap-1 text-brand-text hover:underline" dir="ltr"><Phone class="size-3" aria-hidden="true" />{{ phoneText(guardian.phone) }}</a>
                                    <a v-if="guardian.email" :href="`mailto:${guardian.email}`" class="inline-flex items-center gap-1 text-brand-text hover:underline" dir="ltr"><Mail class="size-3" aria-hidden="true" />{{ guardian.email }}</a>
                                </p>
                            </li>
                        </ul>
                    </section>
                </div>
            </div>

            <ApplicationDialog :open="editing" :admission="admission" @close="editing = false" @saved="(data) => { editing = false; record.data.value = { data }; }" @conflict="reload" />
            <AdmitDialog :open="admitting" :admission="admission" :education="education" @close="admitting = false" @done="admitted" @conflict="reload" />
            <AppDialog :open="stepping !== null" :title="stepTitle" :tone="closing ? 'bad' : 'brand'" @close="stepping = null">
                <p v-if="closing" class="mb-4 text-[13px] text-muted">{{ t('education.admission.reject_text') }}</p>
                <AppField v-slot="{ id }" :label="t('education.admission.step_note')" :hint="t('education.admission.step_note_hint')" optional>
                    <textarea :id="id" v-model="note" rows="3" class="field-input" maxlength="500" />
                </AppField>
                <template #footer>
                    <AppButton variant="ghost" @click="stepping = null">{{ t('education.cancel') }}</AppButton>
                    <AppButton :variant="closing ? 'danger' : 'primary'" :loading="saving" @click="sendStep">{{ stepping ? t(`education.admission.steps.${stepping}`) : '' }}</AppButton>
                </template>
            </AppDialog>
        </template>
    </div>
</template>
