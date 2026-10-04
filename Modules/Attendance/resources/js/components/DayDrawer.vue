<script setup>
import { reactive, ref, watch } from 'vue';
import { Ban, PenLine, Plus } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDrawer from '@/components/AppDrawer.vue';
import AppField from '@/components/AppField.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { confirmAction } from '@/lib/dialogs';
import { formatDate } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { dayTone, durationParts } from '../lib';

/**
 * One employee's day in a side panel: what the server worked out, every
 * punch (voided ones struck through), and for people who manage attendance
 * writing a punch from a register, voiding a wrong one, or asking a
 * correction on the person's behalf.
 */
const props = defineProps({
    day: { type: Object, default: null },
    attendance: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'changed', 'correct']);

const punches = ref([]);
const loading = ref(false);
const loadError = ref(null);
const writing = reactive({ time: '', note: '' });
const errors = ref({});
const saving = ref(false);

watch(() => props.day, (day) => {
    if (!day) return;
    Object.assign(writing, { time: '', note: '' });
    errors.value = {};
    load();
});

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        punches.value = (await props.attendance.punches({ employee_id: props.day.employee_id, from: props.day.work_date, to: props.day.work_date })).data;
    } catch (error) {
        loadError.value = error;
    } finally {
        loading.value = false;
    }
}

async function write() {
    saving.value = true;
    errors.value = {};
    try {
        await props.attendance.writePunch({ employee_id: props.day.employee_id, at: `${props.day.work_date}T${writing.time}`, note: writing.note.trim() });
        toast.success(t('attendance.drawer.written'));
        Object.assign(writing, { time: '', note: '' });
        await load();
        emit('changed');
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

async function voidPunch(item) {
    const confirmed = await confirmAction({
        title: t('attendance.drawer.void_title', { time: formatDate(item.punched_at, { timeStyle: 'short' }) }),
        message: t('attendance.drawer.void_text'),
        confirmLabel: t('attendance.drawer.void'),
        reason: 'required',
        danger: true,
    });
    if (!confirmed) return;
    try {
        await props.attendance.voidPunch(item.id, confirmed.reason);
        toast.success(t('attendance.drawer.voided'));
        await load();
        emit('changed');
    } catch (error) {
        toast.error(error.message);
    }
}

const time = (iso) => (iso ? formatDate(iso, { timeStyle: 'short' }) : '—');
const duration = (minutes) => t('attendance.duration', durationParts(minutes));
</script>

<template>
    <AppDrawer :open="day !== null" :title="day ? `${day.employee_name ?? ''} · ${formatDate(`${day.work_date}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' })}` : ''" @close="emit('close')">
        <div v-if="day" class="grid gap-5 p-5">
            <div class="flex flex-wrap items-center gap-3">
                <AppBadge :tone="dayTone(day.status)" dot>{{ t(`attendance.status.${day.status}`) }}</AppBadge>
                <AppButton v-if="canManage" size="sm" variant="secondary" :icon="PenLine" @click="emit('correct', day)">{{ t('attendance.drawer.ask') }}</AppButton>
            </div>
            <dl class="grid grid-cols-2 gap-3 text-[13px] sm:grid-cols-3">
                <div><dt class="text-muted">{{ t('attendance.day.in') }}</dt><dd class="tabular font-semibold">{{ time(day.first_in_at) }}</dd></div>
                <div><dt class="text-muted">{{ t('attendance.day.out') }}</dt><dd class="tabular font-semibold">{{ time(day.last_out_at) }}</dd></div>
                <div><dt class="text-muted">{{ t('attendance.day.worked') }}</dt><dd class="tabular font-semibold">{{ duration(day.worked_minutes) }}</dd></div>
                <div><dt class="text-muted">{{ t('attendance.day.late') }}</dt><dd class="tabular">{{ duration(day.late_minutes) }}</dd></div>
                <div><dt class="text-muted">{{ t('attendance.day.overtime') }}</dt><dd class="tabular">{{ duration(day.overtime_minutes) }}</dd></div>
            </dl>

            <section>
                <h3 class="mb-2 text-[13.5px] font-semibold">{{ t('attendance.drawer.punches') }}</h3>
                <SkeletonRows v-if="loading" :rows="2" />
                <ErrorState v-else-if="loadError" compact :error="loadError" @retry="load" />
                <p v-else-if="!punches.length" class="text-[13px] text-muted">{{ t('attendance.drawer.no_punches') }}</p>
                <ul v-else class="divide-y divide-line rounded-xl border border-line text-[13px]">
                    <li v-for="item in punches" :key="item.id" class="flex items-center gap-3 px-3 py-2">
                        <span class="tabular w-16 shrink-0 font-medium" :class="item.voided ? 'text-muted line-through' : ''">{{ time(item.punched_at) }}</span>
                        <span class="min-w-0 flex-1 text-muted">
                            {{ t(`attendance.source.${item.source}`) }}<template v-if="item.note"> · {{ item.note }}</template><template v-if="item.distance_m !== null && item.distance_m !== undefined"> · {{ t('attendance.drawer.distance', { metres: item.distance_m }) }}</template>
                            <span v-if="item.voided" class="block text-[12px]">{{ t('attendance.drawer.voided_because', { reason: item.void_reason }) }}</span>
                        </span>
                        <AppButton v-if="canManage && !item.voided" size="icon-sm" variant="ghost" :icon="Ban" :aria-label="t('attendance.drawer.void')" @click="voidPunch(item)" />
                    </li>
                </ul>
            </section>

            <form v-if="canManage" class="grid gap-3 rounded-xl border border-line p-4 sm:grid-cols-[8rem_1fr_auto] sm:items-end" novalidate @submit.prevent="write">
                <AppField v-slot="{ id }" :label="t('attendance.drawer.time')" :error="errors.at?.[0]">
                    <input :id="id" v-model="writing.time" type="time" class="field-input" required />
                </AppField>
                <AppField v-slot="{ id }" :label="t('attendance.drawer.note')" :error="errors.note?.[0]">
                    <input :id="id" v-model="writing.note" class="field-input" maxlength="300" :placeholder="t('attendance.drawer.note_hint')" />
                </AppField>
                <AppButton type="submit" variant="primary" :icon="Plus" :loading="saving" :disabled="!writing.time || writing.note.trim().length < 3">{{ t('attendance.drawer.write') }}</AppButton>
            </form>
        </div>
    </AppDrawer>
</template>
