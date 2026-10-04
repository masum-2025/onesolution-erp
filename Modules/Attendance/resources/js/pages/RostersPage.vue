<script setup>
import { computed, reactive, ref } from 'vue';
import { ArrowLeft, CalendarClock, Users } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatNumber } from '@/lib/format';
import { can, currentOrganization, session } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { attendanceApi } from '../api';
import { localDateTime, shiftHours } from '../lib';

/**
 * Who works which shift: the unit's people with today's shift; choose some,
 * a shift (or none: no fixed hours) and the day it starts. The shift each
 * had before ends the day before.
 */
const org = currentOrganization();
const attendance = attendanceApi(org.id);
const today = localDateTime(session.me?.context?.settings?.timezone).slice(0, 10);

const people = useResource(() => attendance.days({ from: today, to: today }));
const shifts = useResource(() => attendance.shifts());
const rows = computed(() => people.data.value?.data ?? []);
const shiftList = computed(() => (shifts.data.value?.data ?? []).filter((shift) => shift.is_active));
const shiftById = computed(() => Object.fromEntries((shifts.data.value?.data ?? []).map((shift) => [shift.id, shift])));

const chosen = ref([]);
const form = reactive({ shift_id: '', from: today });
const saving = ref(false);
const errors = ref({});
const allChosen = computed(() => rows.value.length > 0 && chosen.value.length === rows.value.length);

function toggleAll() {
    chosen.value = allChosen.value ? [] : rows.value.map((row) => row.employee_id);
}

async function assign() {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await attendance.assign({ employee_ids: chosen.value, shift_id: form.shift_id || null, from: form.from });
        toast.success(t('attendance.rosters.assigned', { count: formatNumber(data.assigned) }));
        chosen.value = [];
        people.reload();
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

const shiftLabel = (id) => {
    const shift = shiftById.value[id];
    return shift ? `${shift.name} · ${shiftHours(shift).from}–${shiftHours(shift).to}` : t('attendance.rosters.no_shift');
};
</script>

<template>
    <div>
        <PageHeader :title="t('attendance.rosters.title')" :description="t('attendance.rosters.text', { unit: org.name })">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: 'attendance' }" :icon="ArrowLeft">{{ t('attendance.common.back') }}</AppButton>
            </template>
        </PageHeader>

        <form v-if="can('attendance.manage')" class="card mb-5 grid gap-4 p-5 sm:grid-cols-[minmax(0,1fr)_11rem_auto] sm:items-end" novalidate @submit.prevent="assign">
            <AppField v-slot="{ id }" :label="t('attendance.rosters.shift')" :error="errors.shift_id?.[0]">
                <select :id="id" v-model="form.shift_id" class="field-input">
                    <option value="">{{ t('attendance.rosters.no_shift') }}</option>
                    <option v-for="shift in shiftList" :key="shift.id" :value="shift.id">{{ shiftLabel(shift.id) }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('attendance.rosters.from')" :error="errors.from?.[0]">
                <input :id="id" v-model="form.from" type="date" class="field-input" />
            </AppField>
            <AppButton type="submit" variant="primary" :icon="CalendarClock" :loading="saving" :disabled="!chosen.length || !form.from">
                {{ t('attendance.rosters.assign', { count: formatNumber(chosen.length) }) }}
            </AppButton>
        </form>

        <section class="card">
            <SkeletonRows v-if="people.loading.value && !people.data.value" :rows="6" />
            <ErrorState v-else-if="people.error.value" compact :error="people.error.value" @retry="people.reload()" />
            <EmptyState v-else-if="!rows.length" :icon="Users" :title="t('attendance.today.empty')" :text="t('attendance.today.empty_text')" compact />
            <template v-else>
                <label v-if="can('attendance.manage')" class="flex items-center gap-3 border-b border-line px-5 py-2.5 text-[12.5px] font-medium text-muted">
                    <input type="checkbox" :checked="allChosen" @change="toggleAll" />
                    {{ t('attendance.rosters.everyone') }}
                </label>
                <ul class="divide-y divide-line">
                    <li v-for="row in rows" :key="row.employee_id">
                        <label class="flex cursor-pointer items-center gap-3 px-5 py-2.5 hover:bg-surface-2">
                            <input v-if="can('attendance.manage')" v-model="chosen" type="checkbox" :value="row.employee_id" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[14px] font-medium">{{ row.employee_name }}</span>
                                <span class="block font-mono text-[12px] text-muted" dir="ltr">{{ row.employee_code }}</span>
                            </span>
                            <span class="text-end text-[12.5px] text-muted">{{ shiftLabel(row.shift_id) }}</span>
                        </label>
                    </li>
                </ul>
            </template>
        </section>
    </div>
</template>
