<script setup>
import { computed, ref, watch } from 'vue';
import { BadgeCheck, CalendarClock, FileText, Mail, Paperclip, Phone, Star, UserMinus, X } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDrawer from '@/components/AppDrawer.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { confirmAction } from '@/lib/dialogs';
import { formatDateTime } from '@/lib/format';
import { i18n, t } from '@/lib/i18n';
import { toast } from '@/lib/toast';
import FeedbackDialog from './FeedbackDialog.vue';
import HireDialog from './HireDialog.vue';
import InterviewDialog from './InterviewDialog.vue';
import OfferPanel from './OfferPanel.vue';
import { applicationTone, nameIn, recommendationTone } from '../lib';

/**
 * One application: the person and their note, CVs (each opens through a
 * short signed link, fetched again whenever the drawer opens), the
 * history, interviews and feedback (an interviewer sees only their own).
 * People who manage recruitment move it, rate it, schedule interviews,
 * reject or mark it withdrawn; those who may hire make the person an
 * employee. What one may do comes from the server ("can").
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    applicationId: { type: String, default: null },
    opening: { type: Object, default: null },
    employmentTypes: { type: Array, default: () => [] },
    calls: { type: Object, required: true },
});
const emit = defineEmits(['close', 'changed']);

const item = ref(null);
const error = ref(null);
const busy = ref(false);
const scheduling = ref(false);
const hiring = ref(false);
const giving = ref(null); // the interview one gives feedback on

async function load() {
    error.value = null;
    try {
        item.value = (await props.calls.application(props.applicationId)).data;
    } catch (failure) {
        error.value = failure;
    }
}
watch(() => [props.open, props.applicationId], ([open]) => {
    item.value = null;
    if (open && props.applicationId) load();
});

const can = computed(() => item.value?.can ?? {});
const stageName = (key) => nameIn(item.value?.stages?.find((stage) => stage.key === key)?.name, i18n.locale) || key;
const stageIndex = computed(() => (item.value?.stages ?? []).findIndex((stage) => stage.key === item.value?.stage));
const next = computed(() => item.value?.stages?.[stageIndex.value + 1] ?? null);

async function update(body, done) {
    busy.value = true;
    try {
        item.value = (await props.calls.updateApplication(item.value.id, { base_version: item.value.version, ...body })).data;
        if (done) toast.success(done);
        emit('changed');
    } catch (failure) {
        if (failure.code === 'version_conflict') load();
        toast.error(failure.message);
    } finally {
        busy.value = false;
    }
}

function moveTo(stage) {
    if (stage && stage !== item.value.stage) update({ stage }, t('recruitment.drawer.moved', { stage: stageName(stage) }));
}

async function end(step) {
    const confirmed = await confirmAction({
        title: t(`recruitment.drawer.${step}_title`, { name: item.value.name }),
        message: t(`recruitment.drawer.${step}_text`),
        confirmLabel: t(`recruitment.drawer.${step}`),
        reason: 'required',
        danger: true,
        ...(step === 'reject' ? { checkbox: { label: t('recruitment.drawer.tell'), checked: true } } : {}),
    });
    if (!confirmed) return;
    busy.value = true;
    try {
        item.value = (await props.calls.endApplication(item.value.id, step, { base_version: item.value.version, reason: confirmed.reason, tell: !!confirmed.checked })).data;
        toast.success(t(`recruitment.drawer.${step}_done`));
        emit('changed');
    } catch (failure) {
        if (failure.code === 'version_conflict') load();
        toast.error(failure.message);
    } finally {
        busy.value = false;
    }
}

async function attach(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    busy.value = true;
    try {
        item.value = (await props.calls.addFile(item.value.id, file)).data;
        toast.success(t('recruitment.drawer.file_added'));
    } catch (failure) {
        toast.error(failure.errors?.cv?.[0] ?? failure.errors?.file?.[0] ?? failure.message);
    } finally {
        busy.value = false;
    }
}

async function cancelInterview(interview) {
    const confirmed = await confirmAction({ title: t('recruitment.drawer.cancel_interview_title'), message: interview.local, confirmLabel: t('recruitment.drawer.cancel_interview'), danger: true });
    if (!confirmed) return;
    try {
        await props.calls.cancelInterview(interview.id, { base_version: interview.version });
        load();
    } catch (failure) {
        toast.error(failure.message);
    }
}

const mineOn = (interview) => interview.feedback.find((entry) => entry.mine) ?? null;
const amInterviewer = (interview) => can.value.feedback && interview.status !== 'cancelled';
function afterScheduled(data) {
    scheduling.value = false;
    item.value = data;
    emit('changed');
}
function afterHired(data) {
    hiring.value = false;
    item.value = data;
    emit('changed');
}
function afterFeedback() {
    giving.value = null;
    load();
    emit('changed');
}
</script>

<template>
    <AppDrawer :open="open" :title="item?.name ?? t('recruitment.drawer.title')" width="sm:max-w-[620px]" @close="emit('close')">
        <ErrorState v-if="error" compact :error="error" @retry="load" />
        <SkeletonRows v-else-if="!item" :rows="5" />
        <div v-else class="space-y-5">
            <header class="space-y-2">
                <p class="flex flex-wrap items-center gap-2">
                    <AppBadge :tone="applicationTone(item.status)">{{ t(`recruitment.status.${item.status}`) }}</AppBadge>
                    <AppBadge v-if="item.status === 'active'" tone="outline">{{ stageName(item.stage) }}</AppBadge>
                    <span class="text-[12.5px] text-muted">{{ t(`recruitment.sources.${item.source}`) }} · {{ formatDateTime(item.applied_at) }}</span>
                </p>
                <p v-if="item.email" class="flex flex-wrap gap-x-4 gap-y-1 text-[13.5px]">
                    <a :href="`mailto:${item.email}`" class="inline-flex items-center gap-1.5 text-brand" dir="ltr"><Mail class="size-4" aria-hidden="true" />{{ item.email }}</a>
                    <a v-if="item.phone" :href="`tel:${item.phone}`" class="inline-flex items-center gap-1.5 text-brand" dir="ltr"><Phone class="size-4" aria-hidden="true" />{{ item.phone }}</a>
                </p>
                <p v-if="item.employee" class="rounded-lg bg-ok/10 px-3 py-2 text-[13px] text-ok">{{ t('recruitment.drawer.is_employee', { name: item.employee.name, code: item.employee.code }) }}</p>
                <p v-if="item.reject_reason" class="rounded-lg bg-subtle px-3 py-2 text-[13px]">{{ t('recruitment.drawer.reason') }}: {{ item.reject_reason }}</p>
            </header>

            <section v-if="can.manage" class="grid gap-3 rounded-xl border border-line p-3 sm:grid-cols-2">
                <label class="grid gap-1 text-[12.5px] font-medium text-fg-2">
                    {{ t('recruitment.drawer.stage') }}
                    <select class="field-input" :value="item.stage" :disabled="busy" @change="moveTo($event.target.value)">
                        <option v-for="stage in item.stages" :key="stage.key" :value="stage.key">{{ nameIn(stage.name, i18n.locale) }}</option>
                    </select>
                </label>
                <div class="grid gap-1 text-[12.5px] font-medium text-fg-2">
                    {{ t('recruitment.drawer.rating') }}
                    <div class="flex gap-0.5" role="radiogroup" :aria-label="t('recruitment.drawer.rating')">
                        <button v-for="value in 5" :key="value" type="button" role="radio" :aria-checked="item.rating === value" :aria-label="t('recruitment.feedback.stars', { count: value })"
                            class="grid size-9 place-items-center rounded-lg hover:bg-subtle" :disabled="busy" @click="update({ rating: item.rating === value ? null : value })">
                            <Star class="size-5" :class="value <= (item.rating ?? 0) ? 'fill-warn text-warn' : 'text-muted'" aria-hidden="true" />
                        </button>
                    </div>
                </div>
            </section>

            <section v-if="item.cover_note" :aria-label="t('recruitment.drawer.note')">
                <h3 class="mb-1 text-[13px] font-semibold text-fg-2">{{ t('recruitment.drawer.note') }}</h3>
                <p class="whitespace-pre-line text-[14px]">{{ item.cover_note }}</p>
            </section>

            <section :aria-label="t('recruitment.drawer.files')">
                <h3 class="mb-2 text-[13px] font-semibold text-fg-2">{{ t('recruitment.drawer.files') }}</h3>
                <div class="flex flex-wrap items-center gap-2">
                    <a v-for="file in item.files" :key="file.id" :href="file.url" target="_blank" rel="noopener" class="inline-flex max-w-full items-center gap-1.5 rounded-lg bg-subtle px-3 py-1.5 text-[13px]">
                        <FileText class="size-4 shrink-0" aria-hidden="true" /><span class="truncate">{{ file.name }}</span>
                    </a>
                    <span v-if="!item.files.length" class="text-[12.5px] text-muted">{{ t('recruitment.drawer.no_files') }}</span>
                    <label v-if="can.manage" class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-line px-3 py-1.5 text-[13px] font-medium hover:bg-subtle">
                        <Paperclip class="size-4" aria-hidden="true" />{{ t('recruitment.drawer.add_file') }}
                        <input type="file" class="sr-only" accept=".pdf,.doc,.docx" :disabled="busy" @change="attach" />
                    </label>
                </div>
            </section>

            <section :aria-label="t('recruitment.drawer.interviews')">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <h3 class="text-[13px] font-semibold text-fg-2">{{ t('recruitment.drawer.interviews') }}</h3>
                    <AppButton v-if="can.manage" size="sm" :icon="CalendarClock" @click="scheduling = true">{{ t('recruitment.drawer.schedule') }}</AppButton>
                </div>
                <p v-if="!item.interviews.length" class="text-[12.5px] text-muted">{{ t('recruitment.drawer.no_interviews') }}</p>
                <ol v-else class="space-y-2">
                    <li v-for="interview in item.interviews" :key="interview.id" class="rounded-xl border border-line p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-[13.5px] font-medium">{{ interview.local }} · {{ t(`recruitment.modes.${interview.mode}`) }}</p>
                            <AppBadge :tone="interview.status === 'done' ? 'ok' : interview.status === 'cancelled' ? 'neutral' : 'warn'">{{ t(`recruitment.interview_status.${interview.status}`) }}</AppBadge>
                        </div>
                        <p class="text-[12.5px] text-muted">
                            <template v-if="interview.place">{{ interview.place }} · </template>{{ interview.interviewers.map((person) => person.name).join(', ') }}
                        </p>
                        <ul v-if="interview.feedback.length" class="mt-2 space-y-1.5">
                            <li v-for="entry in interview.feedback" :key="entry.by" class="rounded-lg bg-subtle px-3 py-2 text-[13px]">
                                <span class="font-medium">{{ entry.by }}</span> · {{ '★'.repeat(entry.score) }}<span class="text-muted">{{ '★'.repeat(5 - entry.score) }}</span>
                                <AppBadge class="ms-1" :tone="recommendationTone(entry.recommendation)">{{ t(`recruitment.recommendations.${entry.recommendation}`) }}</AppBadge>
                                <p v-if="entry.note" class="mt-0.5 whitespace-pre-line text-muted">{{ entry.note }}</p>
                            </li>
                        </ul>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <AppButton v-if="amInterviewer(interview)" size="sm" variant="primary" :icon="Star" @click="giving = interview">{{ mineOn(interview) ? t('recruitment.drawer.change_feedback') : t('recruitment.drawer.give_feedback') }}</AppButton>
                            <AppButton v-if="can.manage && interview.status === 'scheduled'" size="sm" variant="ghost" :icon="X" @click="cancelInterview(interview)">{{ t('recruitment.drawer.cancel_interview') }}</AppButton>
                        </div>
                    </li>
                </ol>
            </section>

            <OfferPanel v-if="can.contact" :application="item" :calls="calls" @changed="load(); emit('changed')" />

            <section v-if="item.history.length" :aria-label="t('recruitment.drawer.history')">
                <h3 class="mb-2 text-[13px] font-semibold text-fg-2">{{ t('recruitment.drawer.history') }}</h3>
                <ol class="space-y-1 border-s border-line ps-4 text-[12.5px]">
                    <li v-for="(move, index) in item.history" :key="index">
                        <span class="font-medium">{{ move.status === 'active' ? stageName(move.to) : t(`recruitment.status.${move.status}`) }}</span>
                        <span class="text-muted"> · {{ move.by ?? t('recruitment.drawer.by_candidate') }} · {{ formatDateTime(move.at) }}</span>
                        <span v-if="move.note" class="block text-muted">“{{ move.note }}”</span>
                    </li>
                </ol>
            </section>
        </div>
        <template v-if="item && (can.manage || can.hire)" #footer>
            <div class="flex w-full flex-wrap justify-between gap-2">
                <div class="flex flex-wrap gap-2">
                    <AppButton v-if="can.manage" variant="danger-soft" :icon="X" :loading="busy" @click="end('reject')">{{ t('recruitment.drawer.reject') }}</AppButton>
                    <AppButton v-if="can.manage" variant="ghost" :icon="UserMinus" :loading="busy" @click="end('withdraw')">{{ t('recruitment.drawer.withdraw') }}</AppButton>
                </div>
                <div class="flex flex-wrap gap-2">
                    <AppButton v-if="can.manage && next" :loading="busy" @click="moveTo(next.key)">{{ t('recruitment.drawer.move_to', { stage: nameIn(next.name, i18n.locale) }) }}</AppButton>
                    <AppButton v-if="can.hire" variant="primary" :icon="BadgeCheck" @click="hiring = true">{{ t('recruitment.drawer.hire') }}</AppButton>
                </div>
            </div>
        </template>
        <InterviewDialog :open="scheduling" :application="item" :calls="calls" @close="scheduling = false" @scheduled="afterScheduled" />
        <HireDialog :open="hiring" :application="item" :opening="opening" :employment-types="employmentTypes" :start-on="item?.offers?.find((offer) => offer.status === 'accepted')?.start_on ?? null" :calls="calls" @close="hiring = false" @hired="afterHired" />
        <FeedbackDialog :open="giving !== null" :interview="giving" :name="item?.name ?? ''" :given="giving ? mineOn(giving) : null" :calls="calls" @close="giving = null" @saved="afterFeedback" />
    </AppDrawer>
</template>
