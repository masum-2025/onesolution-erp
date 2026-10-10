<script setup>
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, Check, CircleAlert, MinusCircle, Plus, RotateCcw, Search, SearchX, Send } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDate } from '@/lib/format';
import { currentOrganization, session } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { registrationApi } from '../api';
import { useRegistrationSetup } from '../setup';
import { credits, creditState, itemTone, newOpId, outcomeTone, registrationTone, seats } from '../lib';

/**
 * One student's registration for a session: the credits against the rules
 * (a bar with words), their subjects with status and outcome, subjects
 * added (full ones go to the waiting list) and dropped (withdrawn with a
 * reason once add/drop is over), handed in, approved or sent back by
 * someone else. The server checks every rule again.
 */
const org = currentOrganization();
const api = registrationApi(org.id);
const setup = useRegistrationSetup();
const route = useRoute();

const record = useResource(() => api.registration(route.params.id));
const registration = computed(() => record.data.value?.data ?? null);
const items = computed(() => registration.value?.items ?? []);
const active = computed(() => items.value.filter((item) => ['registered', 'waitlisted'].includes(item.status)));
const ended = computed(() => items.value.filter((item) => !['registered', 'waitlisted'].includes(item.status)));
const credit = computed(() => creditState(registration.value?.credits_centi ?? 0, setup.rules.value));
const today = computed(() => setup.data.value?.today ?? '');
const late = computed(() => registration.value?.window && today.value > registration.value.window.add_drop_until);
const mine = computed(() => registration.value?.submitted_by === session.me?.user?.id);
const editable = computed(() => setup.can('register') && registration.value);

function replace(data) {
    record.data.value = { data: { ...registration.value, ...data } };
}
function failed(error) {
    if (error.code === 'version_conflict') {
        toast.error(t('course_registration.conflict'));
        record.reload();
        return;
    }
    toast.error(error.message);
}

// Adding subjects.
const adding = ref(false);
const offerings = ref([]);
const search = ref('');
const busy = ref(null);
async function openAdd() {
    search.value = '';
    adding.value = true;
    offerings.value = (await api.offerings({ session_id: registration.value.session_id, status: 'open' }).catch(() => ({ data: [] }))).data;
}
const choices = computed(() => {
    const term = search.value.trim().toLowerCase();
    const taken = new Set(active.value.map((item) => item.subject_id));
    return offerings.value
        .filter((offering) => !term || offering.subject?.code.toLowerCase().includes(term) || textIn(offering.subject?.name).toLowerCase().includes(term))
        .map((offering) => ({ ...offering, taken: offering.taken, already: taken.has(offering.subject_id), seat: seats(offering) }))
        .sort((a, b) => (a.subject?.code ?? '').localeCompare(b.subject?.code ?? '') || a.group_name.localeCompare(b.group_name));
});
async function add(offering) {
    busy.value = offering.id;
    try {
        const { data } = await api.add(registration.value.id, { offering_id: offering.id, op_id: newOpId() });
        replace(data);
        const item = data.items.find((row) => row.offering_id === offering.id && ['registered', 'waitlisted'].includes(row.status));
        if (item?.status === 'waitlisted') toast.info(t('course_registration.registration.waitlisted', { subject: offering.subject.code, place: offering.waiting + 1 }));
        else toast.success(t('course_registration.registration.added', { subject: offering.subject.code }));
        offerings.value = (await api.offerings({ session_id: registration.value.session_id, status: 'open' }).catch(() => ({ data: offerings.value }))).data;
    } catch (error) {
        failed(error);
    } finally {
        busy.value = null;
    }
}

// Dropping, or withdrawing with a reason once add/drop is over.
const dropping = ref(null);
const reason = ref('');
async function drop(item) {
    const withdraw = item.status === 'registered' && late.value;
    if (!withdraw) {
        const confirmed = await confirmAction({
            title: t('course_registration.registration.drop_title', { subject: item.subject?.code }),
            message: t('course_registration.registration.drop_text'),
            confirmLabel: t('course_registration.registration.drop'),
            danger: true,
        });
        if (confirmed) await send(item);
        return;
    }
    reason.value = '';
    dropping.value = item;
}
async function send(item, withReason = null) {
    busy.value = item.id;
    try {
        const { data } = await api.drop(item.id, withReason ? { reason: withReason } : {});
        replace(data);
        dropping.value = null;
        toast.success(t('course_registration.registration.dropped'));
    } catch (error) {
        failed(error);
    } finally {
        busy.value = null;
    }
}

// Handing in, approving, sending back.
const stepping = ref(null);
async function step(name) {
    stepping.value = name;
    try {
        const { data } = name === 'submit' ? await api.submit(registration.value.id) : await api.approve(registration.value.id, { base_version: registration.value.version });
        replace(data);
        toast.success(t(`course_registration.registration.${name === 'submit' ? 'submitted' : 'approved'}`));
    } catch (error) {
        failed(error);
    } finally {
        stepping.value = null;
    }
}
const returning = ref(false);
const note = ref('');
async function sendBack() {
    stepping.value = 'send_back';
    try {
        const { data } = await api.sendBack(registration.value.id, { base_version: registration.value.version, note: note.value.trim() });
        replace(data);
        returning.value = false;
        toast.success(t('course_registration.registration.sent_back'));
    } catch (error) {
        failed(error);
    } finally {
        stepping.value = null;
    }
}

const bar = { ok: 'bg-ok', warn: 'bg-warn', bad: 'bg-bad' };
const sourceText = (item) => t(`course_registration.registration.source.${item.source}`);
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'crs-registrations' }" :icon="ArrowLeft" class="mb-3">{{ t('course_registration.registration.back') }}</AppButton>

        <SkeletonRows v-if="record.loading.value && !registration" :rows="8" />
        <section v-else-if="record.error.value?.status === 404" class="card"><EmptyState :icon="SearchX" :title="t('course_registration.registration.not_found')" /></section>
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />

        <template v-else-if="registration">
            <PageHeader :title="registration.student?.name ?? '—'" :description="`${registration.student?.code ?? ''} · ${setup.sessionName(registration.session_id)}`">
                <template #eyebrow>
                    <span class="mb-2 flex flex-wrap items-center gap-2">
                        <AppBadge :tone="registrationTone(registration.status)" dot>{{ t(`course_registration.statuses.${registration.status}`) }}</AppBadge>
                        <AppBadge v-if="registration.overload" tone="warn">{{ t('course_registration.registrations.overload') }}</AppBadge>
                        <span v-if="registration.approved_at" class="text-[12px] text-faint">{{ t('course_registration.registration.approved_on', { date: formatDate(registration.approved_at) }) }}</span>
                    </span>
                </template>
                <template #actions>
                    <AppButton v-if="editable && ['draft', 'returned'].includes(registration.status)" variant="primary" :icon="Send" :loading="stepping === 'submit'" @click="step('submit')">{{ t('course_registration.registration.submit') }}</AppButton>
                    <template v-if="setup.can('approve') && registration.status === 'submitted' && !mine">
                        <AppButton variant="danger-soft" :icon="RotateCcw" @click="returning = true; note = ''">{{ t('course_registration.registration.send_back') }}</AppButton>
                        <AppButton variant="primary" :icon="Check" :loading="stepping === 'approve'" @click="step('approve')">{{ t('course_registration.registration.approve') }}</AppButton>
                    </template>
                </template>
            </PageHeader>

            <!-- Where it stands. -->
            <div class="mb-5 space-y-2">
                <p v-if="registration.status === 'returned' && registration.note" class="flex items-start gap-2 rounded-xl border border-bad/30 bg-bad-soft px-4 py-3 text-[13px] text-fg"><CircleAlert class="mt-0.5 size-4 shrink-0 text-bad" aria-hidden="true" />{{ t('course_registration.registration.returned_note', { note: registration.note }) }}</p>
                <p v-if="registration.status === 'submitted'" class="rounded-xl bg-subtle px-4 py-3 text-[13px] text-muted">{{ mine ? t('course_registration.registration.own') : t('course_registration.registration.waiting_for_approval') }}</p>
            </div>

            <!-- Credits against the rules. -->
            <section class="card mb-5 p-5">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-[13px] font-medium text-muted">{{ t('course_registration.registration.credits') }}</h2>
                    <p class="text-[13px] text-muted">
                        <span class="tabular text-[22px] font-semibold text-fg">{{ credits(registration.credits_centi) }}</span>
                        <span v-if="credit.max" class="ms-1">{{ t('course_registration.registration.of_max', { max: credits(credit.max) }) }}</span>
                    </p>
                </div>
                <div class="relative mt-2 h-2 overflow-hidden rounded-full bg-subtle">
                    <div class="h-full rounded-full" :class="bar[credit.tone]" :style="{ inlineSize: `${credit.percent}%` }" />
                    <span v-if="credit.maxPercent && credit.maxPercent < 100" class="absolute inset-y-0 w-0.5 bg-fg/40" :style="{ insetInlineStart: `${credit.maxPercent}%` }" aria-hidden="true" />
                </div>
                <p v-if="credit.state !== 'ok'" class="mt-2 text-[12.5px]" :class="credit.tone === 'bad' ? 'text-bad' : 'text-warn'">
                    {{ t(`course_registration.registration.${credit.state}`, { min: credits(credit.min), max: credits(credit.max), limit: credits(credit.limit) }) }}
                </p>
            </section>

            <!-- Subjects. -->
            <section class="card mb-5">
                <header class="flex items-center justify-between gap-2 border-b border-line px-5 py-3">
                    <h2 class="text-[13.5px] font-semibold text-fg">{{ t('course_registration.registration.subjects') }}</h2>
                    <AppButton v-if="editable" size="sm" :icon="Plus" @click="openAdd">{{ t('course_registration.registration.add') }}</AppButton>
                </header>
                <p v-if="!active.length" class="px-5 py-5 text-[13px] text-muted">{{ t('course_registration.registration.no_subjects') }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="item in active" :key="item.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[14px] font-medium text-fg"><span class="font-mono" dir="ltr">{{ item.subject?.code }}</span> · {{ textIn(item.subject?.name) }}</span>
                            <span class="block text-[12.5px] text-muted">{{ t('course_registration.offerings.group', { name: item.group_name ?? '' }) }} · {{ t('course_registration.credits_text', { credits: credits(item.credits_centi) }) }} · {{ sourceText(item) }}</span>
                        </span>
                        <AppBadge v-if="item.outcome" :tone="outcomeTone(item.outcome)">{{ t(`course_registration.outcomes.${item.outcome}`) }}</AppBadge>
                        <AppBadge :tone="itemTone(item.status)" dot>{{ t(`course_registration.item_statuses.${item.status}`) }}</AppBadge>
                        <AppButton v-if="editable" size="sm" variant="danger-soft" :icon="MinusCircle" :loading="busy === item.id" @click="drop(item)">
                            {{ item.status === 'registered' && late ? t('course_registration.registration.withdraw') : t('course_registration.registration.drop') }}
                        </AppButton>
                    </li>
                </ul>
                <ul v-if="ended.length" class="divide-y divide-line border-t border-line bg-subtle/40">
                    <li v-for="item in ended" :key="item.id" class="flex flex-wrap items-center gap-3 px-5 py-2.5 text-[13px] text-muted">
                        <span class="min-w-0 flex-1 truncate"><span class="font-mono" dir="ltr">{{ item.subject?.code }}</span> · {{ textIn(item.subject?.name) }}<template v-if="item.reason"> · {{ item.reason }}</template></span>
                        <AppBadge :tone="itemTone(item.status)">{{ t(`course_registration.item_statuses.${item.status}`) }}</AppBadge>
                    </li>
                </ul>
            </section>
        </template>

        <!-- Adding a subject. -->
        <AppDialog :open="adding" :title="t('course_registration.registration.add_title')" :description="t('course_registration.registration.add_text')" :icon="Plus" size="lg" @close="adding = false">
            <label class="relative mb-3 block">
                <span class="sr-only">{{ t('course_registration.registration.search') }}</span>
                <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-faint" aria-hidden="true" />
                <input v-model="search" type="search" class="field-input ps-9" :placeholder="t('course_registration.registration.search')" autocomplete="off" />
            </label>
            <p v-if="!choices.length" class="text-[13px] text-muted">{{ t('course_registration.registration.none_to_add') }}</p>
            <ul v-else class="max-h-[55vh] divide-y divide-line overflow-y-auto rounded-xl border border-line">
                <li v-for="offering in choices" :key="offering.id" class="flex flex-wrap items-center gap-3 px-3 py-2.5">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[13.5px] font-medium text-fg"><span class="font-mono" dir="ltr">{{ offering.subject?.code }}</span> · {{ textIn(offering.subject?.name) }}</span>
                        <span class="block text-[12px] text-muted">
                            {{ t('course_registration.offerings.group', { name: offering.group_name }) }} · {{ t('course_registration.credits_text', { credits: credits(offering.credits_centi) }) }} ·
                            <span :class="{ ok: 'text-ok', warn: 'text-warn', bad: 'text-bad' }[offering.seat.tone]">{{ t(`course_registration.seats.${offering.seat.state}`, { count: offering.seat.left }) }}</span>
                        </span>
                    </span>
                    <AppBadge v-if="offering.already" tone="outline">{{ t('course_registration.registration.taken') }}</AppBadge>
                    <AppButton v-else size="sm" :variant="offering.seat.state === 'full' ? 'secondary' : 'primary'" :disabled="offering.seat.state === 'full' && !setup.rules.value.waitlist" :loading="busy === offering.id" @click="add(offering)">
                        {{ offering.seat.state === 'full' ? t('course_registration.registration.full_waitlist') : t('course_registration.registration.add_one') }}
                    </AppButton>
                </li>
            </ul>
            <template #footer>
                <AppButton variant="ghost" @click="adding = false">{{ t('course_registration.back') }}</AppButton>
            </template>
        </AppDialog>

        <!-- Withdrawing with a reason. -->
        <AppDialog :open="dropping !== null" :title="dropping ? t('course_registration.registration.withdraw_title', { subject: dropping.subject?.code }) : ''" :description="t('course_registration.registration.withdraw_text')" tone="warn" @close="dropping = null">
            <AppField v-slot="{ id }" :label="t('course_registration.registration.reason')">
                <textarea :id="id" v-model="reason" rows="3" class="field-input" maxlength="300" />
            </AppField>
            <template #footer>
                <AppButton variant="ghost" @click="dropping = null">{{ t('course_registration.cancel') }}</AppButton>
                <AppButton variant="danger" :loading="busy === dropping?.id" :disabled="reason.trim().length < 3" @click="send(dropping, reason.trim())">{{ t('course_registration.registration.withdraw') }}</AppButton>
            </template>
        </AppDialog>

        <!-- Sending back with a note. -->
        <AppDialog :open="returning" :title="t('course_registration.registration.send_back_title')" tone="warn" @close="returning = false">
            <AppField v-slot="{ id }" :label="t('course_registration.registration.note')">
                <textarea :id="id" v-model="note" rows="3" class="field-input" maxlength="500" />
            </AppField>
            <template #footer>
                <AppButton variant="ghost" @click="returning = false">{{ t('course_registration.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="stepping === 'send_back'" :disabled="note.trim().length < 3" @click="sendBack">{{ t('course_registration.registration.send_back') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
