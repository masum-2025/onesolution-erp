<script setup>
import { computed, reactive, ref } from 'vue';
import { ArrowLeft, Clock, Moon, PenLine, Plus } from 'lucide-vue-next';
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
import { useResource } from '@/lib/useResource';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { attendanceApi } from '../api';
import { durationParts, minutesToTime, shiftHours, timeToMinutes } from '../lib';

/**
 * The company's shifts: hours (an end at or before the start is the next
 * morning), unpaid break, and the minutes expected. Switched off, never
 * removed, once used.
 */
const org = currentOrganization();
const attendance = attendanceApi(org.id);
const list = useResource(() => attendance.shifts());
const shifts = computed(() => list.data.value?.data ?? []);

const editing = ref(null);
const saving = ref(false);
const errors = ref({});
const form = reactive({ code: '', name: { en: '', bn: '' }, start: '09:00', end: '17:00', break_minutes: 60, is_active: true });

function edit(shift = null) {
    Object.assign(form, {
        code: shift?.code ?? '',
        name: { en: shift?.names.en ?? '', bn: shift?.names.bn ?? '' },
        start: shift ? minutesToTime(shift.start_minute) : '09:00',
        end: shift ? minutesToTime(shift.end_minute) : '17:00',
        break_minutes: shift?.break_minutes ?? 60,
        is_active: shift?.is_active ?? true,
    });
    errors.value = {};
    editing.value = shift ?? 'new';
}

const nightPreview = computed(() => {
    const start = timeToMinutes(form.start);
    const end = timeToMinutes(form.end);
    return start !== null && end !== null && end <= start;
});

async function save() {
    const body = {
        code: form.code.trim(),
        name: Object.fromEntries(Object.entries(form.name).filter(([, value]) => value?.trim())),
        start_minute: timeToMinutes(form.start),
        end_minute: timeToMinutes(form.end),
        break_minutes: Number(form.break_minutes) || 0,
    };
    saving.value = true;
    errors.value = {};
    try {
        if (editing.value === 'new') await attendance.createShift(body);
        else await attendance.updateShift(editing.value.id, { ...body, is_active: form.is_active, base_version: editing.value.version });
        toast.success(t('attendance.shifts.saved'));
        editing.value = null;
        list.reload();
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(error.message);
            editing.value = null;
            list.reload();
            return;
        }
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;
const duration = (minutes) => t('attendance.duration', durationParts(minutes));
</script>

<template>
    <div>
        <PageHeader :title="t('attendance.shifts.title')" :description="t('attendance.shifts.text')">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: 'attendance' }" :icon="ArrowLeft">{{ t('attendance.common.back') }}</AppButton>
                <AppButton v-if="can('attendance.manage')" variant="primary" :icon="Plus" @click="edit()">{{ t('attendance.shifts.add') }}</AppButton>
            </template>
        </PageHeader>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!shifts.length" :icon="Clock" :title="t('attendance.shifts.empty')" :text="t('attendance.shifts.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="shift in shifts" :key="shift.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl" :class="shift.overnight ? 'bg-subtle text-fg' : 'bg-brand-soft text-brand-text'">
                        <component :is="shift.overnight ? Moon : Clock" class="size-5" aria-hidden="true" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-[14px] font-medium">{{ shift.name }} <span class="font-mono text-[12px] text-muted" dir="ltr">{{ shift.code }}</span></span>
                        <span class="block text-[12.5px] text-muted">
                            <span class="tabular" dir="ltr">{{ shiftHours(shift).from }} – {{ shiftHours(shift).to }}</span>
                            <template v-if="shift.overnight"> · {{ t('attendance.shifts.next_day') }}</template>
                            · {{ t('attendance.shifts.break_of', { time: duration(shift.break_minutes) }) }} · {{ t('attendance.shifts.expected', { time: duration(shift.expected_minutes) }) }}
                        </span>
                    </span>
                    <AppBadge v-if="!shift.is_active" tone="neutral">{{ t('attendance.shifts.off') }}</AppBadge>
                    <AppButton v-if="can('attendance.manage')" size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="t('attendance.shifts.edit')" @click="edit(shift)" />
                </li>
            </ul>
        </section>

        <AppDialog :open="editing !== null" :title="editing === 'new' ? t('attendance.shifts.add') : t('attendance.shifts.edit')" @close="editing = null">
            <form id="attendance-shift" class="grid gap-4 sm:grid-cols-3" novalidate @submit.prevent="save">
                <AppField v-slot="{ id }" :label="t('attendance.shifts.code')" :error="fieldError('code')">
                    <input :id="id" v-model="form.code" class="field-input font-mono" dir="ltr" maxlength="20" />
                </AppField>
                <div class="sm:col-span-2">
                    <TranslatedFields v-model="form.name" :label="t('attendance.shifts.name')" :errors="errors" required :maxlength="80" />
                </div>
                <AppField v-slot="{ id }" :label="t('attendance.shifts.start')" :error="fieldError('start_minute')">
                    <input :id="id" v-model="form.start" type="time" class="field-input" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('attendance.shifts.end')" :hint="nightPreview ? t('attendance.shifts.next_day') : ''" :error="fieldError('end_minute')">
                    <input :id="id" v-model="form.end" type="time" class="field-input" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('attendance.shifts.break')" :error="fieldError('break_minutes')">
                    <input :id="id" v-model.number="form.break_minutes" type="number" min="0" max="600" step="5" class="field-input tabular text-end" />
                </AppField>
                <div v-if="editing !== 'new'" class="sm:col-span-3">
                    <AppSwitch v-model="form.is_active" :label="t('attendance.shifts.active')" show-label />
                </div>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('attendance.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="attendance-shift" :loading="saving" :disabled="!form.code.trim() || !form.name.en?.trim() || timeToMinutes(form.start) === null || timeToMinutes(form.end) === null">
                    {{ t('attendance.common.save') }}
                </AppButton>
            </template>
        </AppDialog>
    </div>
</template>
