<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { CalendarOff, ChevronLeft, ChevronRight, Plus, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { currentOrganization } from '@/lib/session';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatNumber } from '@/lib/format';
import { cleanTexts, textIn, textsFor } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { attendanceApi } from '../api';
import { useAttendanceSetup } from '../setup';

/**
 * The institution's holidays of a year, beyond the weekly days off: the
 * whole institution, one campus, or some classes. Attendance can still be
 * taken on them (an extra class); the sheet says it is a holiday.
 */
const organization = currentOrganization();
const api = attendanceApi(organization.id);
const setup = useAttendanceSetup();
const year = ref(new Date().getFullYear());
watch(() => setup.today.value, (today) => { if (today) year.value = Number(today.slice(0, 4)); }, { immediate: true });
const list = useResource(() => api.holidays(`${year.value}-01-01`, `${year.value}-12-31`), { immediate: false });
watch(year, () => list.reload(), { immediate: true });
const holidays = computed(() => list.data.value?.data ?? []);
const atInstitution = computed(() => setup.institution.value?.id === organization.id);
const weekend = computed(() => (setup.rules.value.weekend_days ?? []).map((day) => t(`student_attendance.weekdays.${day}`)).join(', '));

const open = ref(false);
const form = reactive({ starts_on: '', ends_on: '', unit_id: '', some_classes: false, level_ids: [] });
const names = ref(textsFor({}));
const errors = ref({});
const saving = ref(false);

function start() {
    Object.assign(form, { starts_on: setup.today.value, ends_on: '', unit_id: '', some_classes: false, level_ids: [] });
    names.value = textsFor({});
    errors.value = {};
    open.value = true;
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        await api.addHoliday({
            starts_on: form.starts_on, ends_on: form.ends_on || null, name: cleanTexts(names.value),
            unit_id: atInstitution.value ? form.unit_id || null : null,
            level_ids: form.some_classes ? form.level_ids : [],
        });
        toast.success(t('student_attendance.holidays.saved'));
        open.value = false;
        list.reload();
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

async function remove(holiday) {
    const sure = await confirmAction({ title: t('student_attendance.holidays.remove_title', { name: textIn(holiday.name) }), message: t('student_attendance.holidays.remove_text'), confirmLabel: t('student_attendance.holidays.remove'), danger: true });
    if (!sure) return;
    try {
        await api.removeHoliday(holiday.id);
        toast.success(t('student_attendance.holidays.removed'));
        list.reload();
    } catch (error) {
        toast.error(error.message);
    }
}

const days = (holiday) => holiday.starts_on === holiday.ends_on
    ? formatDate(holiday.starts_on, { dateStyle: 'medium' })
    : `${formatDate(holiday.starts_on, { dateStyle: 'medium' })} – ${formatDate(holiday.ends_on, { dateStyle: 'medium' })}`;
const levelNames = (ids) => ids.map((id) => textIn(setup.levels.value.find((level) => level.id === id)?.name ?? {})).filter(Boolean).join(', ');
</script>

<template>
    <div>
        <PageHeader :title="t('student_attendance.holidays.title')" :description="t('student_attendance.holidays.text')">
            <template #actions>
                <div class="flex items-center gap-1.5" role="group" :aria-label="t('student_attendance.holidays.year')">
                    <AppButton size="icon" variant="ghost" :icon="ChevronLeft" :aria-label="t('student_attendance.holidays.previous_year')" @click="year--" />
                    <span class="tabular min-w-14 text-center font-semibold text-fg">{{ formatNumber(year, { useGrouping: false }) }}</span>
                    <AppButton size="icon" variant="ghost" :icon="ChevronRight" :aria-label="t('student_attendance.holidays.next_year')" @click="year++" />
                </div>
                <AppButton v-if="setup.can('manage')" variant="primary" :icon="Plus" @click="start">{{ t('student_attendance.holidays.add') }}</AppButton>
            </template>
        </PageHeader>

        <p v-if="weekend" class="mb-4 rounded-xl bg-subtle px-4 py-3 text-[13px] text-fg-2" role="note">{{ t('student_attendance.holidays.weekend', { days: weekend }) }}</p>

        <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
        <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
        <section v-else-if="!holidays.length" class="card">
            <EmptyState :icon="CalendarOff" :title="t('student_attendance.holidays.empty_title')" :text="t('student_attendance.holidays.empty_text')">
                <AppButton v-if="setup.can('manage')" variant="primary" :icon="Plus" @click="start">{{ t('student_attendance.holidays.add') }}</AppButton>
            </EmptyState>
        </section>
        <ul v-else class="card divide-y divide-line">
            <li v-for="holiday in holidays" :key="holiday.id" class="flex items-start gap-3 px-4 py-3">
                <CalendarOff class="mt-0.5 size-4.5 shrink-0 text-muted" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <p class="font-medium text-fg">{{ textIn(holiday.name) }}</p>
                    <p class="text-[12.5px] text-muted">{{ days(holiday) }}</p>
                    <p class="mt-1 flex flex-wrap gap-1.5">
                        <AppBadge tone="neutral">{{ holiday.unit_id ? setup.campusName(holiday.unit_id) : t('student_attendance.holidays.whole') }}</AppBadge>
                        <AppBadge v-if="holiday.level_ids" tone="brand">{{ levelNames(holiday.level_ids) }}</AppBadge>
                    </p>
                </div>
                <AppButton v-if="setup.can('manage')" size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('student_attendance.holidays.remove_title', { name: textIn(holiday.name) })" @click="remove(holiday)" />
            </li>
        </ul>

        <AppDialog :open="open" :title="t('student_attendance.holidays.add')" @close="open = false">
            <form class="space-y-4" @submit.prevent="save">
                <div class="grid grid-cols-2 gap-3">
                    <AppField :label="t('student_attendance.holidays.starts_on')" :error="errors.starts_on?.[0]">
                        <template #default="{ id }"><input :id="id" v-model="form.starts_on" type="date" class="field-input" required /></template>
                    </AppField>
                    <AppField :label="t('student_attendance.holidays.ends_on')" :hint="t('student_attendance.holidays.ends_on_hint')" :error="errors.ends_on?.[0]" optional>
                        <template #default="{ id, describedby }"><input :id="id" v-model="form.ends_on" type="date" :min="form.starts_on" class="field-input" :aria-describedby="describedby" /></template>
                    </AppField>
                </div>
                <TranslatedFields v-model="names" :label="t('student_attendance.holidays.name')" required :maxlength="80" :errors="errors" error-prefix="name" />
                <AppField v-if="atInstitution && setup.campuses.value.length > 1" :label="t('student_attendance.holidays.where')" :error="errors.unit_id?.[0]">
                    <template #default="{ id }">
                        <select :id="id" v-model="form.unit_id" class="field-input">
                            <option value="">{{ t('student_attendance.holidays.whole') }}</option>
                            <option v-for="campus in setup.campuses.value.filter((item) => item.type !== 'company')" :key="campus.id" :value="campus.id">{{ campus.name }}</option>
                        </select>
                    </template>
                </AppField>
                <fieldset class="space-y-2">
                    <legend class="text-[13px] font-medium text-fg">{{ t('student_attendance.holidays.classes') }}</legend>
                    <label class="flex items-center gap-2 text-[13.5px]"><input v-model="form.some_classes" type="radio" :value="false" />{{ t('student_attendance.holidays.every_class') }}</label>
                    <label class="flex items-center gap-2 text-[13.5px]"><input v-model="form.some_classes" type="radio" :value="true" />{{ t('student_attendance.holidays.some_classes') }}</label>
                    <div v-if="form.some_classes" class="grid max-h-48 grid-cols-2 gap-1.5 overflow-y-auto rounded-lg bg-subtle p-2">
                        <label v-for="level in setup.levels.value" :key="level.id" class="flex items-center gap-2 text-[13px]">
                            <input v-model="form.level_ids" type="checkbox" :value="level.id" />{{ textIn(level.name) }}
                        </label>
                    </div>
                    <p v-if="errors.level_ids" class="text-[12.5px] text-bad">{{ errors.level_ids[0] }}</p>
                </fieldset>
            </form>
            <template #footer>
                <AppButton @click="open = false">{{ t('core.actions.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="saving" @click="save">{{ t('student_attendance.holidays.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
