<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { BookOpen, CircleAlert, Info, ListChecks, MinusCircle, Plus, SearchX, Send, Users } from 'lucide-vue-next';
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
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { portalRegistrationApi } from '../api';
import { credits, creditState, itemTone, newOpId, offeringAction, portalBlock, registrationTone, seats } from '../lib';

/**
 * A student choosing their own subjects in the client's portal: what is
 * offered to their class (with seats, and why one cannot be chosen before
 * the tap), their subjects with the waiting-list place, credits against
 * the rules, then hand in. A parent opens it from the child's record and
 * only looks. The server checks every rule again.
 */
const route = useRoute();
const view = useResource(() => portalRegistrationApi.show(route.params.record || null));
watch(() => route.params.record, () => view.reload());

const data = computed(() => view.data.value?.data ?? null);
const registration = computed(() => data.value?.registration ?? null);
const items = computed(() => registration.value?.items ?? []);
const active = computed(() => items.value.filter((item) => ['registered', 'waitlisted'].includes(item.status)));
const ended = computed(() => items.value.filter((item) => !['registered', 'waitlisted'].includes(item.status)));
const credit = computed(() => creditState(registration.value?.credits_centi ?? 0, data.value?.rules));
const block = computed(() => portalBlock(data.value));
const late = computed(() => data.value?.window && data.value.today > data.value.window.add_drop_until);
const offered = computed(() => data.value?.offerings ?? []);
// Subjects already on the registration show above, not twice.
const offerings = computed(() => offered.value.filter((offering) => offering.reason !== 'taken')
    .map((offering) => ({ ...offering, seat: seats(offering), action: offeringAction(offering, data.value.can.add) }))
    .sort((a, b) => (a.subject?.code ?? '').localeCompare(b.subject?.code ?? '') || a.group_name.localeCompare(b.group_name)));
const canSubmit = computed(() => data.value?.can.add && registration.value && ['draft', 'returned'].includes(registration.value.status) && active.value.length > 0);
const short = computed(() => Math.max(0, (data.value?.rules?.min_credits_centi ?? 0) - (registration.value?.credits_centi ?? 0)));

const blockDate = computed(() => {
    const window = data.value?.window;
    if (block.value === 'upcoming') return formatDate(window?.opens_on);
    if (block.value === 'add_drop') return formatDate(window?.add_drop_until);
    return '';
});
const why = (offering) => (offering.action.reason === 'prerequisites'
    ? t('course_registration.portal.why.prerequisites', { codes: offering.missing.join(', ') })
    : offering.action.reason ? t(`course_registration.portal.why.${offering.action.reason}`) : '');

function failed(error) {
    toast.error(error.message);
    view.reload();
}

// Adding a subject (a full one puts the student on its waiting list).
const busy = ref(null);
async function add(offering) {
    busy.value = offering.id;
    try {
        const { data: next } = await portalRegistrationApi.add({ offering_id: offering.id, op_id: newOpId() });
        view.data.value = { data: next };
        const item = next.registration?.items.find((row) => row.offering_id === offering.id && ['registered', 'waitlisted'].includes(row.status));
        if (item?.status === 'waitlisted') toast.info(t('course_registration.portal.waitlisted', { subject: offering.subject?.code, place: item.position ?? 1 }));
        else toast.success(t('course_registration.portal.added', { subject: offering.subject?.code }));
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
    if (item.status === 'registered' && late.value) {
        reason.value = '';
        dropping.value = item;
        return;
    }
    const confirmed = await confirmAction({
        title: t('course_registration.registration.drop_title', { subject: item.subject?.code }),
        message: t('course_registration.registration.drop_text'),
        confirmLabel: t('course_registration.registration.drop'),
        danger: true,
    });
    if (confirmed) await send(item);
}
async function send(item, withReason = null) {
    busy.value = item.id;
    try {
        const { data: next } = await portalRegistrationApi.drop(item.id, withReason ? { reason: withReason } : {});
        view.data.value = { data: next };
        dropping.value = null;
        toast.success(t('course_registration.portal.dropped', { subject: item.subject?.code }));
    } catch (error) {
        failed(error);
    } finally {
        busy.value = null;
    }
}

const submitting = ref(false);
async function submit() {
    submitting.value = true;
    try {
        const { data: next } = await portalRegistrationApi.submit();
        view.data.value = { data: next };
        toast.success(t('course_registration.portal.submitted'));
    } catch (error) {
        failed(error);
    } finally {
        submitting.value = false;
    }
}

const bar = { ok: 'bg-ok', warn: 'bg-warn', bad: 'bg-bad' };
const seatText = { ok: 'text-ok', warn: 'text-warn', bad: 'text-bad' };
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <SkeletonRows v-if="view.loading.value && !data" :rows="8" />
        <section v-else-if="view.error.value?.status === 404" class="card"><EmptyState :icon="SearchX" :title="t('course_registration.portal.not_found')" /></section>
        <ErrorState v-else-if="view.error.value" :error="view.error.value" @retry="view.reload()" />

        <template v-else-if="data">
            <PageHeader
                :title="data.own ? t('course_registration.portal.title') : t('course_registration.portal.title_of', { name: data.student.name })"
                :description="[data.student.code, textIn(data.session?.name)].filter(Boolean).join(' · ')"
            >
                <template v-if="registration" #eyebrow>
                    <span class="mb-2 flex flex-wrap items-center gap-2">
                        <AppBadge :tone="registrationTone(registration.status)" dot>{{ t(`course_registration.statuses.${registration.status}`) }}</AppBadge>
                        <AppBadge v-if="registration.overload" tone="warn">{{ t('course_registration.registrations.overload') }}</AppBadge>
                    </span>
                </template>
            </PageHeader>

            <!-- Where it stands. -->
            <div class="mb-5 space-y-2">
                <p v-if="block" class="flex items-start gap-2 rounded-xl bg-subtle px-4 py-3 text-[13px] text-fg">
                    <Info class="mt-0.5 size-4 shrink-0 text-muted" aria-hidden="true" />{{ t(`course_registration.portal.block.${block}`, { date: blockDate }) }}
                </p>
                <p v-if="registration?.status === 'returned' && registration.note" class="flex items-start gap-2 rounded-xl border border-bad/30 bg-bad-soft px-4 py-3 text-[13px] text-fg">
                    <CircleAlert class="mt-0.5 size-4 shrink-0 text-bad" aria-hidden="true" />{{ t('course_registration.registration.returned_note', { note: registration.note }) }}
                </p>
                <p v-if="registration?.status === 'submitted'" class="rounded-xl bg-warn-soft px-4 py-3 text-[13px] text-fg">{{ t('course_registration.portal.waiting', { date: formatDate(registration.submitted_at) }) }}</p>
                <p v-if="registration?.status === 'approved'" class="rounded-xl bg-ok-soft px-4 py-3 text-[13px] text-fg">
                    {{ t('course_registration.portal.approved', { date: formatDate(registration.approved_at) }) }}
                    <template v-if="data.can.add && data.rules.approval_required"> {{ t('course_registration.portal.changed_after') }}</template>
                </p>
            </div>

            <!-- Credits against the rules. -->
            <section class="card mb-5 p-5">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-[13px] font-medium text-muted">{{ t('course_registration.registration.credits') }}</h2>
                    <p class="text-[13px] text-muted">
                        <span class="tabular text-[22px] font-semibold text-fg">{{ credits(registration?.credits_centi ?? 0) }}</span>
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

            <!-- The student's subjects. -->
            <section class="card mb-5">
                <header class="flex items-center gap-2 border-b border-line px-5 py-3">
                    <ListChecks class="size-4 text-muted" aria-hidden="true" />
                    <h2 class="text-[13.5px] font-semibold text-fg">{{ data.own ? t('course_registration.portal.mine') : t('course_registration.registration.subjects') }}</h2>
                </header>
                <p v-if="!active.length" class="px-5 py-5 text-[13px] text-muted">{{ data.own && data.can.add ? t('course_registration.portal.none_yet') : t('course_registration.portal.none_yet_other') }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="item in active" :key="item.id" class="flex flex-wrap items-center gap-x-3 gap-y-2 px-5 py-3">
                        <span class="min-w-0 basis-full sm:basis-0 sm:flex-1">
                            <span class="block text-[14px] font-medium text-fg"><span class="font-mono" dir="ltr">{{ item.subject?.code }}</span> · {{ textIn(item.subject?.name) }}</span>
                            <span class="block text-[12.5px] text-muted">{{ t('course_registration.offerings.group', { name: item.group_name ?? '' }) }} · {{ t('course_registration.credits_text', { credits: credits(item.credits_centi) }) }}</span>
                        </span>
                        <AppBadge :tone="itemTone(item.status)" dot>{{ item.status === 'waitlisted' && item.position ? t('course_registration.portal.place', { place: item.position }) : t(`course_registration.item_statuses.${item.status}`) }}</AppBadge>
                        <AppButton v-if="data.can.drop" size="sm" variant="danger-soft" class="ms-auto" :icon="MinusCircle" :loading="busy === item.id" @click="drop(item)">
                            {{ item.status === 'registered' && late ? t('course_registration.registration.withdraw') : t('course_registration.registration.drop') }}
                        </AppButton>
                    </li>
                </ul>
                <ul v-if="ended.length" class="divide-y divide-line border-t border-line bg-subtle/40">
                    <li v-for="item in ended" :key="item.id" class="flex flex-wrap items-center gap-3 px-5 py-2.5 text-[13px] text-muted">
                        <span class="min-w-0 flex-1"><span class="font-mono" dir="ltr">{{ item.subject?.code }}</span> · {{ textIn(item.subject?.name) }}<template v-if="item.reason"> · {{ item.reason }}</template></span>
                        <AppBadge :tone="itemTone(item.status)">{{ t(`course_registration.item_statuses.${item.status}`) }}</AppBadge>
                    </li>
                </ul>
                <!-- Handing in. -->
                <footer v-if="canSubmit" class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-5 py-3">
                    <p class="text-[12.5px]" :class="short ? 'text-warn' : 'text-muted'">{{ short ? t('course_registration.portal.submit_more', { count: credits(short) }) : t('course_registration.portal.submit_text') }}</p>
                    <AppButton variant="primary" :icon="Send" :loading="submitting" :disabled="short > 0 || credit.state === 'over'" @click="submit">{{ t('course_registration.portal.submit') }}</AppButton>
                </footer>
            </section>

            <!-- What can be chosen. -->
            <section v-if="data.own" class="card mb-5">
                <header class="border-b border-line px-5 py-3">
                    <h2 class="flex items-center gap-2 text-[13.5px] font-semibold text-fg"><BookOpen class="size-4 text-muted" aria-hidden="true" />{{ t('course_registration.portal.offered') }}</h2>
                    <p class="mt-0.5 text-[12.5px] text-muted">{{ t('course_registration.portal.offered_text') }}</p>
                </header>
                <p v-if="!offerings.length" class="px-5 py-5 text-[13px] text-muted">{{ offered.length ? t('course_registration.portal.all_chosen') : t('course_registration.portal.none_offered') }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="offering in offerings" :key="offering.id" class="flex flex-wrap items-center gap-x-3 gap-y-2 px-5 py-3">
                        <span class="min-w-0 basis-full sm:basis-0 sm:flex-1">
                            <span class="block text-[14px] font-medium text-fg"><span class="font-mono" dir="ltr">{{ offering.subject?.code }}</span> · {{ textIn(offering.subject?.name) }}</span>
                            <span class="block text-[12.5px] text-muted">
                                {{ t('course_registration.offerings.group', { name: offering.group_name }) }} · {{ t('course_registration.credits_text', { credits: credits(offering.credits_centi) }) }} ·
                                <span :class="seatText[offering.seat.tone]">{{ t(`course_registration.seats.${offering.seat.state}`, { count: offering.seat.left }) }}</span>
                            </span>
                        </span>
                        <span v-if="offering.action.kind === 'blocked'" class="ms-auto text-[12.5px] text-muted">{{ why(offering) }}</span>
                        <AppButton
                            v-else
                            size="sm"
                            class="ms-auto"
                            :variant="offering.action.kind === 'waitlist' ? 'secondary' : 'primary'"
                            :icon="offering.action.kind === 'waitlist' ? Users : Plus"
                            :loading="busy === offering.id"
                            :disabled="busy !== null"
                            @click="add(offering)"
                        >
                            {{ offering.action.kind === 'waitlist' ? t('course_registration.portal.join_waitlist') : t('course_registration.portal.add') }}
                        </AppButton>
                    </li>
                </ul>
            </section>
        </template>

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
    </div>
</template>
