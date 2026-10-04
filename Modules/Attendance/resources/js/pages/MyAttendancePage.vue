<script setup>
import { computed, ref } from 'vue';
import { ChevronLeft, ChevronRight, Fingerprint, LogIn, LogOut, PenLine, UserX } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { attendanceApi } from '../api';
import { dayTone, durationParts, nextPunch, shiftMonth } from '../lib';
import CorrectionDialog from '../components/CorrectionDialog.vue';

/**
 * An employee's own attendance: one big button to check in or out, today
 * as the server works it out, the days of a month, and asking to fix one.
 */
const org = currentOrganization();
const attendance = attendanceApi(org.id);

const me = useResource(() => attendance.me());
const data = computed(() => me.data.value?.data ?? null);
const today = computed(() => data.value?.today ?? null);
const action = computed(() => nextPunch(today.value));

const month = ref(new Date().toISOString().slice(0, 7));
const days = useResource(() => attendance.myDays(month.value));
const corrections = useResource(() => attendance.myCorrections());
const punching = ref(false);
const asking = ref(null);

const time = (iso) => (iso ? formatDate(iso, { timeStyle: 'short' }) : '—');
const duration = (minutes) => t('attendance.duration', durationParts(minutes));

async function punch() {
    punching.value = true;
    try {
        const opId = globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random()}`;
        const { data: result } = await attendance.punch(opId);
        toast.success(t(action.value === 'in' ? 'attendance.me.checked_in' : 'attendance.me.checked_out', { time: time(result.punch.punched_at) }));
        await Promise.all([me.reload(), days.reload()]);
    } catch (error) {
        toast.error(error.message);
    } finally {
        punching.value = false;
    }
}

function moveMonth(by) {
    month.value = shiftMonth(month.value, by);
    days.reload();
}

function sent() {
    asking.value = null;
    corrections.reload();
}
</script>

<template>
    <div>
        <PageHeader :title="t('attendance.me.title')" :description="t('attendance.me.text')">
            <template v-if="data" #actions>
                <AppButton variant="secondary" :icon="PenLine" @click="asking = new Date().toISOString().slice(0, 10)">{{ t('attendance.me.ask') }}</AppButton>
            </template>
        </PageHeader>

        <section v-if="me.loading.value && !data" class="card"><SkeletonRows :rows="4" /></section>
        <section v-else-if="me.error.value?.code === 'not_linked'" class="card">
            <EmptyState :icon="UserX" :title="t('attendance.me.not_linked')" :text="t('attendance.me.not_linked_text')" />
        </section>
        <section v-else-if="me.error.value" class="card"><ErrorState compact :error="me.error.value" @retry="me.reload()" /></section>

        <div v-else-if="data" class="grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
            <!-- Today -->
            <section class="card flex flex-col items-center gap-4 p-6 text-center">
                <p class="text-[13px] text-muted">{{ formatDate(new Date().toISOString(), { dateStyle: 'full' }) }}</p>
                <AppBadge v-if="today" :tone="dayTone(today.status)" dot>{{ t(`attendance.status.${today.status}`) }}</AppBadge>
                <button
                    v-if="data.can_punch"
                    type="button"
                    class="grid size-40 place-items-center rounded-full shadow-lg transition focus-visible:outline-4 focus-visible:outline-offset-4 disabled:opacity-60"
                    :class="action === 'in' ? 'bg-brand text-brand-fg hover:bg-brand-hover' : 'bg-fg text-surface hover:opacity-90'"
                    :disabled="punching"
                    @click="punch"
                >
                    <span class="grid justify-items-center gap-1.5">
                        <component :is="punching ? Fingerprint : action === 'in' ? LogIn : LogOut" class="size-9" aria-hidden="true" />
                        <span class="text-[17px] font-semibold">{{ t(action === 'in' ? 'attendance.me.check_in' : 'attendance.me.check_out') }}</span>
                    </span>
                </button>
                <p v-else class="rounded-xl bg-subtle px-4 py-3 text-[13px] text-muted">{{ t('attendance.me.no_punch') }}</p>

                <dl class="grid w-full grid-cols-3 gap-2 text-[13px]">
                    <div><dt class="text-muted">{{ t('attendance.day.in') }}</dt><dd class="tabular font-semibold">{{ time(today?.first_in_at) }}</dd></div>
                    <div><dt class="text-muted">{{ t('attendance.day.out') }}</dt><dd class="tabular font-semibold">{{ time(today?.last_out_at) }}</dd></div>
                    <div><dt class="text-muted">{{ t('attendance.day.worked') }}</dt><dd class="tabular font-semibold">{{ today?.worked_minutes ? duration(today.worked_minutes) : '—' }}</dd></div>
                </dl>
                <ul v-if="data.punches.length" class="w-full divide-y divide-line rounded-xl border border-line text-start text-[12.5px]">
                    <li v-for="item in data.punches" :key="item.id" class="flex justify-between px-3 py-1.5">
                        <span class="tabular">{{ formatDate(item.punched_at, { dateStyle: 'medium', timeStyle: 'short' }) }}</span>
                        <span class="text-muted">{{ t(`attendance.source.${item.source}`) }}</span>
                    </li>
                </ul>
            </section>

            <div class="grid min-w-0 grid-cols-1 content-start gap-5">
                <!-- My month -->
                <section class="card">
                    <header class="flex items-center justify-between gap-3 border-b border-line px-5 py-3">
                        <h2 class="text-[14.5px] font-semibold">{{ t('attendance.me.month') }}</h2>
                        <div class="flex items-center gap-1">
                            <AppButton size="icon-sm" variant="ghost" :icon="ChevronLeft" :aria-label="t('attendance.common.previous_month')" @click="moveMonth(-1)" />
                            <span class="min-w-28 text-center text-[13px] font-medium">{{ formatDate(`${month}-01T00:00:00Z`, { month: 'long', year: 'numeric', timeZone: 'UTC' }) }}</span>
                            <AppButton size="icon-sm" variant="ghost" :icon="ChevronRight" :aria-label="t('attendance.common.next_month')" @click="moveMonth(1)" />
                        </div>
                    </header>
                    <SkeletonRows v-if="days.loading.value && !days.data.value" :rows="5" />
                    <ErrorState v-else-if="days.error.value" compact :error="days.error.value" @retry="days.reload()" />
                    <p v-else-if="!days.data.value?.data.length" class="px-5 py-6 text-center text-[13px] text-muted">{{ t('attendance.me.no_days') }}</p>
                    <ul v-else class="divide-y divide-line">
                        <li v-for="day in days.data.value.data" :key="day.work_date" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-2.5 text-[13px]">
                            <span class="w-28 shrink-0">{{ formatDate(`${day.work_date}T00:00:00Z`, { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' }) }}</span>
                            <AppBadge :tone="dayTone(day.status)" dot>{{ t(`attendance.status.${day.status}`) }}</AppBadge>
                            <span class="flex-1"></span>
                            <span class="tabular text-muted">{{ time(day.first_in_at) }} – {{ time(day.last_out_at) }}</span>
                            <span v-if="day.late_minutes" class="tabular text-[12px] text-muted">{{ t('attendance.day.late_by', { time: duration(day.late_minutes) }) }}</span>
                        </li>
                    </ul>
                </section>

                <!-- My corrections -->
                <section v-if="corrections.data.value?.data.length" class="card">
                    <header class="border-b border-line px-5 py-3"><h2 class="text-[14.5px] font-semibold">{{ t('attendance.me.corrections') }}</h2></header>
                    <ul class="divide-y divide-line">
                        <li v-for="item in corrections.data.value.data" :key="item.id" class="flex flex-wrap items-center gap-3 px-5 py-2.5 text-[13px]">
                            <span class="w-28 shrink-0">{{ formatDate(`${item.work_date}T00:00:00Z`, { day: 'numeric', month: 'short', timeZone: 'UTC' }) }}</span>
                            <span class="min-w-0 flex-1 truncate text-muted">{{ item.reason }}</span>
                            <AppBadge :tone="item.status === 'approved' ? 'ok' : item.status === 'rejected' ? 'bad' : 'warn'">{{ t(`attendance.correction.status.${item.status}`) }}</AppBadge>
                        </li>
                    </ul>
                </section>
            </div>
        </div>

        <CorrectionDialog :open="asking !== null" :date="asking ?? ''" :send="(body) => attendance.askMine(body)" @close="asking = null" @sent="sent" />
    </div>
</template>
