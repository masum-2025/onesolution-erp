<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Check, CircleAlert, ListChecks, RotateCcw, Save, SearchX, Send, Undo2, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { currentOrganization, session } from '@/lib/session';
import { confirmAction } from '@/lib/dialogs';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { educationApi } from '../api';
import { useEducationSetup } from '../setup';
import { decisionTotals, promotionTone } from '../lib';
import StudentAvatar from '../components/StudentAvatar.vue';

/**
 * One promotion list: a decision and the next section for every student,
 * changed while it is a draft (by people who make lists), then handed in:
 * applied at once, or by someone else who approves it (or sends it back
 * with a note), and undone for a while after. Every step asks once more.
 */
const org = currentOrganization();
const education = educationApi(org.id);
const setup = useEducationSetup();
const route = useRoute();

const record = useResource(() => education.promotion(route.params.id));
const batch = computed(() => record.data.value?.data ?? null);
const level = computed(() => setup.level(batch.value?.level_id));
const isLast = computed(() => level.value && !level.value.next_level_id);
const editable = computed(() => batch.value?.status === 'draft' && setup.can('promote'));
const me = computed(() => session.me?.user?.id);
const mine = computed(() => batch.value && [batch.value.created_by, batch.value.submitted_by].includes(me.value));

// Local edits of the lines: line id -> { decision, to_section_id, reason }.
const edits = reactive({});
const errors = ref({});
watch(batch, () => {
    Object.keys(edits).forEach((key) => delete edits[key]);
    errors.value = {};
});
const lineOf = (line) => ({ decision: line.decision, to_section_id: line.to_section_id, reason: line.reason ?? '', ...edits[line.id] });
const lines = computed(() => (batch.value?.lines ?? []).map((line) => ({ ...line, ...lineOf(line) })));
const changed = computed(() => Object.keys(edits).length > 0);
const totals = computed(() => decisionTotals(lines.value));

function change(line, values) {
    const next = { ...lineOf(line), ...values };
    if (next.decision !== 'promote' && next.decision !== 'repeat') next.to_section_id = null;
    // A section of the other class is no place for the new decision.
    if (values.decision && next.to_section_id && !sectionsFor(next.decision).some((section) => section.id === next.to_section_id)) next.to_section_id = null;
    edits[line.id] = next;
}

// Sections of the next session, for the class each decision leads to.
const nextSections = ref([]);
watch(
    () => batch.value?.to_session_id,
    async (sessionId) => {
        nextSections.value = [];
        if (!sessionId) return;
        try {
            nextSections.value = (await education.list('sections', { session_id: sessionId })).data.filter((section) => section.is_active);
        } catch {
            nextSections.value = [];
        }
    },
);
const sectionsFor = (decision) => {
    const levelId = decision === 'promote' ? level.value?.next_level_id : decision === 'repeat' ? batch.value?.level_id : null;
    return levelId ? nextSections.value.filter((section) => section.level_id === levelId) : [];
};
const decisions = computed(() => (isLast.value ? ['graduate', 'repeat', 'leave'] : ['promote', 'repeat', 'leave']));
const needsReason = (line) => line.decision === 'leave' || (line.decision === 'repeat' && line.repeats >= (setup.data.value?.rules?.max_repeats ?? Infinity));

const saving = ref(false);
async function save() {
    const missing = {};
    lines.value.forEach((line) => {
        if (needsReason(line) && !line.reason.trim()) missing[line.id] = t('education.promotion.reason_needed');
    });
    if (Object.keys(missing).length) {
        errors.value = missing;
        return;
    }
    const sent = Object.keys(edits);
    saving.value = true;
    try {
        const body = { base_version: batch.value.version, lines: sent.map((id) => ({ line_id: id, decision: edits[id].decision, to_section_id: edits[id].to_section_id || null, reason: edits[id].reason?.trim() || null })) };
        const { data } = await education.decide(batch.value.id, body);
        record.data.value = { data };
        toast.success(t('education.promotion.saved'));
    } catch (error) {
        handle(error, sent);
    } finally {
        saving.value = false;
    }
}

// Server errors come as lines.<index>.<field>: show them on the line they belong to.
function handle(error, sent = []) {
    if (error.code === 'version_conflict') {
        toast.error(t('education.conflict'));
        record.reload();
        return;
    }
    const found = {};
    for (const [key, messages] of Object.entries(error.errors ?? {})) {
        const match = /^lines\.(\d+)\./.exec(key);
        if (match && sent[Number(match[1])]) found[sent[Number(match[1])]] = messages[0];
    }
    errors.value = found;
    toast.error(Object.keys(found).length ? t('education.new_student.check') : error.message);
}

// Everyone going up (or repeating) into one section of the next class.
const placeAll = ref('');
async function applyPlaceAll() {
    saving.value = true;
    try {
        const { data } = await education.decide(batch.value.id, { base_version: batch.value.version, section_id: placeAll.value });
        record.data.value = { data };
        placeAll.value = '';
        toast.success(t('education.promotion.saved'));
    } catch (error) {
        handle(error);
    } finally {
        saving.value = false;
    }
}

// Steps of the list.
const stepping = ref(null);
async function step(name, extra = {}) {
    const number = batch.value.number;
    const count = lines.value.length;
    const approval = setup.data.value?.rules?.promotion_approval;
    const ask = {
        submit: approval
            ? { title: t('education.promotion.submit_title_approval', { number }), message: t('education.promotion.submit_text_approval'), confirmLabel: t('education.promotion.submit') }
            : { title: t('education.promotion.submit_title_apply', { number }), message: t('education.promotion.submit_text_apply', { count, days: setup.data.value?.rules?.promotion_undo_days ?? 0 }), confirmLabel: t('education.promotion.submit_apply') },
        approve: { title: t('education.promotion.approve_title', { number }), message: t('education.promotion.approve_text', { count }), confirmLabel: t('education.promotion.approve') },
        cancel: { title: t('education.promotion.cancel_title', { number }), message: t('education.promotion.cancel_text'), confirmLabel: t('education.promotion.cancel'), danger: true },
        undo: { title: t('education.promotion.undo_title', { number }), message: t('education.promotion.undo_text'), confirmLabel: t('education.promotion.undo'), danger: true },
    }[name];
    if (ask && !(await confirmAction(ask))) return;
    stepping.value = name;
    try {
        const result = await education.promotionStep(batch.value.id, name, { base_version: batch.value.version, ...extra });
        record.data.value = { data: result.data };
        toast.success(result.message ?? t(`education.promotion_statuses.${result.data.status}`));
        rejecting.value = false;
    } catch (error) {
        handle(error);
    } finally {
        stepping.value = null;
    }
}

const rejecting = ref(false);
const rejectNote = ref('');
function openReject() {
    rejectNote.value = '';
    rejecting.value = true;
}

const people = (id) => batch.value?.people?.[id] ?? '';
const sectionName = (id) => nextSections.value.find((section) => section.id === id)?.name ?? '';
const toneOf = { promote: 'ok', graduate: 'brand', repeat: 'warn', leave: 'bad' };
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'education-promotions' }" :icon="ArrowLeft" class="mb-3">{{ t('education.promotion.back') }}</AppButton>

        <SkeletonRows v-if="record.loading.value && !batch" :rows="8" avatar />
        <section v-else-if="record.error.value?.status === 404" class="card">
            <EmptyState :icon="SearchX" :title="t('education.promotion.not_found')" />
        </section>
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />

        <template v-else-if="batch">
            <PageHeader :title="setup.levelText(batch.level_id)" :description="t('education.promotions.from_to', { from: setup.sessionText(batch.from_session_id), to: setup.sessionText(batch.to_session_id) })">
                <template #eyebrow>
                    <span class="mb-2 flex flex-wrap items-center gap-2.5">
                        <span class="grid size-9 place-items-center rounded-xl bg-brand-soft text-brand-text" aria-hidden="true"><ListChecks class="size-[18px]" /></span>
                        <span class="font-mono text-[12.5px] text-muted" dir="ltr">{{ batch.number }}</span>
                        <AppBadge :tone="promotionTone(batch.status)" dot>{{ t(`education.promotion_statuses.${batch.status}`) }}</AppBadge>
                        <span v-if="people(batch.created_by)" class="text-[12px] text-faint">{{ t('education.promotions.made_by', { name: people(batch.created_by) }) }}</span>
                    </span>
                </template>
                <template #actions>
                    <template v-if="editable">
                        <AppButton size="sm" variant="danger-soft" :icon="X" :loading="stepping === 'cancel'" @click="step('cancel')">{{ t('education.promotion.cancel') }}</AppButton>
                        <AppButton v-if="changed" size="sm" :icon="Save" :loading="saving" @click="save">{{ t('education.promotion.save') }}</AppButton>
                        <AppButton size="sm" variant="primary" :icon="Send" :disabled="changed" :loading="stepping === 'submit'" @click="step('submit')">
                            {{ setup.data.value?.rules?.promotion_approval ? t('education.promotion.submit') : t('education.promotion.submit_apply') }}
                        </AppButton>
                    </template>
                    <template v-if="batch.status === 'pending_approval' && setup.can('approve_promotion') && !mine">
                        <AppButton size="sm" variant="danger-soft" :icon="RotateCcw" @click="openReject">{{ t('education.promotion.reject') }}</AppButton>
                        <AppButton size="sm" variant="primary" :icon="Check" :loading="stepping === 'approve'" @click="step('approve')">{{ t('education.promotion.approve') }}</AppButton>
                    </template>
                    <AppButton v-if="batch.status === 'applied' && setup.can('promote')" size="sm" variant="danger-soft" :icon="Undo2" :loading="stepping === 'undo'" @click="step('undo')">{{ t('education.promotion.undo') }}</AppButton>
                </template>
            </PageHeader>

            <!-- Where the list stands. -->
            <div class="mb-5 space-y-2">
                <p v-if="batch.note && batch.status === 'draft'" class="flex items-start gap-2 rounded-xl border border-warn/30 bg-warn-soft px-4 py-3 text-[13px] text-fg"><CircleAlert class="mt-0.5 size-4 shrink-0 text-warn" aria-hidden="true" />{{ t('education.promotion.sent_back_note', { note: batch.note }) }}</p>
                <p v-if="batch.status === 'pending_approval'" class="rounded-xl bg-subtle px-4 py-3 text-[13px] text-muted">{{ mine ? t('education.promotion.own_list') : t('education.promotion.waiting') }}</p>
                <p v-if="batch.status === 'applied' && batch.undo_until" class="rounded-xl bg-subtle px-4 py-3 text-[13px] text-muted">{{ t('education.promotion.undo_until', { date: formatDate(batch.undo_until) }) }}</p>
                <p v-if="!editable && batch.status === 'draft'" class="rounded-xl bg-subtle px-4 py-3 text-[13px] text-muted">{{ t('education.promotion.read_only') }}</p>
                <p v-if="changed" class="rounded-xl bg-brand-soft px-4 py-3 text-[13px] text-brand-text" role="status">{{ t('education.promotion.unsaved') }}</p>
            </div>

            <!-- Totals: number and word for each decision. -->
            <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
                <div v-for="key in ['promote', 'repeat', 'leave', 'graduate']" :key="key" class="card p-4">
                    <p class="text-[12.5px] text-muted">{{ t(`education.decisions.${key}`) }}</p>
                    <p class="tabular mt-1 text-[22px] font-semibold text-fg">{{ totals[key] }}</p>
                </div>
            </div>

            <!-- Everyone into one section. -->
            <div v-if="editable && sectionsFor(isLast ? 'repeat' : 'promote').length" class="card mb-5 flex flex-col gap-3 p-4 sm:flex-row sm:items-end">
                <AppField v-slot="{ id }" :label="t('education.promotion.place_all')" :hint="t('education.promotion.place_all_hint')" class="flex-1">
                    <select :id="id" v-model="placeAll" class="field-input">
                        <option value="">—</option>
                        <option v-for="section in [...sectionsFor('promote'), ...sectionsFor('repeat')]" :key="section.id" :value="section.id">{{ setup.levelText(section.level_id) }} · {{ section.name }}</option>
                    </select>
                </AppField>
                <AppButton :disabled="!placeAll || changed" :loading="saving" @click="applyPlaceAll">{{ t('education.promotion.place_all_apply') }}</AppButton>
            </div>

            <section class="card">
                <div class="hidden grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.2fr)] gap-3 border-b border-line px-5 py-2.5 text-[12px] font-medium text-muted lg:grid">
                    <span>{{ t('education.promotion.student') }}</span><span>{{ t('education.promotion.decision') }}</span><span>{{ t('education.promotion.to_section') }}</span><span>{{ t('education.promotion.reason') }}</span>
                </div>
                <ul class="divide-y divide-line">
                    <li v-for="line in lines" :key="line.id" class="grid grid-cols-1 gap-3 px-4 py-3 sm:px-5 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.2fr)] lg:items-center" :class="edits[line.id] ? 'bg-brand-soft/30' : ''">
                        <div class="flex min-w-0 items-center gap-3">
                            <StudentAvatar :name="line.name ?? '?'" size="sm" />
                            <span class="min-w-0">
                                <RouterLink :to="{ name: 'education-student', params: { id: line.student_id } }" class="block truncate text-[14px] font-medium text-fg hover:underline">{{ line.name }}</RouterLink>
                                <span class="block text-[12px] text-muted">
                                    <span class="font-mono" dir="ltr">{{ line.code }}</span>
                                    <template v-if="line.repeats"> · {{ t('education.promotion.repeats', { count: line.repeats }) }}</template>
                                </span>
                            </span>
                        </div>
                        <template v-if="editable">
                            <label class="block">
                                <span class="sr-only">{{ t('education.promotion.decision') }} · {{ line.name }}</span>
                                <select :value="line.decision" class="field-input" @change="change(line, { decision: $event.target.value })">
                                    <option v-for="value in decisions" :key="value" :value="value">{{ t(`education.decisions.${value}`) }}</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="sr-only">{{ t('education.promotion.to_section') }} · {{ line.name }}</span>
                                <select :value="line.to_section_id ?? ''" class="field-input" :disabled="!sectionsFor(line.decision).length" @change="change(line, { to_section_id: $event.target.value || null })">
                                    <option value="">{{ t('education.promotion.no_section') }}</option>
                                    <option v-for="section in sectionsFor(line.decision)" :key="section.id" :value="section.id">{{ section.name }}</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="sr-only">{{ t('education.promotion.reason') }} · {{ line.name }}</span>
                                <input :value="line.reason" class="field-input" maxlength="300" :placeholder="needsReason(line) ? t('education.promotion.reason_needed') : ''"
                                    :aria-invalid="errors[line.id] ? true : undefined" @input="change(line, { reason: $event.target.value })" />
                                <span v-if="errors[line.id]" class="mt-1 block text-[12px] text-bad" role="alert">{{ errors[line.id] }}</span>
                            </label>
                        </template>
                        <template v-else>
                            <span><AppBadge :tone="toneOf[line.decision]">{{ t(`education.decisions.${line.decision}`) }}</AppBadge></span>
                            <span class="text-[13px] text-fg-2">{{ line.to_section_id ? sectionName(line.to_section_id) : '—' }}</span>
                            <span class="text-[13px] text-muted">{{ line.reason || '—' }}</span>
                        </template>
                    </li>
                </ul>
            </section>

            <AppDialog :open="rejecting" :title="t('education.promotion.reject_title', { number: batch.number })" tone="warn" @close="rejecting = false">
                <AppField v-slot="{ id }" :label="t('education.promotion.reject_note')">
                    <textarea :id="id" v-model="rejectNote" rows="3" class="field-input" maxlength="500" />
                </AppField>
                <template #footer>
                    <AppButton variant="ghost" @click="rejecting = false">{{ t('education.cancel') }}</AppButton>
                    <AppButton variant="primary" :loading="stepping === 'reject'" :disabled="rejectNote.trim().length < 3" @click="step('reject', { note: rejectNote.trim() })">{{ t('education.promotion.reject') }}</AppButton>
                </template>
            </AppDialog>
        </template>
    </div>
</template>
