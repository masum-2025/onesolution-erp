<script setup>
import { computed, ref, watch } from 'vue';
import { CalendarDays, ClipboardCheck, Users } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatNumber } from '@/lib/format';
import { can, currentOrganization, session } from '@/lib/session';
import { t } from '@/lib/i18n';
import { attendanceApi } from '../api';
import { dayTone, durationParts, localDateTime, STATUSES, statusCounts } from '../lib';
import CorrectionDialog from '../components/CorrectionDialog.vue';
import DayDrawer from '../components/DayDrawer.vue';

/**
 * The day at a unit (and the units below it): who is in, late, absent or
 * off, with counts to filter by. A row opens the person's day (punches;
 * writing or voiding one; asking a correction for them).
 */
const org = currentOrganization();
const attendance = attendanceApi(org.id);
const zone = session.me?.context?.settings?.timezone;

const date = ref(localDateTime(zone).slice(0, 10));
const page = ref(1);
const filter = ref('all');
const list = useResource(() => attendance.days({ from: date.value, to: date.value, page: page.value }));
const days = computed(() => list.data.value?.data ?? []);
const meta = computed(() => list.data.value?.meta ?? { employees: 0, per_page: 50, page: 1 });
const counts = computed(() => statusCounts(days.value));
const shown = computed(() => (filter.value === 'all' ? days.value : days.value.filter((day) => day.status === filter.value)));
const pages = computed(() => Math.max(1, Math.ceil(meta.value.employees / meta.value.per_page)));

watch([date, page], () => list.reload());

const open = ref(null);
const correcting = ref(null);
const time = (iso) => (iso ? formatDate(iso, { timeStyle: 'short' }) : '—');
const duration = (minutes) => t('attendance.duration', durationParts(minutes));
</script>

<template>
    <div>
        <PageHeader :title="t('attendance.today.title')" :description="t('attendance.today.text', { unit: org.name })">
            <template #actions>
                <AppButton variant="secondary" :icon="CalendarDays" :to="{ name: 'attendance-month' }">{{ t('attendance.month.title') }}</AppButton>
                <AppButton v-if="can('attendance.correct')" variant="secondary" :icon="ClipboardCheck" :to="{ name: 'attendance-corrections' }">{{ t('attendance.corrections.title') }}</AppButton>
            </template>
        </PageHeader>

        <section class="card mb-5 flex flex-wrap items-end gap-4 p-5">
            <AppField v-slot="{ id }" :label="t('attendance.today.date')" class="w-44">
                <input :id="id" v-model="date" type="date" class="field-input" @change="page = 1" />
            </AppField>
            <div class="flex flex-wrap gap-2" role="group" :aria-label="t('attendance.today.filter')">
                <button
                    v-for="status in ['all', ...STATUSES.filter((value) => counts[value])]"
                    :key="status"
                    type="button"
                    class="rounded-full border px-3 py-1.5 text-[12.5px] transition"
                    :class="filter === status ? 'border-brand bg-brand-soft font-semibold text-brand-text' : 'border-line hover:bg-surface-2'"
                    :aria-pressed="filter === status"
                    @click="filter = status"
                >
                    {{ status === 'all' ? t('attendance.today.everyone') : t(`attendance.status.${status}`) }}
                    <span class="tabular ms-1">{{ formatNumber(status === 'all' ? days.length : counts[status]) }}</span>
                </button>
            </div>
        </section>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="6" avatar />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!shown.length" :icon="Users" :title="t(days.length ? 'attendance.today.none_with_status' : 'attendance.today.empty')" :text="days.length ? '' : t('attendance.today.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="day in shown" :key="day.employee_id">
                    <button type="button" class="flex w-full flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-start hover:bg-surface-2" @click="open = day">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[14px] font-medium">{{ day.employee_name }}</span>
                            <span class="block font-mono text-[12px] text-muted" dir="ltr">{{ day.employee_code }}</span>
                        </span>
                        <AppBadge :tone="dayTone(day.status)" dot>{{ t(`attendance.status.${day.status}`) }}</AppBadge>
                        <span class="tabular w-32 text-end text-[13px]">{{ time(day.first_in_at) }} – {{ time(day.last_out_at) }}</span>
                        <span class="tabular hidden w-24 text-end text-[13px] text-muted sm:inline">{{ day.worked_minutes ? duration(day.worked_minutes) : '' }}</span>
                    </button>
                </li>
            </ul>
            <footer v-if="pages > 1" class="flex items-center justify-between border-t border-line px-5 py-3 text-[13px]">
                <AppButton size="sm" variant="ghost" :disabled="page <= 1" @click="page--">{{ t('attendance.common.previous') }}</AppButton>
                <span class="text-muted">{{ t('attendance.common.page', { page: formatNumber(page), pages: formatNumber(pages) }) }}</span>
                <AppButton size="sm" variant="ghost" :disabled="page >= pages" @click="page++">{{ t('attendance.common.next') }}</AppButton>
            </footer>
        </section>

        <DayDrawer :day="open" :attendance="attendance" :can-manage="can('attendance.manage')" @close="open = null" @changed="list.reload()" @correct="(day) => { correcting = day; open = null; }" />
        <CorrectionDialog
            :open="correcting !== null"
            :date="correcting?.work_date ?? ''"
            :employee-name="correcting?.employee_name ?? ''"
            :send="(body) => attendance.askFor({ ...body, employee_id: correcting.employee_id })"
            @close="correcting = null"
            @sent="correcting = null"
        />
    </div>
</template>
