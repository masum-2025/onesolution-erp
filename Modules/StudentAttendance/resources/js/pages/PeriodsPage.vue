<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Clock, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { currentOrganization } from '@/lib/session';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatNumber } from '@/lib/format';
import { cleanTexts, textIn, textsFor } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { attendanceApi } from '../api';
import { useAttendanceSetup } from '../setup';

/**
 * A campus's periods of the day (1st, 2nd…) with their times, used when
 * attendance is taken in each period. One attendance was taken in is
 * switched off rather than removed. Whether periods are used at all is the
 * rule "Attendance in each period"; the page says so when it is off.
 */
const api = attendanceApi(currentOrganization().id);
const setup = useAttendanceSetup();
const list = useResource(() => api.periods());
const campusId = ref('');
watch(() => setup.campuses.value, (campuses) => {
    if (campuses.length && !campuses.some((campus) => campus.id === campusId.value)) campusId.value = (campuses.find((campus) => campus.type !== 'company') ?? campuses[0]).id;
}, { immediate: true });
const periods = computed(() => (list.data.value?.data ?? []).filter((period) => period.unit_id === campusId.value));

const editing = ref(null);
const open = ref(false);
const form = reactive({ number: '', starts_at: '', ends_at: '', is_active: true });
const names = ref(textsFor({}));
const errors = ref({});
const saving = ref(false);

function start(period = null) {
    editing.value = period;
    Object.assign(form, period
        ? { number: String(period.number), starts_at: period.starts_at ?? '', ends_at: period.ends_at ?? '', is_active: period.is_active }
        : { number: String((periods.value.at(-1)?.number ?? 0) + 1), starts_at: periods.value.at(-1)?.ends_at ?? '', ends_at: '', is_active: true });
    names.value = textsFor(period?.name ?? {});
    errors.value = {};
    open.value = true;
}

async function save() {
    saving.value = true;
    errors.value = {};
    const body = { number: Number(form.number), name: cleanTexts(names.value), starts_at: form.starts_at || null, ends_at: form.ends_at || null, is_active: form.is_active };
    try {
        if (editing.value) await api.changePeriod(editing.value.id, { ...body, base_version: editing.value.version });
        else await api.addPeriod({ ...body, unit_id: campusId.value });
        toast.success(t('student_attendance.periods.saved'));
        open.value = false;
        await Promise.all([list.reload(), setup.reload()]);
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('student_attendance.periods.conflict'));
            open.value = false;
            list.reload();
            return;
        }
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

async function remove(period) {
    const sure = await confirmAction({ title: t('student_attendance.periods.remove_title', { name: textIn(period.name) }), message: t('student_attendance.periods.remove_text'), confirmLabel: t('student_attendance.periods.remove'), danger: true });
    if (!sure) return;
    try {
        await api.removePeriod(period.id, period.version);
        toast.success(t('student_attendance.periods.removed'));
        await Promise.all([list.reload(), setup.reload()]);
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('student_attendance.periods.title')" :description="t('student_attendance.periods.text')">
            <template #actions>
                <AppButton v-if="setup.can('manage')" variant="primary" :icon="Plus" :disabled="!campusId" @click="start()">{{ t('student_attendance.periods.add') }}</AppButton>
            </template>
        </PageHeader>

        <p v-if="setup.data.value && !setup.rules.value.by_period" class="mb-4 rounded-xl bg-subtle px-4 py-3 text-[13px] text-fg-2" role="note">{{ t('student_attendance.periods.rule_off') }}</p>

        <AppField v-if="setup.campuses.value.length > 1" :label="t('student_attendance.periods.campus')" class="mb-4 w-full sm:w-72">
            <template #default="{ id }">
                <select :id="id" v-model="campusId" class="field-input">
                    <option v-for="campus in setup.campuses.value" :key="campus.id" :value="campus.id">{{ campus.name }}</option>
                </select>
            </template>
        </AppField>

        <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
        <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
        <section v-else-if="!periods.length" class="card">
            <EmptyState :icon="Clock" :title="t('student_attendance.periods.empty_title')" :text="t('student_attendance.periods.empty_text')">
                <AppButton v-if="setup.can('manage')" variant="primary" :icon="Plus" @click="start()">{{ t('student_attendance.periods.add') }}</AppButton>
            </EmptyState>
        </section>
        <ul v-else class="card divide-y divide-line">
            <li v-for="period in periods" :key="period.id" class="flex items-center gap-3 px-4 py-3">
                <span class="tabular grid size-9 shrink-0 place-items-center rounded-full bg-brand-soft text-[13px] font-semibold text-brand-text">{{ formatNumber(period.number) }}</span>
                <div class="min-w-0 flex-1">
                    <p class="truncate font-medium text-fg">{{ textIn(period.name) }}</p>
                    <p class="tabular text-[12.5px] text-muted">{{ period.starts_at ? `${period.starts_at} – ${period.ends_at ?? '…'}` : t('student_attendance.periods.no_time') }}</p>
                </div>
                <AppBadge v-if="!period.is_active" tone="neutral">{{ t('student_attendance.periods.off') }}</AppBadge>
                <template v-if="setup.can('manage')">
                    <AppButton size="icon-sm" variant="ghost" :icon="Pencil" :aria-label="t('student_attendance.periods.edit', { name: textIn(period.name) })" @click="start(period)" />
                    <AppButton size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('student_attendance.periods.remove_title', { name: textIn(period.name) })" @click="remove(period)" />
                </template>
            </li>
        </ul>

        <AppDialog :open="open" :title="editing ? t('student_attendance.periods.edit', { name: textIn(editing.name) }) : t('student_attendance.periods.add')" @close="open = false">
            <form class="space-y-4" @submit.prevent="save">
                <AppField :label="t('student_attendance.periods.number')" :hint="t('student_attendance.periods.number_hint')" :error="errors.number?.[0]">
                    <template #default="{ id, describedby }">
                        <input :id="id" v-model="form.number" type="number" min="1" max="20" inputmode="numeric" class="field-input w-28" :aria-describedby="describedby" required />
                    </template>
                </AppField>
                <TranslatedFields v-model="names" :label="t('student_attendance.periods.name')" required :maxlength="40" :errors="errors" error-prefix="name" />
                <div class="grid grid-cols-2 gap-3">
                    <AppField :label="t('student_attendance.periods.starts_at')" :error="errors.starts_at?.[0]" optional>
                        <template #default="{ id }"><input :id="id" v-model="form.starts_at" type="time" class="field-input" /></template>
                    </AppField>
                    <AppField :label="t('student_attendance.periods.ends_at')" :error="errors.ends_at?.[0]" optional>
                        <template #default="{ id }"><input :id="id" v-model="form.ends_at" type="time" class="field-input" /></template>
                    </AppField>
                </div>
                <AppSwitch v-if="editing" v-model="form.is_active" :label="t('student_attendance.periods.active')" show-label />
            </form>
            <template #footer>
                <AppButton @click="open = false">{{ t('core.actions.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="saving" @click="save">{{ t('core.actions.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
